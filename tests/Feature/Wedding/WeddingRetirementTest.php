<?php

namespace Tests\Feature\Wedding;

use App\Models\WeddingUpload;
use Illuminate\Support\Facades\Storage;

class WeddingRetirementTest extends WeddingTestCase
{
    public function test_retired_pages_redirect_without_forwarding_guest_details(): void
    {
        config(['wedding.external_url' => 'https://events.example.test']);

        $this->get('/wedding?email=private@example.test')
            ->assertRedirect('https://events.example.test/events');
        $this->get('/wedding/hls/ceremony/master.m3u8')
            ->assertRedirect('https://events.example.test/events');
        $this->get('/')->assertOk();
    }

    public function test_retired_api_and_upload_requests_do_not_enter_legacy_handlers(): void
    {
        config(['wedding.external_url' => 'https://events.example.test']);

        $this->getJson('/wedding/api/gallery')->assertGone()
            ->assertJsonPath('url', 'https://events.example.test/events');
        $this->postJson('/wedding/api/uploads', [])->assertGone();
        $this->post('/wedding/enter', ['email' => 'guest@example.test'])->assertGone();
        $this->assertDatabaseCount('wedding_uploads', 0);
        $this->assertNull(session('wedding.guest'));
    }

    public function test_maintenance_commands_preserve_retired_originals_and_records(): void
    {
        $upload = $this->makeUpload([
            'status' => WeddingUpload::STATUS_PENDING,
            'created_at' => now()->subDays(3),
        ]);
        Storage::disk('r2')->put($upload->object_key, 'original');
        config(['wedding.external_url' => 'https://events.example.test']);

        foreach (['wedding:prune-uploads', 'wedding:resolve-videos', 'wedding:recluster-photos'] as $command) {
            $this->artisan($command)->assertSuccessful();
        }

        $this->assertDatabaseHas('wedding_uploads', ['id' => $upload->id, 'status' => WeddingUpload::STATUS_PENDING]);
        Storage::disk('r2')->assertExists($upload->object_key);
    }

    public function test_migration_freeze_blocks_legacy_requests_and_pruning(): void
    {
        $upload = $this->makeUpload(['status' => WeddingUpload::STATUS_PENDING, 'created_at' => now()->subDays(3)]);
        Storage::disk('r2')->put($upload->object_key, 'original');
        config(['wedding.migration_frozen' => true]);

        $this->get('/wedding')->assertServiceUnavailable()->assertHeader('Retry-After', '60');
        $this->postJson('/wedding/api/uploads', [])->assertServiceUnavailable();
        $this->artisan('wedding:prune-uploads')->assertSuccessful();
        $this->assertDatabaseHas('wedding_uploads', ['id' => $upload->id]);
        Storage::disk('r2')->assertExists($upload->object_key);
        $this->get('/')->assertOk();
    }
}
