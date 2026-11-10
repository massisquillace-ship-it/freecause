<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class AuthClearResetsScheduleTest extends TestCase
{
    public function test_auth_clear_resets_is_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->pluck('command')->implode(' ');

        $this->assertStringContainsString('auth:clear-resets', $commands);
    }
}
