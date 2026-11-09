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

    public function test_verification_mail_is_dropped_not_deferred_while_tripped(): void
    {
        MailQuota::trip();
        Queue::fake();
        $mailable = new VerifyAccountMail(new User, 'en');
        $job = (new SendQueuedMailable($mailable))->through($mailable->middleware());
        $job->job = $this->createMock(Job::class);

        (new MailQuotaGate($mailable->transactional ?? false))
            ->handle($job, fn () => $this->fail('SMTP reached'));

        Queue::assertNothingPushed();
    }
}
