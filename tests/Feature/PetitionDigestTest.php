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
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

    public function test_limit_option_caps_queued_mails(): void
    {
        $this->publishedPetition();

        User::factory()->count(3)->create([
            'locale' => 'en',
            'verified' => true,
            'notify_product' => true,
            'unsubscribed_at' => null,
        ]);

        Mail::fake();

        Artisan::call('petition:send-digest', ['--limit' => 2]);

        Mail::assertQueuedCount(2);
    }

    public function test_limited_runs_resume_from_cursor_and_reset_after_reaching_the_end(): void
    {
        $petition = $this->publishedPetition();

        $users = User::factory()->count(4)->create([
            'locale' => 'en',
            'verified' => true,
            'notify_product' => true,
            'unsubscribed_at' => null,
        ]);

        Mail::fake();

        $recipients = fn () => Mail::queued(PetitionDigestMail::class)
            ->map(fn ($mail) => $mail->to[0]['address'])
            ->all();

        Artisan::call('petition:send-digest', ['--limit' => 2]);

        $this->travel(1)->days();
        $petition->forceFill(['created_at' => now()])->save();

        Artisan::call('petition:send-digest', ['--limit' => 2]);

        $this->assertEqualsCanonicalizing($users->pluck('email')->all(), $recipients());

        $this->travel(1)->days();
        $petition->forceFill(['created_at' => now()])->save();

        Artisan::call('petition:send-digest', ['--limit' => 2]);

        $this->assertSame(
            $users->take(2)->pluck('email')->all(),
            array_slice($recipients(), 4)
        );
    }

    public function test_dry_run_and_unlimited_runs_ignore_the_cursor(): void
    {
        $this->publishedPetition();

        $users = User::factory()->count(3)->create([
            'locale' => 'en',
            'verified' => true,
            'notify_product' => true,
            'unsubscribed_at' => null,
        ]);

        Mail::fake();
        Cache::put('digest:cursor', $users->get(1)->id);

        Artisan::call('petition:send-digest', ['--dry-run' => true, '--limit' => 1]);

        $this->assertSame($users->get(1)->id, Cache::get('digest:cursor'));
        Mail::assertNothingQueued();

        Artisan::call('petition:send-digest');

        $this->assertSame($users->get(1)->id, Cache::get('digest:cursor'));
        Mail::assertQueuedCount(3);
    }

    public function test_digest_is_scheduled_with_a_limit(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'petition:send-digest'));

        $this->assertNotNull($event);
        $this->assertStringContainsString('--limit=100', $event->command);
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

    public function test_sends_nothing_when_all_petitions_are_older_than_a_day(): void
    {
        $petition = $this->publishedPetition();
        $petition->forceFill(['created_at' => now()->subDays(2)])->save();

        User::factory()->create([
            'email' => 'old@example.com',
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

    public function test_saves_cursor_when_mail_quota_trips_mid_run(): void
    {
        $this->publishedPetition();

        $users = User::factory()->count(201)->create([
            'locale' => 'en',
            'verified' => true,
            'notify_product' => true,
            'unsubscribed_at' => null,
        ]);

        $secondChunkId = $users->last()->id;

        User::retrieved(function (User $user) use ($secondChunkId) {
            if ($user->id === $secondChunkId) {
                MailQuota::trip();
            }
        });

        Mail::fake();

        Artisan::call('petition:send-digest', ['--limit' => 500]);

        $this->assertDatabaseHas('logs', ['title' => 'Petition digest aborted']);
        $this->assertSame($users[199]->id, Cache::get('digest:cursor'));
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
