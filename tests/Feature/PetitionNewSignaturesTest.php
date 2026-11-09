<?php

namespace Tests\Feature;

use App\Jobs\SendPetitionNewSignaturesNotification;
use App\Models\Petition;
use App\Models\PetitionSignatureNotification;
use App\Models\PetitionTranslation;
use App\Models\Signature;
use App\Models\User;
use App\Support\MailQuota;
use App\Support\Settings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PetitionNewSignaturesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);

        Settings::set('smtp_enabled', true, 'global');
    }

    public function test_sends_batched_email_to_opted_in_owner(): void
    {
        $owner = User::factory()->create(['locale' => 'en', 'notify_new_signatures' => true]);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
        PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Save the Park', 'slug' => 'save-the-park', 'description' => 'D']);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'created_at' => now()->subMinutes(10)]);

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');

        Queue::assertPushed(SendPetitionNewSignaturesNotification::class);
    }

    public function test_opt_out_owner_gets_nothing(): void
    {
        $owner = User::factory()->create(['locale' => 'en', 'notify_new_signatures' => false]);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'created_at' => now()->subMinutes(10)]);

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');

        Queue::assertNothingPushed();
    }

    public function test_does_not_resend_on_second_run_without_new_signatures(): void
    {
        $owner = User::factory()->create(['locale' => 'en', 'notify_new_signatures' => true]);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
        PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Save the Park', 'slug' => 'save-the-park', 'description' => 'D']);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'created_at' => now()->subMinutes(5)]);

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');
        Queue::assertPushed(SendPetitionNewSignaturesNotification::class, 1);

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');
        Queue::assertNothingPushed();
    }

    public function test_sends_mail_for_more_than_200_petitions(): void
    {
        $owner = User::factory()->create(['locale' => 'en', 'notify_new_signatures' => true]);
        $at = now()->subMinutes(10);
        for ($i = 0; $i < 205; $i++) {
            $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
            Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'is_spam' => false, 'created_at' => $at]);
        }

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');

        Queue::assertPushed(SendPetitionNewSignaturesNotification::class, 205);
    }

    public function test_skips_sending_while_mail_is_paused(): void
    {
        $owner = User::factory()->create(['locale' => 'en', 'notify_new_signatures' => true]);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
        PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Save the Park', 'slug' => 'save-the-park', 'description' => 'D']);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'created_at' => now()->subMinutes(10)]);

        Settings::set('smtp_enabled', false);

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');

        Queue::assertNothingPushed();
    }

    public function test_old_signatures_outside_lookback_wait_for_a_new_signature(): void
    {
        $owner = User::factory()->create(['locale' => 'en', 'notify_new_signatures' => true]);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true, 'last_notified_at' => now()->subDays(3)]);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'is_spam' => false, 'created_at' => now()->subDays(2)]);

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');
        Queue::assertNothingPushed();

        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'is_spam' => false, 'created_at' => now()->subMinutes(5)]);
        Artisan::call('emails:petition-new-signatures');
        Queue::assertPushed(SendPetitionNewSignaturesNotification::class, 1);
    }

    public function test_queue_insertion_does_not_advance_cursor(): void
    {
        $owner = User::factory()->create(['locale' => 'en', 'notify_new_signatures' => true]);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'created_at' => now()->subMinutes(10)]);

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');

        $this->assertDatabaseHas('petition_signature_notifications', [
            'petition_id' => $petition->id,
            'status' => 'pending',
        ]);
        $this->assertNull($petition->fresh()->last_notified_at);
    }

    public function test_transient_failure_is_retryable_and_advances_once_on_success(): void
    {
        $owner = User::factory()->create(['locale' => 'en', 'notify_new_signatures' => true]);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'created_at' => now()->subMinutes(10)]);

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');
        $notification = PetitionSignatureNotification::firstOrFail();
        $job = new SendPetitionNewSignaturesNotification($notification->id);

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('temporary'));
        try {
            $job->handle();
            $this->fail('Expected the first delivery attempt to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('temporary', $exception->getMessage());
        }

        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andReturnNull();
        $job->handle();
        $this->assertNotNull($petition->fresh()->last_notified_at);
        $this->assertSame('delivered', $notification->fresh()->status);
    }

    public function test_permanent_failure_is_recorded_and_rerun_requeues_same_batch(): void
    {
        $owner = User::factory()->create(['locale' => 'en', 'notify_new_signatures' => true]);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'created_at' => now()->subMinutes(10)]);

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');
        $notification = PetitionSignatureNotification::firstOrFail();
        (new SendPetitionNewSignaturesNotification($notification->id))->failed(new \RuntimeException('permanent'));

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');

        Queue::assertPushed(SendPetitionNewSignaturesNotification::class, fn ($job) => $job->notificationId === $notification->id);
        $this->assertSame(1, PetitionSignatureNotification::where('petition_id', $petition->id)->count());
    }

    public function test_quota_exhausted_releases_the_job_and_sends_nothing(): void
    {
        $owner = User::factory()->create(['locale' => 'en', 'notify_new_signatures' => true]);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'created_at' => now()->subMinutes(10)]);

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');
        $notification = PetitionSignatureNotification::firstOrFail();

        Mail::fake();
        MailQuota::trip();
        $job = (new SendPetitionNewSignaturesNotification($notification->id))->withFakeQueueInteractions();
        $job->handle();

        Mail::assertNothingSent();
        $job->assertReleased(3600);
        $this->assertSame('pending', $notification->fresh()->status);
        $this->assertNull($petition->fresh()->last_notified_at);
    }

    public function test_paused_job_keeps_releasing_without_exhausting_attempts(): void
    {
        $owner = User::factory()->create(['locale' => 'en', 'notify_new_signatures' => true]);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'created_at' => now()->subMinutes(10)]);

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');
        $notification = PetitionSignatureNotification::firstOrFail();

        $this->travel(4)->hours();
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'created_at' => now()->subMinutes(5)]);

        Mail::fake();
        MailQuota::trip();
        $job = (new SendPetitionNewSignaturesNotification($notification->id))->withFakeQueueInteractions();
        for ($i = 0; $i < 5; $i++) {
            $job->handle();
        }

        Mail::assertNothingSent();
        $job->assertReleased(3600);
        $this->assertSame(0, $job->tries);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertSame('pending', $notification->fresh()->status);
        $this->assertTrue($notification->fresh()->updated_at->gt(now()->subMinute()));

        Cache::forget('mail:quota-exhausted');
        Queue::fake();
        Artisan::call('emails:petition-new-signatures');
        Queue::assertNothingPushed();
    }

    public function test_failed_send_marks_failed_and_keeps_cursor(): void
    {
        $owner = User::factory()->create(['locale' => 'en', 'notify_new_signatures' => true]);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'created_at' => now()->subMinutes(10)]);

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');
        $notification = PetitionSignatureNotification::firstOrFail();
        $job = new SendPetitionNewSignaturesNotification($notification->id);

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('smtp down'));
        try {
            $job->handle();
            $this->fail('Expected the send to throw.');
        } catch (\RuntimeException) {
            $job->failed(new \RuntimeException('smtp down'));
        }

        $this->assertSame('failed', $notification->fresh()->status);
        $this->assertNull($petition->fresh()->last_notified_at);
    }

    public function test_stale_pending_row_is_redispatched_but_fresh_one_is_not(): void
    {
        $owner = User::factory()->create(['locale' => 'en', 'notify_new_signatures' => true]);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'created_at' => now()->subMinutes(10)]);

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');
        $notification = PetitionSignatureNotification::firstOrFail();

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');
        Queue::assertNothingPushed();

        $this->travel(4)->hours();
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'created_at' => now()->subMinutes(5)]);
        Artisan::call('emails:petition-new-signatures');
        Queue::assertPushed(SendPetitionNewSignaturesNotification::class, fn ($job) => $job->notificationId === $notification->id);
        $this->assertSame(1, PetitionSignatureNotification::where('petition_id', $petition->id)->count());

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');
        Queue::assertNothingPushed();
    }

    public function test_owner_who_opted_out_after_queueing_gets_no_mail(): void
    {
        $owner = User::factory()->create(['locale' => 'en', 'notify_new_signatures' => true]);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'created_at' => now()->subMinutes(10)]);

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');
        $notification = PetitionSignatureNotification::firstOrFail();
        $owner->update(['notify_new_signatures' => false]);

        Mail::fake();
        (new SendPetitionNewSignaturesNotification($notification->id))->handle();

        Mail::assertNothingSent();
        $this->assertSame('skipped', $notification->fresh()->status);
    }

    public function test_missing_owner_marks_notification_skipped(): void
    {
        $owner = User::factory()->create(['locale' => 'en', 'notify_new_signatures' => true]);
        $petition = Petition::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'is_active' => true]);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'created_at' => now()->subMinutes(10)]);

        Queue::fake();
        Artisan::call('emails:petition-new-signatures');
        $notification = PetitionSignatureNotification::firstOrFail();
        $petition->forceFill(['user_id' => null])->save();

        Mail::fake();
        (new SendPetitionNewSignaturesNotification($notification->id))->handle();

        Mail::assertNothingSent();
        $this->assertSame('skipped', $notification->fresh()->status);
    }
}
