<?php

namespace Tests\Feature\Wedding;

class WeddingGateTest extends WeddingTestCase
{
    public function test_visitor_without_email_sees_the_gate(): void
    {
        $this->get('/wedding')
            ->assertOk()
            ->assertSee('Enter your email')
            ->assertDontSee('wedding-bootstrap', false);
    }

    public function test_invalid_email_is_rejected(): void
    {
        $this->from('/wedding')
            ->post('/wedding/enter', ['email' => 'not-an-email'])
            ->assertRedirect('/wedding')
            ->assertSessionHasErrors('email');

        $this->assertNull(session('wedding.guest'));
    }

    public function test_entering_an_email_opens_the_hub(): void
    {
        $this->enterAs('Guest@Example.TEST', 'Aunt May');

        $this->assertSame('guest@example.test', session('wedding.guest.email'));
        $this->get('/wedding')
            ->assertOk()
            ->assertSee('wedding-bootstrap', false)
            ->assertSee('Aunt May');
    }

    public function test_hub_routes_require_an_email(): void
    {
        $this->getJson('/wedding/api/gallery')->assertUnauthorized();
        $this->postJson('/wedding/api/uploads', [])->assertUnauthorized();
        $this->get('/wedding/hls/ceremony/master.m3u8')->assertRedirect('/wedding');
    }

    public function test_leaving_clears_the_guest(): void
    {
        $this->enterAs();

        $this->post('/wedding/leave')->assertRedirect('/wedding');

        $this->assertNull(session('wedding.guest'));
        $this->getJson('/wedding/api/gallery')->assertUnauthorized();
    }

    public function test_csp_allows_only_the_configured_bucket_origins(): void
    {
        config([
            'filesystems.disks.r2.endpoint' => 'https://acct.r2.cloudflarestorage.com',
            'filesystems.disks.r2.bucket' => 'media-bucket',
            'filesystems.disks.r2_hls.endpoint' => 'https://acct.r2.cloudflarestorage.com',
            'filesystems.disks.r2_hls.bucket' => 'hls-bucket',
        ]);

        $csp = (string) $this->get('/wedding')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('https://media-bucket.acct.r2.cloudflarestorage.com', $csp);
        $this->assertStringContainsString('https://hls-bucket.acct.r2.cloudflarestorage.com', $csp);
        $this->assertStringContainsString("worker-src 'self' blob:", $csp);
        $this->assertStringNotContainsString('*.r2.cloudflarestorage.com', $csp);
    }
}
