<?php

namespace Tests\Unit;

use App\Services\FileStorageService;
use Tests\TestCase;

/**
 * The SDK's presigner drops Content-Length and Content-Type from the signed
 * headers, which would let a presigned PUT carry a body of any size. Browser
 * upload URLs must sign both (verified against live R2: a mismatched length
 * or type is rejected with SignatureDoesNotMatch).
 */
class LengthBoundPresignTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.disks.presign-test' => [
            'driver' => 's3',
            'key' => 'AKIDEXAMPLE',
            'secret' => 'secret',
            'region' => 'auto',
            'bucket' => 'bucket',
            'endpoint' => 'https://account.r2.cloudflarestorage.com',
            'use_path_style_endpoint' => true,
        ]]);
    }

    /**
     * @return array<string, string>
     */
    private function signedQuery(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_single_put_urls_sign_length_and_type(): void
    {
        $signed = (new FileStorageService)->getSignedUploadUrl('presign-test', 'photos/a.jpg', 'image/jpeg', 1234);

        $this->assertSame('content-length;content-type;host', $this->signedQuery($signed['url'])['X-Amz-SignedHeaders']);
        $this->assertSame(['Content-Type' => 'image/jpeg'], $signed['headers']);
    }

    public function test_multipart_part_urls_sign_the_part_length(): void
    {
        $signed = (new FileStorageService)->getSignedMultipartUploadPartUrl('presign-test', 'videos/a.mp4', 'upload-1', 3, 5_242_880);
        $query = $this->signedQuery($signed['url']);

        $this->assertStringContainsString('content-length', $query['X-Amz-SignedHeaders']);
        $this->assertSame('3', $query['partNumber']);
        $this->assertArrayNotHasKey('Host', $signed['headers']);
        $this->assertArrayNotHasKey('Content-Length', $signed['headers']);
    }

    public function test_the_signature_changes_with_the_declared_length(): void
    {
        $storage = new FileStorageService;
        $this->travelTo(now()->startOfMinute());

        $small = $this->signedQuery($storage->getSignedUploadUrl('presign-test', 'photos/a.jpg', 'image/jpeg', 10)['url']);
        $large = $this->signedQuery($storage->getSignedUploadUrl('presign-test', 'photos/a.jpg', 'image/jpeg', 11)['url']);

        $this->assertNotSame($small['X-Amz-Signature'], $large['X-Amz-Signature']);
    }
}
