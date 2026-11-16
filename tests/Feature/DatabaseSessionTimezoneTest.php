<?php

namespace Tests\Feature;

use Tests\TestCase;

class DatabaseSessionTimezoneTest extends TestCase
{
    public function test_application_timezone_is_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_mysql_family_connections_pin_their_session_to_utc(): void
    {
        foreach (['mysql', 'mariadb'] as $name) {
            $this->assertSame('+00:00', config("database.connections.{$name}.timezone"),
                "The {$name} session must use UTC instead of inheriting the server's SYSTEM timezone.");
        }
    }
}
