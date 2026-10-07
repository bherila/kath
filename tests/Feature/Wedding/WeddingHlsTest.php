<?php

namespace Tests\Feature\Wedding;

use App\Models\WeddingUpload;
use Illuminate\Support\Facades\Storage;

class WeddingHlsTest extends WeddingTestCase
{
    private const CONTENT_ID = 'sha256:abc123';

    private function publishHls(string $sourceKey): void
    {
        $disk = Storage::disk('r2_hls');
        $disk->put('mappings/'.$sourceKey.'.json', json_encode(['sourceKey' => $sourceKey, 'contentId' => self::CONTENT_ID]));
        $disk->put('by-id/'.self::CONTENT_ID.'/master.m3u8', "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=800000\n720/index.m3u8\n");
        $disk->put('by-id/'.self::CONTENT_ID.'/720/index.m3u8', "#EXTM3U\n#EXT-X-MAP:URI=\"init_0.mp4\"\n#EXTINF:6.0,\nseg_0.m4s\n");
    }

    public function test_ceremony_shows_coming_soon_until_the_transcoder_publishes_it(): void
    {
        config(['wedding.ceremony_source_key' => 'videos/ceremony.mp4']);
        $this->enterAs();

        $this->get('/wedding')->assertSee('"master_url":null', false);
        $this->get('/wedding/hls/ceremony/master.m3u8')->assertNotFound();
    }

    public function test_ceremony_manifests_are_proxied_and_segments_redirected(): void
    {
        config(['wedding.ceremony_source_key' => 'videos/ceremony.mp4']);
        $this->publishHls('videos/ceremony.mp4');
        $this->enterAs();

        $this->get('/wedding')->assertSee('\/wedding\/hls\/ceremony\/master.m3u8', false);

        $this->get('/wedding/hls/ceremony/master.m3u8')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.apple.mpegurl')
            ->assertSee('/wedding/hls/ceremony/720/index.m3u8', false);

        $this->get('/wedding/hls/ceremony/720/index.m3u8')
            ->assertOk()
            ->assertSee('URI="/wedding/hls/ceremony/720/init_0.mp4"', false)
            ->assertSee('/wedding/hls/ceremony/720/seg_0.m4s', false);

        $this->get('/wedding/hls/ceremony/720/seg_0.m4s')
            ->assertRedirect('https://r2.example.test/r2_hls/by-id/'.self::CONTENT_ID.'/720/seg_0.m4s?view');
    }

    public function test_unsafe_paths_are_rejected(): void
    {
        config(['wedding.ceremony_source_key' => 'videos/ceremony.mp4']);
        $this->publishHls('videos/ceremony.mp4');
        $this->enterAs();

        $this->get('/wedding/hls/ceremony/720//seg_0.m4s')->assertUnprocessable();
        $this->get('/wedding/hls/ceremony/seg$0.m4s')->assertUnprocessable();
    }

    public function test_a_tampered_mapping_cannot_steer_reads_outside_the_output_tree(): void
    {
        config(['wedding.ceremony_source_key' => 'videos/ceremony.mp4']);
        Storage::disk('r2_hls')->put('mappings/videos/ceremony.mp4.json', json_encode(['contentId' => '../../mappings']));
        $this->enterAs();

        $this->get('/wedding/hls/ceremony/master.m3u8')->assertNotFound();
    }

    public function test_guest_videos_play_once_transcoded(): void
    {
        $video = $this->makeUpload(['kind' => WeddingUpload::KIND_VIDEO, 'object_key' => 'videos/clip.mov', 'mime_type' => 'video/quicktime']);
        $photo = $this->makeUpload();
        $this->enterAs();

        $master = "/wedding/hls/{$video->ulid}/master.m3u8";
        $this->getJson('/wedding/api/gallery')
            ->assertJsonPath('items.0.master_url', $master)
            ->assertJsonPath('items.0.processing', true)
            ->assertJsonPath('items.1.master_url', null);
        $this->get($master)->assertNotFound();
        $this->get("/wedding/hls/{$photo->ulid}/master.m3u8")->assertNotFound();

        $this->publishHls('videos/clip.mov');
        // Not-found results are rechecked at most every two minutes.
        $this->travel(3)->minutes();

        $this->get($master)
            ->assertOk()
            ->assertSee("/wedding/hls/{$video->ulid}/720/index.m3u8", false);
        $this->assertSame(self::CONTENT_ID, $video->refresh()->hls_content_id);
        $this->getJson('/wedding/api/gallery')->assertJsonPath('items.0.processing', false);
    }

    public function test_listing_the_gallery_does_no_storage_lookups(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeUpload(['kind' => WeddingUpload::KIND_VIDEO, 'object_key' => "videos/v{$i}.mp4"]);
        }
        $this->enterAs();

        $this->getJson('/wedding/api/gallery')->assertOk()->assertJsonCount(5, 'items');

        $this->assertSame(0, WeddingUpload::query()->whereNotNull('hls_checked_at')->count());
    }

    public function test_a_second_copy_of_a_large_unhashed_video_is_hidden_once_transcoded(): void
    {
        // Over the browser's hash cap: neither upload carried a file hash.
        $first = $this->makeUpload(['kind' => WeddingUpload::KIND_VIDEO, 'object_key' => 'videos/a.mov', 'file_hash' => null]);
        $second = $this->makeUpload(['kind' => WeddingUpload::KIND_VIDEO, 'object_key' => 'videos/b.mov', 'file_hash' => null]);
        // The transcoder maps both sources to the same content.
        $this->publishHls('videos/a.mov');
        $this->publishHls('videos/b.mov');

        $this->artisan('wedding:resolve-videos')->expectsOutputToContain('Resolved 2')->assertSuccessful();

        $this->assertSame(WeddingUpload::STATUS_READY, $first->refresh()->status);
        $this->assertSame(WeddingUpload::STATUS_HIDDEN, $second->refresh()->status);
        $this->assertSame($first->id, $second->duplicate_of_id);
        $this->enterAs();
        $this->getJson('/wedding/api/gallery')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.ulid', $first->ulid);
    }

    public function test_duplicate_videos_keep_the_earliest_whichever_resolves_first(): void
    {
        $first = $this->makeUpload(['kind' => WeddingUpload::KIND_VIDEO, 'object_key' => 'videos/a.mov', 'file_hash' => null]);
        $second = $this->makeUpload(['kind' => WeddingUpload::KIND_VIDEO, 'object_key' => 'videos/b.mov', 'file_hash' => null]);
        $this->publishHls('videos/b.mov');
        $this->enterAs();

        // The later copy is played (and resolved) before the earlier one is transcoded.
        $this->get("/wedding/hls/{$second->ulid}/master.m3u8")->assertOk();
        $this->publishHls('videos/a.mov');
        $this->get("/wedding/hls/{$first->ulid}/master.m3u8")->assertOk();

        $this->assertSame(WeddingUpload::STATUS_READY, $first->refresh()->status);
        $this->assertSame(WeddingUpload::STATUS_HIDDEN, $second->refresh()->status);
        $this->assertSame($first->id, $second->duplicate_of_id);
    }
}
