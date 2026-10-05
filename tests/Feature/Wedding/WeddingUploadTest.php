<?php

namespace Tests\Feature\Wedding;

use App\Models\WeddingUpload;
use App\Services\FileStorageService;
use Illuminate\Support\Facades\Storage;

class WeddingUploadTest extends WeddingTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'filename' => 'IMG_0001.JPG',
            'content_type' => 'image/jpeg',
            'size' => 2048,
            'file_hash' => $this->hash('photo-1'),
            'perceptual_hash' => base64_encode(str_repeat("\x00", 32)),
            'display_size' => 300_000,
            'thumbnail_size' => 20_000,
        ], $overrides);
    }

    public function test_store_creates_a_pending_upload_with_presigned_urls(): void
    {
        $token = $this->enterAs();

        $response = $this->postJson('/wedding/api/uploads', $this->payload())->assertCreated();

        $upload = WeddingUpload::query()->sole();
        $this->assertSame(WeddingUpload::STATUS_PENDING, $upload->status);
        $this->assertSame($token, $upload->guest_token_hash);
        $this->assertMatchesRegularExpression('#^photos/[0-9a-z]{26}\.jpg$#', $upload->object_key);
        $this->assertSame('derived/'.$upload->ulid.'/display.jpg', $upload->display_key);
        $this->assertSame('derived/'.$upload->ulid.'/thumb.jpg', $upload->thumbnail_key);
        $response->assertJsonPath('ulid', $upload->ulid)
            ->assertJsonPath('multipart', false)
            ->assertJsonPath('display_upload.headers.Content-Type', 'image/jpeg');
    }

    public function test_every_presigned_put_is_bound_to_its_declared_size(): void
    {
        $this->enterAs();

        $ulid = $this->postJson('/wedding/api/uploads', $this->payload())->assertCreated()->json('ulid');

        /** @var FakeFileStorageService $storage */
        $storage = app(FileStorageService::class);
        $upload = WeddingUpload::query()->where('ulid', $ulid)->sole();
        $this->assertSame([
            $upload->object_key => 2048,
            'derived/'.$ulid.'/display.jpg' => 300_000,
            'derived/'.$ulid.'/thumb.jpg' => 20_000,
        ], $storage->signedLengths);

        $this->postJson('/wedding/api/uploads', $this->payload([
            'file_hash' => $this->hash('photo-2'),
            'thumbnail_size' => config('wedding.max_bytes.thumbnail') + 1,
        ]))->assertUnprocessable()->assertJsonValidationErrors('thumbnail_size');
    }

    public function test_videos_go_under_the_transcoder_prefix_and_never_get_a_display_copy(): void
    {
        $this->enterAs();

        $this->postJson('/wedding/api/uploads', $this->payload([
            'filename' => 'clip.MOV',
            'content_type' => 'video/quicktime',
            'perceptual_hash' => null,
            'display_size' => 300_000,
        ]))->assertCreated();

        $upload = WeddingUpload::query()->sole();
        $this->assertStringStartsWith('videos/', $upload->object_key);
        $this->assertStringEndsWith('.mov', $upload->object_key);
        $this->assertNull($upload->display_key);
        $this->assertSame('derived/'.$upload->ulid.'/thumb.jpg', $upload->thumbnail_key);
    }

    public function test_unsupported_and_oversized_files_are_rejected(): void
    {
        $this->enterAs();

        $this->postJson('/wedding/api/uploads', $this->payload(['content_type' => 'application/pdf']))
            ->assertUnprocessable()->assertJsonValidationErrors('content_type');
        $this->postJson('/wedding/api/uploads', $this->payload(['size' => config('wedding.max_bytes.photo') + 1]))
            ->assertUnprocessable()->assertJsonValidationErrors('size');
        $this->postJson('/wedding/api/uploads', $this->payload(['file_hash' => 'nope']))
            ->assertUnprocessable()->assertJsonValidationErrors('file_hash');

        $this->assertSame(0, WeddingUpload::query()->count());
    }

    public function test_a_file_anyone_already_shared_is_refused(): void
    {
        $this->makeUpload(['file_hash' => $this->hash('photo-1')]);
        $this->enterAs();

        $this->postJson('/wedding/api/uploads', $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('duplicate', true);
    }

    public function test_another_guests_in_flight_upload_holds_the_file_until_it_goes_stale(): void
    {
        $pending = $this->makeUpload(['file_hash' => $this->hash('photo-1'), 'status' => WeddingUpload::STATUS_PENDING]);
        $this->enterAs();

        $this->postJson('/wedding/api/uploads', $this->payload())->assertStatus(409);

        $pending->forceFill(['created_at' => now()->subHours(25)])->save();
        $this->postJson('/wedding/api/uploads', $this->payload())->assertCreated();
    }

    public function test_retrying_your_own_file_supersedes_the_abandoned_attempt(): void
    {
        $this->enterAs();
        $first = $this->postJson('/wedding/api/uploads', $this->payload())->assertCreated()->json('ulid');
        Storage::disk('r2')->put('derived/'.$first.'/thumb.jpg', 'partial');

        $second = $this->postJson('/wedding/api/uploads', $this->payload())->assertCreated()->json('ulid');

        $this->assertNotSame($first, $second);
        $this->assertSame([$second], WeddingUpload::query()->pluck('ulid')->all());
        Storage::disk('r2')->assertMissing('derived/'.$first.'/thumb.jpg');
    }

    public function test_check_reports_only_hashes_already_in_the_gallery(): void
    {
        $this->makeUpload(['file_hash' => $this->hash('shared')]);
        $this->makeUpload(['file_hash' => $this->hash('hidden'), 'status' => WeddingUpload::STATUS_HIDDEN]);
        $this->enterAs();

        $this->postJson('/wedding/api/uploads/check', [
            'hashes' => [$this->hash('shared'), $this->hash('hidden'), $this->hash('new')],
        ])->assertOk()->assertExactJson(['existing' => [$this->hash('shared')]]);
    }

    public function test_complete_verifies_the_object_and_keeps_only_valid_derivatives(): void
    {
        $this->enterAs();
        $ulid = $this->postJson('/wedding/api/uploads', $this->payload())->json('ulid');
        $upload = WeddingUpload::query()->where('ulid', $ulid)->sole();

        Storage::disk('r2')->put($upload->object_key, str_repeat('p', 2048));
        Storage::disk('r2')->put((string) $upload->thumbnail_key, 'thumb');
        Storage::disk('r2')->put((string) $upload->display_key, str_repeat('d', config('wedding.max_bytes.display') + 1));

        $this->postJson("/wedding/api/uploads/{$ulid}/complete")->assertOk()->assertJsonPath('status', 'ready');

        $upload->refresh();
        $this->assertSame(2048, $upload->size_bytes);
        $this->assertNotNull($upload->thumbnail_key);
        $this->assertNull($upload->display_key);
        Storage::disk('r2')->assertMissing('derived/'.$ulid.'/display.jpg');
    }

    public function test_complete_without_the_object_discards_the_row(): void
    {
        $this->enterAs();
        $ulid = $this->postJson('/wedding/api/uploads', $this->payload())->json('ulid');

        $this->postJson("/wedding/api/uploads/{$ulid}/complete")->assertUnprocessable();

        $this->assertSame(0, WeddingUpload::query()->count());
    }

    public function test_an_oversized_object_is_deleted_on_complete(): void
    {
        config(['wedding.max_bytes.photo' => 4096]);
        $this->enterAs();
        $ulid = $this->postJson('/wedding/api/uploads', $this->payload())->json('ulid');
        $key = WeddingUpload::query()->sole()->object_key;
        // Declared 2 KB at presign time, actually PUT 8 KB.
        Storage::disk('r2')->put($key, str_repeat('x', 8192));

        $this->postJson("/wedding/api/uploads/{$ulid}/complete")->assertUnprocessable();

        Storage::disk('r2')->assertMissing($key);
        $this->assertSame(0, WeddingUpload::query()->count());
    }

    public function test_the_later_of_two_racing_identical_uploads_is_discarded(): void
    {
        $this->enterAs();
        $ulid = $this->postJson('/wedding/api/uploads', $this->payload())->json('ulid');
        $key = WeddingUpload::query()->sole()->object_key;
        Storage::disk('r2')->put($key, 'bytes');
        // Another guest's identical file finished first.
        $this->makeUpload(['file_hash' => $this->hash('photo-1')]);

        $this->postJson("/wedding/api/uploads/{$ulid}/complete")->assertUnprocessable();

        $this->assertFalse(WeddingUpload::query()->where('ulid', $ulid)->exists());
        Storage::disk('r2')->assertMissing($key);
    }

    public function test_near_identical_photos_are_flagged_not_blocked(): void
    {
        $original = $this->makeUpload(['perceptual_hash' => base64_encode(str_repeat("\x00", 32))]);
        $this->enterAs();
        // Three differing bits: well inside the near-duplicate distance.
        $ulid = $this->postJson('/wedding/api/uploads', $this->payload([
            'perceptual_hash' => base64_encode("\x07".str_repeat("\x00", 31)),
        ]))->json('ulid');
        Storage::disk('r2')->put(WeddingUpload::query()->where('ulid', $ulid)->sole()->object_key, 'x');

        $this->postJson("/wedding/api/uploads/{$ulid}/complete")->assertOk();

        $this->assertSame($original->id, WeddingUpload::query()->where('ulid', $ulid)->sole()->duplicate_of_id);
    }

    public function test_only_the_session_that_created_an_upload_can_touch_it(): void
    {
        $this->enterAs('same@example.test');
        $ulid = $this->postJson('/wedding/api/uploads', $this->payload())->json('ulid');

        // Same email, different session: the email alone grants nothing.
        $this->switchGuest('same@example.test');

        $this->postJson("/wedding/api/uploads/{$ulid}/complete")->assertNotFound();
        $this->postJson("/wedding/api/uploads/{$ulid}/multipart")->assertNotFound();
        $this->deleteJson("/wedding/api/uploads/{$ulid}")->assertNotFound();
    }

    public function test_large_videos_use_a_bounded_multipart_session(): void
    {
        config(['wedding.multipart.threshold_bytes' => 10, 'wedding.multipart.part_size_bytes' => 5 * 1024 * 1024]);
        $this->enterAs();
        $size = 12 * 1024 * 1024; // three 5 MB parts
        $created = $this->postJson('/wedding/api/uploads', $this->payload([
            'filename' => 'clip.mp4',
            'content_type' => 'video/mp4',
            'size' => $size,
            'perceptual_hash' => null,
        ]))->assertCreated()->assertJsonPath('multipart', true);
        $ulid = $created->json('ulid');

        $init = $this->postJson("/wedding/api/uploads/{$ulid}/multipart")
            ->assertOk()
            ->assertJsonPath('max_part_number', 3);
        $uploadId = $init->json('upload_id');

        $this->postJson("/wedding/api/uploads/{$ulid}/multipart/parts", [
            'upload_id' => $uploadId,
            'part_numbers' => [4],
            'part_sizes' => ['4' => 1024],
        ])->assertNotFound();
        $this->postJson("/wedding/api/uploads/{$ulid}/multipart/parts", [
            'upload_id' => $uploadId,
            'part_numbers' => [1],
            'part_sizes' => ['1' => 6 * 1024 * 1024],
        ])->assertNotFound();
        $this->postJson("/wedding/api/uploads/{$ulid}/multipart/parts", [
            'upload_id' => $uploadId,
            'part_numbers' => [1, 2],
            'part_sizes' => ['1' => 5 * 1024 * 1024, '2' => 5 * 1024 * 1024],
        ])->assertOk()->assertJsonCount(2, 'parts');

        $this->postJson("/wedding/api/uploads/{$ulid}/multipart/complete", [
            'upload_id' => $uploadId,
            'parts' => [['part_number' => 1, 'etag' => '"a"'], ['part_number' => 2, 'etag' => '"b"'], ['part_number' => 3, 'etag' => '"c"']],
        ])->assertOk()->assertJsonPath('status', 'ready');
    }

    public function test_small_photos_cannot_start_multipart(): void
    {
        $this->enterAs();
        $ulid = $this->postJson('/wedding/api/uploads', $this->payload())->json('ulid');

        $this->postJson("/wedding/api/uploads/{$ulid}/multipart")->assertUnprocessable();
    }

    public function test_guests_can_remove_their_own_uploads(): void
    {
        $this->enterAs();
        $ulid = $this->postJson('/wedding/api/uploads', $this->payload())->json('ulid');
        Storage::disk('r2')->put(WeddingUpload::query()->sole()->object_key, 'x');
        $this->postJson("/wedding/api/uploads/{$ulid}/complete")->assertOk();

        $this->deleteJson("/wedding/api/uploads/{$ulid}")->assertOk();

        $this->assertSame(WeddingUpload::STATUS_HIDDEN, WeddingUpload::query()->sole()->status);
        $this->getJson('/wedding/api/gallery')->assertJsonCount(0, 'items');
    }
}
