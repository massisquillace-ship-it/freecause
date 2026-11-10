<?php

namespace Tests\Feature;

use App\Mail\VerifyAccountMail;
use App\Models\User;
use App\Support\MailQuota;
use App\Support\MailQuotaGate;
use App\Support\Settings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class MailQuotaUserMessageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
    }

    public function test_forgot_password_reply_is_identical_for_known_and_unknown_email(): void
    {
        Mail::fake();
        User::factory()->create(['email' => 'known@example.com']);

        $known = $this->from('/en/forgot-password')
            ->post('/en/forgot-password', ['email' => 'known@example.com']);
        $unknown = $this->from('/en/forgot-password')
            ->post('/en/forgot-password', ['email' => 'nobody@example.com']);

        $known->assertRedirect('/en/forgot-password')
            ->assertSessionHas('status', __('auth.reset_link_sent'))
            ->assertSessionHasNoErrors();
        $unknown->assertRedirect('/en/forgot-password')
            ->assertSessionHas('status', __('auth.reset_link_sent'))
            ->assertSessionHasNoErrors();
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));
    }

    public function test_reset_password_with_invalid_token_shows_translated_error(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com']);

        $this->from('/en/reset-password/bad')
            ->post('/en/reset-password', [
                'email' => $user->email,
                'token' => 'bad',
                'password' => 'New-Password-123',
                'password_confirmation' => 'New-Password-123',
            ])
            ->assertRedirect('/en/reset-password/bad')
            ->assertSessionHasErrors(['email' => __('auth.reset_failed')]);
    }

    public function test_reset_password_save_failure_is_logged_and_shows_reset_failed(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $token = Password::createToken($user);

        User::saving(function () {
            throw new RuntimeException('db down');
        });

        $this->from('/en/reset-password/x')
            ->post('/en/reset-password', [
                'email' => $user->email,
                'token' => $token,
                'password' => 'New-Password-123',
                'password_confirmation' => 'New-Password-123',
            ])
            ->assertSessionHasErrors(['email' => __('auth.reset_failed')]);

        $this->assertDatabaseHas('logs', [
            'level' => 'error',
            'context' => 'auth.password',
        ]);
    }

    public function test_reset_password_success_flashes_translated_message(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $token = Password::createToken($user);

        $this->post('/en/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'New-Password-123',
            'password_confirmation' => 'New-Password-123',
        ])->assertSessionHasNoErrors();

        $this->assertStringContainsString(__('auth.reset_success'), json_encode(session()->all()));
    }

    public function test_reset_keys_exist_in_every_locale(): void
    {
        foreach (['da', 'de', 'el', 'en', 'es', 'fr', 'it', 'nl', 'pl', 'pt', 'ro', 'ru', 'sv', 'tr'] as $locale) {
            foreach (['reset_link_sent', 'reset_failed', 'reset_success'] as $key) {
                $text = trans("auth.$key", [], $locale);
                $this->assertNotSame("auth.$key", $text, "$locale $key");
                $this->assertNotSame('', trim($text), "$locale $key");
            }
        }
    }

    public function test_password_reset_says_try_later_without_sending_when_tripped(): void
    {
        MailQuota::trip();
        Password::shouldReceive('sendResetLink')->never();

        $this->from('/en/forgot-password')
            ->post('/en/forgot-password', ['email' => 'a@example.com'])
            ->assertRedirect('/en/forgot-password')
            ->assertSessionHasErrors(['email' => __('auth.email_try_later')]);
    }

    public function test_password_reset_quota_rejection_trips_flag_and_says_try_later(): void
    {
        Password::shouldReceive('sendResetLink')->once()
            ->andThrow(new RuntimeException('552 Your monthly email allowance is exhausted'));

        $this->from('/en/forgot-password')
            ->post('/en/forgot-password', ['email' => 'a@example.com'])
            ->assertSessionHasErrors(['email' => __('auth.email_try_later')]);

        $this->assertTrue(MailQuota::tripped());
    }

    public function test_password_reset_other_mail_errors_still_surface(): void
    {
        Password::shouldReceive('sendResetLink')->once()
            ->andThrow(new RuntimeException('550 unroutable domain'));

        $this->post('/en/forgot-password', ['email' => 'a@example.com'])->assertStatus(500);

        $this->assertFalse(MailQuota::tripped());
    }

    public function test_resend_verification_says_try_later_and_keeps_token_when_tripped(): void
    {
        Settings::set('smtp_enabled', true);
        Mail::fake();
        $user = User::factory()->create(['verified' => false, 'verification_token' => 'keep-me']);
        MailQuota::trip();

        $this->from('/en/resend-verification')
            ->post('/en/resend-verification', ['email' => $user->email])
            ->assertSessionHasErrors(['email' => __('auth.email_try_later')]);

        Mail::assertNothingQueued();
        $this->assertSame('keep-me', $user->fresh()->verification_token);
    }

    public function test_registration_queues_verification_mail_as_droppable(): void
    {
        Settings::set('require_email_verification', true);
        Settings::set('smtp_enabled', true);
        Mail::fake();

        $this->post('/en/register', [
            'name' => 'Pending',
            'surname' => 'User',
            'email' => 'pending@example.com',
            'password' => 'password123',
            'agree_terms' => 'on',
        ])->assertRedirect('/en/verify-email');

        $user = User::where('email', 'pending@example.com')->firstOrFail();
        $this->assertNotNull($user->verification_token);
        $this->assertNotNull($user->verification_token_sent_at);
        Mail::assertQueued(VerifyAccountMail::class, function (VerifyAccountMail $mail) use ($user) {
            $this->assertFalse($mail->transactional);
            $this->assertSame($user->verification_token, User::hashVerificationToken($mail->verificationToken));
            $this->assertNotSame($user->verification_token, $mail->verificationToken);

            return true;
        });
    }

    public function test_registration_says_try_later_and_queues_nothing_when_tripped(): void
    {
        Settings::set('require_email_verification', true);
        Settings::set('smtp_enabled', true);
        Mail::fake();
        MailQuota::trip();

        $this->post('/en/register', [
            'name' => 'Pending',
            'surname' => 'User',
            'email' => 'pending@example.com',
            'password' => 'password123',
            'agree_terms' => 'on',
        ])->assertRedirect('/en/verify-email')
            ->assertSessionHas('error', __('auth.email_try_later'))
            ->assertSessionMissing('success');

        Mail::assertNotQueued(VerifyAccountMail::class);
        $this->assertDatabaseHas('users', ['email' => 'pending@example.com']);
    }

    public function test_deferred_verification_keeps_the_original_expiry(): void
    {
        Settings::set('require_email_verification', true);
        Settings::set('smtp_enabled', true);
        Mail::fake();
        MailQuota::trip();

        $this->travelTo(now());
        $this->post('/en/register', [
            'name' => 'Pending',
            'surname' => 'User',
            'email' => 'expiry@example.com',
            'password' => 'password123',
            'agree_terms' => 'on',
        ])->assertRedirect('/en/verify-email');

        $user = User::where('email', 'expiry@example.com')->firstOrFail();
        $token = $this->knownVerificationToken($user);
        $sentAt = $user->verification_token_sent_at;

        $this->travel(49)->hours();
        $this->get("/en/verify/{$token}")
            ->assertOk()
            ->assertViewHas('expired', true);
        $this->post("/en/verify/{$token}")
            ->assertRedirect('/en/resend-verification');

        $this->assertFalse((bool) $user->fresh()->verified);
        $this->assertTrue($sentAt->eq($user->fresh()->verification_token_sent_at));
    }

    public function test_verification_mail_is_dropped_while_tripped(): void
    {
        MailQuota::trip();
        Queue::fake();
        $user = User::factory()->create([
            'verified' => false,
            'verification_token' => 'keep-me',
            'verification_token_sent_at' => now(),
        ]);
        $mailable = new VerifyAccountMail($user, 'en');
        $job = (new SendQueuedMailable($mailable))->through($mailable->middleware());
        $job->job = $this->createMock(Job::class);

        (new MailQuotaGate($mailable->transactional))
            ->handle($job, fn () => $this->fail('SMTP reached'));

        Queue::assertNothingPushed();
    }
}
