<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('r2');
        Storage::fake('r2_hls');
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function pages(): array
    {
        return [
            'home' => ['/', false],
            'blog' => ['/blog', false],
            'contact' => ['/contact', false],
            'wedding gate' => ['/wedding', false],
            'wedding hub' => ['/wedding', true],
        ];
    }

    /**
     * The policy carries a per-request nonce, so an inline script without it
     * is silently dropped by the browser (e.g. the pre-paint theme script).
     */
    #[DataProvider('pages')]
    public function test_every_inline_script_carries_the_policy_nonce(string $uri, bool $asGuest): void
    {
        if ($asGuest) {
            $this->post('/wedding/enter', ['email' => 'guest@example.test'])->assertRedirect('/wedding');
        }

        $response = $this->get($uri)->assertOk();

        $header = (string) $response->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src [^;]*'nonce-([^']+)'/", $header);
        preg_match("/script-src [^;]*'nonce-([^']+)'/", $header, $match);
        $nonce = $match[1];

        preg_match_all('/<script\b([^>]*)>/i', (string) $response->getContent(), $tags);
        $inline = array_filter(
            $tags[1],
            // External scripts are allowed by 'self'; JSON data blocks never execute.
            fn (string $attrs) => ! preg_match('/\bsrc=/i', $attrs) && ! preg_match('/type="application\/json"/i', $attrs),
        );

        $this->assertNotEmpty($inline, "{$uri} renders no inline scripts; this test would pass vacuously.");
        foreach ($inline as $attrs) {
            $this->assertStringContainsString("nonce=\"{$nonce}\"", $attrs, "Inline <script{$attrs}> on {$uri} lacks the CSP nonce.");
        }
    }

    public function test_cloudflare_web_analytics_is_allowed(): void
    {
        $header = (string) $this->get('/')->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression('/script-src [^;]*static\.cloudflareinsights\.com/', $header);
        $this->assertMatchesRegularExpression('/connect-src [^;]*cloudflareinsights\.com/', $header);
    }
}
