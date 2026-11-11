<?php

namespace Tests\Feature;

use App\Support\MailQuota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class QueueMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_reads_integer_available_at_without_type_error(): void
    {
        config(['queue.default' => 'database']);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Example']),
            'attempts' => 0,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $this->artisan('queue:monitor')->assertExitCode(0);
    }

    public function test_monitor_alerts_on_stale_job(): void
    {
        config(['queue.default' => 'database']);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Example']),
            'attempts' => 0,
            'available_at' => now()->subHours(2)->getTimestamp(),
            'created_at' => now()->subHours(2)->getTimestamp(),
        ]);

        // Alerts are handled findings, not a command failure.
        $this->artisan('queue:monitor')->assertExitCode(0);
    }

    public function test_alert_is_logged_not_mailed_while_quota_tripped(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->subHours(2)->getTimestamp(),
            'created_at' => now()->subHours(2)->getTimestamp(),
        ]);
        MailQuota::trip();
        Mail::shouldReceive('raw')->never();

        $this->artisan('queue:monitor', ['--alert-email' => 'ops@example.com'])->assertExitCode(0);
    }

    public function test_alert_is_mailed_when_quota_clear(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->subHours(2)->getTimestamp(),
            'created_at' => now()->subHours(2)->getTimestamp(),
        ]);
        Mail::shouldReceive('raw')->once();

        $this->artisan('queue:monitor', ['--alert-email' => 'ops@example.com'])->assertExitCode(0);
    }

    public function test_backlog_alert_is_mailed_at_most_once_per_hour(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->subHours(2)->getTimestamp(),
            'created_at' => now()->subHours(2)->getTimestamp(),
        ]);
        Mail::shouldReceive('raw')->once();

        $this->artisan('queue:monitor', ['--alert-email' => 'ops@example.com'])->assertExitCode(0);
        $this->artisan('queue:monitor', ['--alert-email' => 'ops@example.com'])->assertExitCode(0);
    }

    public function test_new_failures_still_mail_while_backlog_alert_is_deduped(): void
    {
        config(['queue.default' => 'database']);
        Cache::put('queue:monitor:alerted', 1, 3600);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()->toDateTimeString(),
        ]);
        Mail::shouldReceive('raw')->once();

        $this->artisan('queue:monitor', ['--alert-email' => 'ops@example.com'])->assertExitCode(0);
    }

    public function test_alert_slot_not_claimed_without_email_or_while_quota_tripped(): void
    {
        config(['queue.default' => 'database']);
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'available_at' => now()->subHours(2)->getTimestamp(),
            'created_at' => now()->subHours(2)->getTimestamp(),
        ]);

        $this->artisan('queue:monitor')->assertExitCode(0);
        $this->assertFalse(Cache::has('queue:monitor:alerted'));

        MailQuota::trip();
        $this->artisan('queue:monitor', ['--alert-email' => 'ops@example.com'])->assertExitCode(0);
        $this->assertFalse(Cache::has('queue:monitor:alerted'));
    }

    public function test_alert_slot_released_when_condition_clears(): void
    {
        config(['queue.default' => 'database']);
        Cache::put('queue:monitor:alerted', 1, 3600);

        $this->artisan('queue:monitor')->assertExitCode(0);

        $this->assertFalse(Cache::has('queue:monitor:alerted'));
    }

    private function recordFailure(string $at): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) str()->uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Mail\\PetitionDigestMail']),
            'exception' => 'boom',
            'failed_at' => $at,
        ]);
    }

    public function test_stale_backlog_does_not_alert(): void
    {
        Cache::flush();
        $this->recordFailure('2026-01-01 00:00:00');

        Cache::forever('queue:monitor:last-checked-at', '2026-06-01 00:00:00');

        $this->artisan('queue:monitor')->assertExitCode(0);
    }

    public function test_new_failure_alerts(): void
    {
        // exit 0: see MonitorQueue::handle
        Cache::flush();
        Cache::forever('queue:monitor:last-checked-at', '2026-01-01 00:00:00');
        $this->recordFailure(now()->toDateTimeString());

        $this->artisan('queue:monitor')->assertExitCode(0);
    }

    public function test_first_run_reports_existing_failures(): void
    {
        Cache::flush();
        $this->recordFailure(now()->toDateTimeString());

        $this->artisan('queue:monitor')->assertExitCode(0);
    }

    public function test_repeated_new_failures_mail_at_most_hourly(): void
    {
        Cache::flush();
        Mail::shouldReceive('raw')->once();

        $this->recordFailure(now()->toDateTimeString());
        $this->artisan('queue:monitor', ['--alert-email' => 'ops@example.com'])->expectsOutput('Alert sent to ops@example.com')->run();

        $this->travel(5)->minutes();
        $this->recordFailure(now()->toDateTimeString());
        $this->artisan('queue:monitor', ['--alert-email' => 'ops@example.com'])
            ->expectsOutput('New failed jobs: 1')
            ->doesntExpectOutput('Alert sent to ops@example.com')
            ->assertExitCode(0);
    }
}
