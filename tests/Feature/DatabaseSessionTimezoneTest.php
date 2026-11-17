<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseSessionTimezoneTest extends TestCase
{
    public function test_application_timezone_is_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_mysql_family_sessions_use_utc(): void
    {
        foreach (['mysql', 'mariadb'] as $connection) {
            $this->assertSame('+00:00', config("database.connections.{$connection}.timezone"),
                "The {$connection} session must use UTC rather than the server's SYSTEM timezone.");
        }
    }

    public function test_mysql_family_session_interprets_spring_forward_time_as_utc(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The database CI matrix exercises the MySQL-family session.');
        }

        $this->assertSame('+00:00', DB::scalar('SELECT @@session.time_zone'));
        $this->assertSame(0, (int) DB::scalar('SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), NOW())'));
        // This UTC instant falls in the spring-forward gap in Europe/London.
        $this->assertSame(1806197400, (int) DB::scalar('SELECT UNIX_TIMESTAMP(?)', ['2027-03-28 01:30:00']));
    }
}
