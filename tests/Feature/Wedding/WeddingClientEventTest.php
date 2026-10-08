<?php

namespace Tests\Feature\Wedding;

use Illuminate\Support\Facades\Log;

class WeddingClientEventTest extends WeddingTestCase
{
    public function test_a_guest_browser_event_is_logged_without_identity(): void
    {
        $token = $this->enterAs('guest@example.test');
        $logged = null;
        Log::shouldReceive('channel')->with('wedding_client')->andReturnSelf();
        Log::shouldReceive('info')->once()->withArgs(function (string $event, array $context) use (&$logged): bool {
            $logged = [$event, $context];

            return true;
        });

        $this->postJson('/wedding/api/client-events', [
            'event' => 'picker_empty',
            'reason' => 'cancel',
            'files' => [['type' => 'image/heic', 'ext' => 'heic', 'size' => 123, 'name' => 'IMG_0001.HEIC']],
        ])->assertNoContent();

        [$event, $context] = $logged;
        $this->assertSame('picker_empty', $event);
        $this->assertSame(substr($token, 0, 8), $context['guest']);
        $this->assertSame('cancel', $context['detail']['reason']);
        $this->assertSame([['type' => 'image/heic', 'ext' => 'heic', 'size' => 123]], $context['detail']['files']);
        $this->assertStringNotContainsString('guest@example.test', json_encode($context) ?: '');
        $this->assertStringNotContainsString('IMG_0001', json_encode($context) ?: '');
    }

    public function test_unknown_events_and_strangers_are_refused(): void
    {
        $this->postJson('/wedding/api/client-events', ['event' => 'picker_empty'])->assertUnauthorized();

        $this->enterAs();
        $this->postJson('/wedding/api/client-events', ['event' => 'anything'])->assertUnprocessable();
    }

    public function test_events_are_rate_limited_per_guest(): void
    {
        $this->enterAs();
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');

        foreach (range(1, 60) as $_) {
            $this->postJson('/wedding/api/client-events', ['event' => 'picker_change'])->assertNoContent();
        }
        $this->postJson('/wedding/api/client-events', ['event' => 'picker_change'])->assertTooManyRequests();
    }

    public function test_fresh_sessions_share_an_ip_ceiling(): void
    {
        Log::shouldReceive('channel')->andReturnSelf();
        Log::shouldReceive('info');

        foreach (range(1, 3) as $guest) {
            $this->switchGuest("guest{$guest}@example.test");
            foreach (range(1, 40) as $_) {
                $this->postJson('/wedding/api/client-events', ['event' => 'picker_change'])->assertNoContent();
            }
        }
        $this->switchGuest('guest4@example.test');
        $this->postJson('/wedding/api/client-events', ['event' => 'picker_change'])->assertTooManyRequests();
    }
}
