<?php

namespace Tests\Feature\Wedding;

use App\Models\WeddingUpload;

class WeddingGalleryTest extends WeddingTestCase
{
    public function test_gallery_lists_ready_uploads_newest_first_without_emails(): void
    {
        $older = $this->makeUpload(['thumbnail_key' => 'derived/a/thumb.jpg', 'guest_name' => 'Aunt May']);
        $this->makeUpload(['status' => WeddingUpload::STATUS_PENDING]);
        $this->makeUpload(['status' => WeddingUpload::STATUS_HIDDEN]);
        $newer = $this->makeUpload(['guest_name' => null]);
        $this->enterAs();

        $response = $this->getJson('/wedding/api/gallery')->assertOk();

        $response->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.ulid', $newer->ulid)
            ->assertJsonPath('items.1.ulid', $older->ulid)
            ->assertJsonPath('items.1.guest_name', 'Aunt May')
            ->assertJsonPath('items.1.thumb_url', "/wedding/media/{$older->ulid}/thumb")
            ->assertJsonPath('items.0.thumb_url', null)
            ->assertJsonPath('items.0.mine', false);
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
}
