<?php

namespace Tests\Feature\Wedding;

use App\Models\WeddingUpload;
use App\Services\FileStorageService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

class WeddingPruneTest extends WeddingTestCase
{
    private function storage(): FakeFileStorageService
    {
        /** @var FakeFileStorageService $storage */
        $storage = app(FileStorageService::class);

        return $storage;
    }

    public function test_abandoned_pending_uploads_and_their_objects_are_reaped(): void
    {
        $abandoned = $this->makeUpload([
            'status' => WeddingUpload::STATUS_PENDING,
            'thumbnail_key' => 'derived/old/thumb.jpg',
        ]);
        $abandoned->forceFill(['created_at' => now()->subHours(25)])->save();
        $inFlight = $this->makeUpload(['status' => WeddingUpload::STATUS_PENDING]);
        $ready = $this->makeUpload();
        Storage::disk('r2')->put($abandoned->object_key, 'partial');
        Storage::disk('r2')->put('derived/old/thumb.jpg', 'thumb');

        $this->artisan('wedding:prune-uploads')
            ->expectsOutputToContain('Discarded 1 abandoned upload(s); 0 kept for retry.')
            ->assertSuccessful();

        $this->assertModelMissing($abandoned);
        $this->assertModelExists($inFlight);
        $this->assertModelExists($ready);
        Storage::disk('r2')->assertMissing($abandoned->object_key);
        Storage::disk('r2')->assertMissing('derived/old/thumb.jpg');
    }

    public function test_a_failed_object_delete_keeps_a_tombstone_that_is_retried(): void
    {
        $this->enterAs();
        $ulid = $this->postJson('/wedding/api/uploads', [
            'filename' => 'a.jpg',
            'content_type' => 'image/jpeg',
            'size' => 10,
            'file_hash' => $this->hash('a'),
        ])->json('ulid');
        $upload = WeddingUpload::query()->where('ulid', $ulid)->sole();
        // Oversized object, so completion discards it — but R2 is failing.
        config(['wedding.max_bytes.photo' => 5]);
        Storage::disk('r2')->put($upload->object_key, str_repeat('x', 10));
        $this->storage()->failDeletes = true;

        $this->postJson("/wedding/api/uploads/{$ulid}/complete")->assertUnprocessable();

        // The keys survive in a tombstone that blocks nothing.
        $this->assertSame(WeddingUpload::STATUS_DELETING, $upload->refresh()->status);
        $this->getJson('/wedding/api/gallery')->assertJsonCount(0, 'items');
        $this->postJson('/wedding/api/uploads/check', ['hashes' => [$this->hash('a')]])->assertExactJson(['existing' => []]);

        $this->artisan('wedding:prune-uploads')->expectsOutputToContain('1 kept for retry')->assertSuccessful();
        $this->assertModelExists($upload);

        $this->storage()->failDeletes = false;
        $this->artisan('wedding:prune-uploads')->expectsOutputToContain('Discarded 1')->assertSuccessful();
        $this->assertModelMissing($upload);
        Storage::disk('r2')->assertMissing($upload->object_key);
    }

    public function test_prune_is_scheduled_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'wedding:prune-uploads'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }
}
