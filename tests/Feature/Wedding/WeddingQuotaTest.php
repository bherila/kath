<?php

namespace Tests\Feature\Wedding;

use App\Models\WeddingUpload;

class WeddingQuotaTest extends WeddingTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function photo(string $seed, int $size): array
    {
        return [
            'filename' => $seed.'.jpg',
            'content_type' => 'image/jpeg',
            'size' => $size,
            'file_hash' => $this->hash($seed),
            'thumbnail_size' => 100,
        ];
    }

    private function fromIp(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    public function test_reentering_with_a_fresh_session_does_not_reset_the_per_ip_quota(): void
    {
        config(['wedding.daily_quota.per_ip_bytes' => 1000, 'wedding.daily_quota.total_bytes' => 10_000]);

        $this->fromIp('198.51.100.7');
        $this->enterAs('a@example.test');
        $this->postJson('/wedding/api/uploads', $this->photo('one', 800))->assertCreated();
        // Thumbnail bytes count too: 800 + 100 reserved, so 200 more won't fit.
        $this->postJson('/wedding/api/uploads', $this->photo('two', 150))
            ->assertStatus(429)
            ->assertJsonPath('quota_exceeded', true);

        // A "new guest" from the same address is still over.
        $this->switchGuest('b@example.test');
        $this->postJson('/wedding/api/uploads', $this->photo('three', 150))->assertStatus(429);

        // Another network is unaffected.
        $this->fromIp('198.51.100.8');
        $this->postJson('/wedding/api/uploads', $this->photo('four', 150))->assertCreated();
    }

    public function test_the_global_quota_spans_every_address(): void
    {
        config(['wedding.daily_quota.per_ip_bytes' => 10_000, 'wedding.daily_quota.total_bytes' => 1000]);
        $this->enterAs();

        $this->fromIp('198.51.100.7')->postJson('/wedding/api/uploads', $this->photo('one', 800))->assertCreated();
        $this->fromIp('198.51.100.8')->postJson('/wedding/api/uploads', $this->photo('two', 200))
            ->assertStatus(429)
            ->assertJsonPath('message', "We've reached today's upload limit for the gallery. Please try again tomorrow.");
        $this->assertSame(1, WeddingUpload::query()->count());
    }

    public function test_the_quota_resets_at_pacific_midnight_and_discarded_uploads_free_it(): void
    {
        config(['wedding.daily_quota.per_ip_bytes' => 1000, 'wedding.daily_quota.total_bytes' => 1000]);
        $this->travelTo(now('America/Los_Angeles')->setTime(23, 30));
        $this->enterAs();

        $first = $this->postJson('/wedding/api/uploads', $this->photo('one', 900))->assertCreated()->json('ulid');
        $this->postJson('/wedding/api/uploads', $this->photo('two', 500))->assertStatus(429);

        // An abandoned upload that is discarded gives its bytes back.
        $this->postJson("/wedding/api/uploads/{$first}/complete")->assertUnprocessable();
        $this->postJson('/wedding/api/uploads', $this->photo('two', 500))->assertCreated();
        $this->postJson('/wedding/api/uploads', $this->photo('three', 500))->assertStatus(429);

        $this->travel(1)->hour();
        $this->postJson('/wedding/api/uploads', $this->photo('three', 500))->assertCreated();
    }
}
