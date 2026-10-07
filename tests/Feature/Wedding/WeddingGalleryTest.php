<?php

namespace Tests\Feature\Wedding;

use App\Models\WeddingUpload;

class WeddingGalleryTest extends WeddingTestCase
{
    public function test_gallery_lists_ready_uploads_in_order_without_emails(): void
    {
        $older = $this->makeUpload(['thumbnail_key' => 'derived/a/thumb.jpg', 'guest_name' => 'Aunt May']);
        $this->makeUpload(['status' => WeddingUpload::STATUS_PENDING]);
        $this->makeUpload(['status' => WeddingUpload::STATUS_HIDDEN]);
        $newer = $this->makeUpload(['guest_name' => null]);
        $this->enterAs();

        $response = $this->getJson('/wedding/api/gallery')->assertOk();

        $response->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.ulid', $older->ulid)
            ->assertJsonPath('items.1.ulid', $newer->ulid)
            ->assertJsonPath('items.0.guest_name', 'Aunt May')
            ->assertJsonPath('items.0.thumb_url', "/wedding/media/{$older->ulid}/thumb")
            ->assertJsonPath('items.1.thumb_url', null)
            ->assertJsonPath('items.1.mine', false);
        $this->assertStringNotContainsString('@example.test', $response->getContent() ?: '');
        $this->assertStringNotContainsString('token', $response->getContent() ?: '');
    }

    public function test_gallery_paginates_with_a_cursor(): void
    {
        config(['wedding.gallery_page_size' => 2]);
        foreach (range(1, 3) as $_) {
            $this->makeUpload();
        }
        $this->enterAs();

        $first = $this->getJson('/wedding/api/gallery')->assertJsonCount(2, 'items');
        $cursor = $first->json('next_cursor');
        $this->assertIsString($cursor);

        $this->getJson('/wedding/api/gallery?cursor='.urlencode($cursor))
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('next_cursor', null);
    }

    public function test_media_redirects_to_a_presigned_url(): void
    {
        $photo = $this->makeUpload(['display_key' => 'derived/x/display.jpg']);
        $heic = $this->makeUpload(['object_key' => 'photos/y.heic', 'display_key' => null]);
        $this->enterAs();

        $this->get("/wedding/media/{$photo->ulid}/display")
            ->assertRedirect('https://r2.example.test/r2/derived/x/display.jpg?view');
        $this->get("/wedding/media/{$heic->ulid}/display")
            ->assertRedirect('https://r2.example.test/r2/photos/y.heic?view');
        $this->get("/wedding/media/{$photo->ulid}/original")
            ->assertRedirect("https://r2.example.test/r2/{$photo->object_key}?download");
        $this->get("/wedding/media/{$photo->ulid}/thumb")->assertNotFound();
        $this->get("/wedding/media/{$photo->ulid}/secret")->assertNotFound();
    }

    public function test_media_for_unready_uploads_is_not_served(): void
    {
        $pending = $this->makeUpload(['status' => WeddingUpload::STATUS_PENDING]);
        $hidden = $this->makeUpload(['status' => WeddingUpload::STATUS_HIDDEN]);
        $this->enterAs();

        $this->get("/wedding/media/{$pending->ulid}/original")->assertNotFound();
        $this->get("/wedding/media/{$hidden->ulid}/original")->assertNotFound();
    }

    public function test_gallery_runs_in_capture_order_falling_back_to_upload_time(): void
    {
        // Uploaded in this order, the day after the wedding...
        $this->travelTo('2026-09-28 18:00:00');
        $reception = $this->makeUpload(['captured_at' => '2026-09-28 03:30:00']);
        $unknown = $this->makeUpload(['captured_at' => null]);
        $ceremony = $this->makeUpload(['captured_at' => '2026-09-27 23:10:00']);
        $this->enterAs();

        $items = $this->getJson('/wedding/api/gallery')->assertOk()->json('items');

        // ...but shown as it happened, with no-metadata uploads at upload time.
        $this->assertSame([$ceremony->ulid, $reception->ulid, $unknown->ulid], array_column($items, 'ulid'));
        $this->assertSame('2026-09-27T23:10:00+00:00', $items[0]['captured_at']);
        $this->assertNull($items[2]['captured_at']);
    }

    public function test_store_records_a_plausible_capture_time_only(): void
    {
        $this->enterAs();
        $payload = fn (?string $capturedAt, string $seed): array => [
            'filename' => 'IMG.JPG',
            'content_type' => 'image/jpeg',
            'size' => 10,
            'file_hash' => $this->hash($seed),
            'captured_at' => $capturedAt,
        ];

        $taken = $this->postJson('/wedding/api/uploads', $payload('2026-09-28T00:04:12.000Z', 'a'))->assertCreated()->json('ulid');
        $unset = $this->postJson('/wedding/api/uploads', $payload('1970-01-01T00:00:00Z', 'b'))->assertCreated()->json('ulid');
        $future = $this->postJson('/wedding/api/uploads', $payload(now()->addYear()->toIso8601String(), 'c'))->assertCreated()->json('ulid');
        $this->postJson('/wedding/api/uploads', $payload('not a date', 'd'))->assertUnprocessable()->assertJsonValidationErrors('captured_at');
        // No offset: the camera's wall clock, at the event (LA, UTC-7 in September).
        config(['wedding.event_timezone' => 'America/Los_Angeles']);
        $local = $this->postJson('/wedding/api/uploads', $payload('2026-09-27T17:04:12', 'e'))->assertCreated()->json('ulid');

        $row = fn (string $ulid): WeddingUpload => WeddingUpload::query()->where('ulid', $ulid)->sole();
        $this->assertSame('2026-09-28 00:04:12', $row($taken)->captured_at?->utc()->toDateTimeString());
        $this->assertTrue($row($taken)->taken_at?->eq($row($taken)->captured_at));
        $this->assertSame('2026-09-28 00:04:12', $row($local)->captured_at?->utc()->toDateTimeString());
        $this->assertNull($row($unset)->captured_at);
        $this->assertNull($row($future)->captured_at);
        $this->assertNotNull($row($future)->taken_at);
    }
}
