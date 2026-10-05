<?php

namespace Tests\Feature\Wedding;

use App\Models\WeddingUpload;
use App\Services\FileStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared setup for the wedding hub: both R2 disks are faked locally, and
 * presigning (which needs a real S3 client) is replaced by a deterministic
 * double. Object existence/size checks run against the fake disks.
 */
abstract class WeddingTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('r2');
        Storage::fake('r2_hls');
        $this->app->instance(FileStorageService::class, new FakeFileStorageService);
    }

    /**
     * Enter the hub as a guest and return that guest's token hash.
     */
    protected function enterAs(string $email = 'guest@example.test', ?string $name = 'Guest'): string
    {
        $this->post('/wedding/enter', ['email' => $email, 'name' => $name])->assertRedirect('/wedding');

        return hash('sha256', (string) session('wedding.guest.token'));
    }

    /**
     * Switch to a different guest (a fresh session).
     */
    protected function switchGuest(string $email = 'other@example.test'): string
    {
        $this->flushSession();

        return $this->enterAs($email, null);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeUpload(array $attributes = []): WeddingUpload
    {
        $ulid = Str::lower((string) Str::ulid());

        return WeddingUpload::query()->forceCreate(array_merge([
            'ulid' => $ulid,
            'guest_email' => 'someone@example.test',
            'guest_name' => 'Someone',
            'guest_token_hash' => hash('sha256', 'someone-else'),
            'kind' => WeddingUpload::KIND_PHOTO,
            'status' => WeddingUpload::STATUS_READY,
            'object_key' => 'photos/'.$ulid.'.jpg',
            'original_filename' => 'IMG_0001.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1234,
            'file_hash' => hash('sha256', $ulid),
        ], $attributes));
    }

    protected function hash(string $seed): string
    {
        return hash('sha256', $seed);
    }
}
