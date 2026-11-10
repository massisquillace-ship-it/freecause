<?php

namespace Tests\Feature;

use App\Mail\PetitionDigestMail;
use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Models\User;
use App\Support\MailQuota;
use App\Support\Settings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PetitionDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);

        Settings::set('smtp_enabled', true, 'global');
    }

    public function test_sends_digest_to_verified_opted_in_user_with_unsubscribe_url(): void
    {
        $this->publishedPetition();

        $user = User::factory()->create([
            'email' => 'digest@example.com',
            'locale' => 'en',
            'verified' => true,
            'notify_product' => true,
            'unsubscribed_at' => null,
        ]);

        Mail::fake();

        Artisan::call('petition:send-digest');

        Mail::assertQueued(PetitionDigestMail::class);

        $mail = Mail::queued(PetitionDigestMail::class)->firstOrFail();
        $this->assertSame('bulk', $mail->queue);
        $html = $mail->render();

        $this->assertTrue($mail->hasTo($user->email));
        $this->assertStringContainsString('/unsubscribe/', $html);
        $this->assertStringContainsString('Protect Local Forests', $html);
        $this->assertStringContainsString('1,200', $html);
    }

    public function test_running_twice_in_one_day_queues_one_mail_per_user(): void
    {
        $this->publishedPetition();

        User::factory()->create([
            'email' => 'twice@example.com',
            'locale' => 'en',
            'verified' => true,
            'notify_product' => true,
            'unsubscribed_at' => null,
        ]);

        Mail::fake();

        Artisan::call('petition:send-digest');
        Artisan::call('petition:send-digest');

        Mail::assertQueuedCount(1);
    }

    public function test_does_not_send_to_opted_out_or_unsubscribed_users(): void
    {
        $this->publishedPetition();

        User::factory()->create([
            'email' => 'no-product@example.com',
            'locale' => 'en',
            'verified' => true,
            'notify_product' => false,
        ]);

        User::factory()->create([
            'email' => 'unsubscribed@example.com',
            'locale' => 'en',
            'verified' => true,
            'notify_product' => true,
            'unsubscribed_at' => now(),
        ]);

        Mail::fake();

        Artisan::call('petition:send-digest');

        Mail::assertNothingQueued();
    }

    public function test_does_not_send_to_unverified_users(): void
    {
        $this->publishedPetition();

        User::factory()->create([
            'email' => 'unverified@example.com',
            'locale' => 'en',
            'verified' => false,
            'notify_product' => true,
        ]);

        Mail::fake();

        Artisan::call('petition:send-digest');

        Mail::assertNothingQueued();
    }

    public function test_sends_nothing_when_no_petitions_exist(): void
    {
        User::factory()->create([
            'email' => 'digest@example.com',
            'locale' => 'en',
            'verified' => true,
            'notify_product' => true,
        ]);

        Mail::fake();

        Artisan::call('petition:send-digest');

        Mail::assertNothingQueued();
    }

    public function test_aborts_dispatch_once_mail_quota_is_tripped(): void
    {
        $this->publishedPetition();

        User::factory()->create([
            'email' => 'digest@example.com',
            'locale' => 'en',
            'verified' => true,
            'notify_product' => true,
            'unsubscribed_at' => null,
        ]);

        MailQuota::trip();

        Mail::fake();

        Artisan::call('petition:send-digest');

        Mail::assertNothingQueued();
        $this->assertDatabaseHas('logs', ['title' => 'Petition digest aborted']);
    }

    public function test_skips_digest_while_mail_is_paused(): void
    {
        $this->publishedPetition();

        User::factory()->create([
            'email' => 'digest@example.com',
            'locale' => 'en',
            'verified' => true,
            'notify_product' => true,
            'unsubscribed_at' => null,
        ]);

        Settings::set('smtp_enabled', false);

        Mail::fake();

        $this->assertTrue(MailQuota::paused());

        Artisan::call('petition:send-digest');

        Mail::assertNothingQueued();
    }

    public function test_dry_run_reports_while_mail_is_paused(): void
    {
        $this->publishedPetition();

        Settings::set('smtp_enabled', false);

        Mail::fake();

        $exitCode = Artisan::call('petition:send-digest', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('dry_run=yes', Artisan::output());
        Mail::assertNothingQueued();
    }

    private function publishedPetition(): Petition
    {
        $owner = User::factory()->create([
            'verified' => false,
            'notify_product' => false,
        ]);

        $petition = Petition::factory()->create([
            'user_id' => $owner->id,
            'status' => 'published',
            'is_active' => true,
            'signature_count' => 1200,
        ]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Protect Local Forests',
            'slug' => 'protect-local-forests',
            'description' => 'Description',
        ]);

        return $petition;
    }
}
