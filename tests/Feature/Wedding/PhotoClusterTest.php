<?php

namespace Tests\Feature\Wedding;

use App\Models\WeddingUpload;
use App\Services\Wedding\PhotoClusterService;
use App\Support\PerceptualHash;
use Illuminate\Support\Facades\Storage;

/**
 * Near-identical photos (resized, recompressed, rotated or mirrored copies)
 * collapse to one gallery tile showing the best (highest-resolution) copy.
 */
class PhotoClusterTest extends WeddingTestCase
{
    /**
     * Eight distinct random orientation hashes.
     *
     * @return list<string>
     */
    private function hashes(): array
    {
        return array_map(fn () => base64_encode(random_bytes(32)), range(0, 7));
    }

    /**
     * A copy of a photo's hash set with `bits` bits flipped in every
     * orientation (resampling noise).
     *
     * @param  list<string>  $hashes
     * @return list<string>
     */
    private function nearCopy(array $hashes, int $bits = 3): array
    {
        return array_map(function (string $hash) use ($bits): string {
            $bytes = base64_decode($hash);
            for ($i = 0; $i < $bits; $i++) {
                $bytes[$i] = chr(ord($bytes[$i]) ^ 0x01);
            }

            return base64_encode($bytes);
        }, $hashes);
    }

    /**
     * Upload and complete a photo as the current guest; returns its ulid.
     *
     * @param  list<string>  $hashes
     */
    private function uploadPhoto(array $hashes, int $width, int $height, int $size = 2048): string
    {
        $ulid = $this->postJson('/wedding/api/uploads', [
            'filename' => 'IMG.JPG',
            'content_type' => 'image/jpeg',
            'size' => $size,
            'file_hash' => $this->hash(uniqid('photo', true)),
            'perceptual_hashes' => $hashes,
            'width' => $width,
            'height' => $height,
        ])->assertCreated()->json('ulid');
        Storage::disk('r2')->put(WeddingUpload::query()->where('ulid', $ulid)->sole()->object_key, str_repeat('x', $size));
        $this->postJson("/wedding/api/uploads/{$ulid}/complete")->assertOk();

        return $ulid;
    }

    /**
     * @return list<string>
     */
    private function galleryUlids(): array
    {
        return array_column($this->getJson('/wedding/api/gallery')->assertOk()->json('items'), 'ulid');
    }

    public function test_a_higher_resolution_copy_takes_over_the_tile(): void
    {
        $this->enterAs();
        $hashes = $this->hashes();
        $small = $this->uploadPhoto($hashes, 1080, 810);
        $full = $this->uploadPhoto($this->nearCopy($hashes), 4032, 3024);

        $this->assertSame([$full], $this->galleryUlids());
        $this->getJson('/wedding/api/gallery')->assertJsonPath('items.0.similar_count', 1)->assertJsonPath('items.0.width', 4032);
        $this->assertSame([$small], array_column($this->getJson("/wedding/api/gallery/{$full}/similar")->assertOk()->json('items'), 'ulid'));
    }

    public function test_a_later_smaller_copy_joins_under_the_existing_best(): void
    {
        $this->enterAs();
        $hashes = $this->hashes();
        $full = $this->uploadPhoto($hashes, 4032, 3024);
        $this->uploadPhoto($this->nearCopy($hashes), 1080, 810);

        $this->assertSame([$full], $this->galleryUlids());
    }

    public function test_rotated_and_mirrored_copies_match_at_their_orientation(): void
    {
        $this->enterAs();
        $hashes = $this->hashes();
        $this->uploadPhoto($hashes, 3024, 4032);
        // A copy rotated 90°: as displayed it looks like the original's
        // orientation 3, and its own hashes are otherwise unrelated bytes.
        $rotated = $this->hashes();
        $rotated[0] = $this->nearCopy([$hashes[3]])[0];
        $landscape = $this->uploadPhoto($rotated, 4032, 3024, 4096);

        // Same pixel count, so the larger file wins.
        $this->assertSame([$landscape], $this->galleryUlids());
    }

    public function test_unrelated_photos_stay_separate(): void
    {
        $this->enterAs();
        $a = $this->uploadPhoto($this->hashes(), 4032, 3024);
        $b = $this->uploadPhoto($this->hashes(), 4032, 3024);

        $this->assertSame([$b, $a], $this->galleryUlids());
    }

    public function test_a_photo_bridging_two_clusters_merges_them_under_the_best(): void
    {
        $this->enterAs();
        $left = $this->hashes();
        $right = $this->nearCopy($left, 16); // too far apart to match directly
        $a = $this->uploadPhoto($left, 1080, 810);
        $b = $this->uploadPhoto($right, 2048, 1536);
        $this->assertSame([$b, $a], $this->galleryUlids());

        $bridge = $this->uploadPhoto($this->nearCopy($left, 8), 4032, 3024); // 8 bits from each

        $this->assertSame([$bridge], $this->galleryUlids());
        $this->getJson('/wedding/api/gallery')->assertJsonPath('items.0.similar_count', 2);
    }

    public function test_removing_the_best_copy_shows_the_next_best(): void
    {
        $this->enterAs();
        $hashes = $this->hashes();
        $this->uploadPhoto($hashes, 1080, 810);
        $middle = $this->uploadPhoto($this->nearCopy($hashes, 1), 2048, 1536);
        $best = $this->uploadPhoto($this->nearCopy($hashes, 2), 4032, 3024);

        $this->deleteJson("/wedding/api/uploads/{$best}")->assertOk();

        $this->assertSame([$middle], $this->galleryUlids());
        $this->getJson('/wedding/api/gallery')->assertJsonPath('items.0.similar_count', 1);
    }

    public function test_removing_a_collapsed_copy_keeps_the_best(): void
    {
        $this->enterAs();
        $hashes = $this->hashes();
        $small = $this->uploadPhoto($hashes, 1080, 810);
        $best = $this->uploadPhoto($this->nearCopy($hashes), 4032, 3024);

        $this->deleteJson("/wedding/api/uploads/{$small}")->assertOk();

        $this->assertSame([$best], $this->galleryUlids());
        $this->getJson('/wedding/api/gallery')->assertJsonPath('items.0.similar_count', 0);
    }

    public function test_a_retried_completion_places_a_photo_left_unclustered(): void
    {
        $this->enterAs();
        $hashes = $this->hashes();
        $best = $this->uploadPhoto($hashes, 4032, 3024);
        $small = $this->uploadPhoto($this->nearCopy($hashes), 1080, 810);
        // As if the first completion died after marking it ready.
        WeddingUpload::query()->where('ulid', $small)->update(['duplicate_of_id' => null]);
        $this->assertCount(2, $this->galleryUlids());

        $this->postJson("/wedding/api/uploads/{$small}/complete")->assertOk();

        $this->assertSame([$best], $this->galleryUlids());
    }

    public function test_similar_is_only_listed_for_a_shown_photo(): void
    {
        $this->enterAs();
        $hashes = $this->hashes();
        $small = $this->uploadPhoto($hashes, 1080, 810);
        $this->uploadPhoto($this->nearCopy($hashes), 4032, 3024);

        $this->getJson("/wedding/api/gallery/{$small}/similar")->assertNotFound();
    }

    public function test_photos_without_hashes_are_never_clustered(): void
    {
        $this->enterAs();
        $hashed = $this->uploadPhoto($this->hashes(), 4032, 3024);
        $ulid = $this->postJson('/wedding/api/uploads', [
            'filename' => 'IMG.HEIC',
            'content_type' => 'image/heic',
            'size' => 10,
            'file_hash' => $this->hash('heic'),
        ])->assertCreated()->json('ulid');
        Storage::disk('r2')->put(WeddingUpload::query()->where('ulid', $ulid)->sole()->object_key, str_repeat('x', 10));
        $this->postJson("/wedding/api/uploads/{$ulid}/complete")->assertOk();

        $this->assertSame([$ulid, $hashed], $this->galleryUlids());
    }

    public function test_releasing_a_copy_follows_a_representative_demoted_since(): void
    {
        // A copy was hidden, then (before its release ran) a better upload
        // re-elected the cluster without it: it still points at the old best.
        $newBest = $this->makeUpload(['width' => 4032, 'height' => 3024]);
        $oldBest = $this->makeUpload(['width' => 2048, 'height' => 1536, 'duplicate_of_id' => $newBest->id]);
        $hidden = $this->makeUpload([
            'width' => 1080,
            'height' => 810,
            'status' => WeddingUpload::STATUS_HIDDEN,
            'duplicate_of_id' => $oldBest->id,
        ]);

        app(PhotoClusterService::class)->release($hidden);

        $this->assertNull($newBest->refresh()->duplicate_of_id);
        $this->assertSame($newBest->id, $oldBest->refresh()->duplicate_of_id);
        $this->assertNull($hidden->refresh()->duplicate_of_id);
    }

    public function test_rebuild_recomputes_clusters_from_stored_hashes(): void
    {
        $hashes = $this->hashes();
        $small = $this->makeUpload(['perceptual_hashes' => $hashes, 'width' => 1080, 'height' => 810]);
        $best = $this->makeUpload(['perceptual_hashes' => $this->nearCopy($hashes), 'width' => 4032, 'height' => 3024]);

        $this->artisan('wedding:recluster-photos')->expectsOutputToContain('1 photo(s)')->assertSuccessful();

        $this->assertSame($best->id, $small->refresh()->duplicate_of_id);
        $this->assertNull($best->refresh()->duplicate_of_id);
    }

    public function test_store_validates_hashes_and_dimensions(): void
    {
        $this->enterAs();
        $base = ['filename' => 'IMG.JPG', 'content_type' => 'image/jpeg', 'size' => 10];

        $this->postJson('/wedding/api/uploads', $base + ['perceptual_hashes' => array_slice($this->hashes(), 0, 7)])
            ->assertUnprocessable()->assertJsonValidationErrors('perceptual_hashes');
        $this->postJson('/wedding/api/uploads', $base + ['perceptual_hashes' => [...array_slice($this->hashes(), 0, 7), 'nope']])
            ->assertUnprocessable()->assertJsonValidationErrors('perceptual_hashes.7');
        $this->postJson('/wedding/api/uploads', $base + ['width' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors('height');
    }

    public function test_oriented_distance_tolerates_a_different_smallest_orientation(): void
    {
        // The old canonical hash kept only the lexicographically smallest
        // orientation; noise that reorders which orientation is smallest made
        // two copies of one photo look unrelated. Comparing at the matching
        // orientation is immune to that.
        $a = [base64_encode("\x10".str_repeat("\x00", 31)), base64_encode("\x0f".str_repeat("\xff", 31))];
        $b = [base64_encode("\x0f".str_repeat("\x00", 31)), base64_encode("\x10".str_repeat("\xff", 31))];

        $this->assertSame(5, PerceptualHash::orientedDistance($a, $b));
        $this->assertGreaterThan(200, PerceptualHash::hammingDistance(min($a), min($b)));
    }
}
