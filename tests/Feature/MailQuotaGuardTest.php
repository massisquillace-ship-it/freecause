<?php

namespace Tests\Feature;

use App\Mail\ContactMail;
use App\Mail\PetitionDigestMail;
use App\Mail\PetitionMilestoneMail;
use App\Models\Petition;
use App\Models\User;
use App\Support\MailQuota;
use App\Support\MailQuotaGate;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class MailQuotaGuardTest extends TestCase
{
    public function test_quota_error_messages_are_recognised(): void
    {
        $this->assertTrue(MailQuota::causedBy(new RuntimeException(
            'Expected response code "250/251/252" but got code "552", with message "552 Your monthly email allowance is exhausted".'
        )));

        $this->assertTrue(MailQuota::causedBy(new RuntimeException(
            'Expected response code "354" but got code "452", with message "452 Sending to this many would exceed your monthly email allowance".'
        )));

        $this->assertFalse(MailQuota::causedBy(new RuntimeException(
            'Expected response code "250/251/252" but got code "550", with message "550 unroutable domain: engl.co.at".'
        )));

        $this->assertFalse(MailQuota::causedBy(new RuntimeException(
            'Expected response code "250/251/252" but got code "552", with message "552 5.2.2 Mailbox full, quota exceeded for user@example.com".'
        )));
    }

    public function test_worker_quota_failure_trips_the_abort_flag(): void
    {
        Event::dispatch(new JobExceptionOccurred(
            'database',
            $this->createMock(Job::class),
            new RuntimeException('552 Your monthly email allowance is exhausted')
        ));

        $this->assertTrue(MailQuota::tripped());
    }

    public function test_unrelated_worker_failure_leaves_flag_clear(): void
    {
        Event::dispatch(new JobExceptionOccurred(
            'database',
            $this->createMock(Job::class),
            new RuntimeException('550 unroutable domain: engl.co.at')
        ));

        $this->assertFalse(MailQuota::tripped());
    }

    private function gate(object $mailable, callable $next): array
    {
        $inner = $this->createMock(Job::class);
        $inner->expects($this->once())->method('delete');
        $job = (new SendQueuedMailable($mailable))->through($mailable->middleware());
        $job->job = $inner;

        Queue::fake();
        $thrown = null;
        try {
            foreach ($mailable->middleware() as $m) {
                $m->handle($job, $next);
            }
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        return [$thrown, $job];
    }

    public function test_tripped_flag_stops_smtp_and_defers_transactional_mail(): void
    {
        MailQuota::trip();
        $reached = false;

        [$thrown] = $this->gate(new ContactMail('A', 'a@example.com', 'hi'), function () use (&$reached) {
            $reached = true;
        });

        $this->assertNull($thrown);
        $this->assertFalse($reached);
        Queue::assertPushed(SendQueuedMailable::class);
    }

    public function test_tripped_flag_defers_milestone_mail(): void
    {
        MailQuota::trip();

        [$thrown] = $this->gate(new PetitionMilestoneMail(new Petition, new User, 100), fn () => $this->fail('SMTP reached'));

        $this->assertNull($thrown);
        Queue::assertPushed(SendQueuedMailable::class);
    }

    public function test_real_queued_job_and_deferred_retry_both_carry_the_gate(): void
    {
        Queue::fake();
        (new ContactMail('A', 'a@example.com', 'hi'))->queue(app('queue'));

        Queue::assertPushed(SendQueuedMailable::class, fn ($job) => $job->middleware[0] instanceof MailQuotaGate);

        MailQuota::trip();
        $this->gate(new ContactMail('A', 'a@example.com', 'hi'), fn () => $this->fail('SMTP reached'));

        Queue::assertPushed(SendQueuedMailable::class, fn ($job) => ($job->middleware[0] ?? null) instanceof MailQuotaGate);
    }

    public function test_tripped_flag_drops_non_transactional_mail(): void
    {
        MailQuota::trip();

        [$thrown] = $this->gate(new PetitionDigestMail(new User, collect()), fn () => $this->fail('SMTP reached'));

        $this->assertNull($thrown);
        Queue::assertNothingPushed();
    }

    public function test_quota_rejection_is_absorbed_and_trips_flag(): void
    {
        [$thrown] = $this->gate(
            new ContactMail('A', 'a@example.com', 'hi'),
            fn () => throw new RuntimeException('552 Your monthly email allowance is exhausted')
        );

        $this->assertNull($thrown, 'quota failure must not fail the job (one Sentry event per job)');
        $this->assertTrue(MailQuota::tripped());
        Queue::assertPushed(SendQueuedMailable::class);
    }

    public function test_other_failures_still_throw(): void
    {
        $this->expectException(RuntimeException::class);
        $inner = $this->createMock(Job::class);
        $job = new SendQueuedMailable(new ContactMail('A', 'a@example.com', 'hi'));
        $job->job = $inner;

        (new MailQuotaGate(true))->handle($job, fn () => throw new RuntimeException('550 unroutable'));
    }
}
