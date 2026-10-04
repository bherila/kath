<?php

namespace App\Support;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * The self-identified guest of the wedding hub, held in the session. This is a
 * speed bump and an attribution label, not authentication: anyone can type any
 * email. Upload ownership therefore rests on a random per-session token whose
 * hash is stored on each row, so knowing a guest's email grants nothing.
 */
final class WeddingGuest
{
    private const SESSION_KEY = 'wedding.guest';

    private function __construct(
        public readonly string $email,
        public readonly ?string $name,
        private readonly string $token,
    ) {}

    public static function fromSession(Session $session): ?self
    {
        $data = $session->get(self::SESSION_KEY);

        if (! is_array($data)
            || ! is_string($data['email'] ?? null)
            || ! is_string($data['token'] ?? null)) {
            return null;
        }

        $name = $data['name'] ?? null;

        return new self($data['email'], is_string($name) ? $name : null, $data['token']);
    }

    public static function enter(Session $session, string $email, ?string $name): self
    {
        $guest = new self(Str::lower(trim($email)), self::cleanName($name), Str::random(40));

        // A fresh session id on entry prevents fixation of a pre-seeded id.
        $session->migrate(true);
        $session->put(self::SESSION_KEY, [
            'email' => $guest->email,
            'name' => $guest->name,
            'token' => $guest->token,
        ]);

        return $guest;
    }

    public static function leave(Session $session): void
    {
        $session->forget(self::SESSION_KEY);
        $session->migrate(true);
    }

    public function tokenHash(): string
    {
        return hash('sha256', $this->token);
    }

    private static function cleanName(?string $name): ?string
    {
        $name = $name === null ? '' : trim($name);

        return $name === '' ? null : $name;
    }
}
