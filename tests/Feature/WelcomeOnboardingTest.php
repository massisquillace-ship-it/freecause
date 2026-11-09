<?php

namespace Tests\Feature;

use App\Jobs\SendWelcomeEmail;
use App\Mail\WelcomeAccountMail;
use App\Models\User;
use App\Support\Locale;
use App\Support\MailQuota;
use App\Support\Settings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WelcomeOnboardingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([LanguageSeeder::class, UserLevelSeeder::class]);
        Settings::set('smtp_enabled', true);
        config(['app.sso_secret' => null]);
        Mail::fake();
    }

    private function newUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'verified' => true,
            'google_id' => 'synthetic-google-id-'.fake()->uuid(),
            'locale' => 'en_US',
            'country' => null,
            'gender' => null,
            'identify_mode' => 'full',
            'nickname' => null,
            'welcome_email_pending' => true,
            'preferred_language_confirmed_at' => null,
        ], $attributes));
    }

    private function profile(array $attributes = []): array
    {
        return array_merge([
            'first_name' => 'Test',
            'last_name' => 'User',
            'country' => 'IT',
            'gender' => 'prefer_not_to_say',
            'locale' => 'it',
            'identify_mode' => 'full',
        ], $attributes);
    }

    public function test_new_google_profile_requires_explicit_language_selection(): void
    {
        $user = $this->newUser(['country' => 'IT', 'gender' => 'female']);
        $this->assertFalse($user->hasCompleteProfile());

        $this->actingAs($user)->get('/en/profile')
            ->assertOk()
            ->assertSee('id="language-field"', false)
            ->assertSee('href="#language-field"', false)
            ->assertSee(__('profile.select_language'))
            ->assertDontSee('value="en" selected', false);

        Mail::assertNothingSent();
    }

    public function test_missing_or_unsupported_language_does_not_save_or_send_welcome(): void
    {
        Queue::fake();
        $user = $this->newUser();
        foreach (['', 'zz'] as $locale) {
            $this->actingAs($user)->from('/en/profile')->post('/en/profile', $this->profile(['locale' => $locale]))
                ->assertSessionHasErrors('locale');
        }
        $this->assertNull($user->fresh()->preferred_language_confirmed_at);
        Queue::assertNothingPushed();
    }

    public function test_profile_save_persists_preferred_language_and_queues_welcome(): void
    {
        Queue::fake();
        $user = $this->newUser();
        $this->actingAs($user)->post('/en/profile', $this->profile())
            ->assertSessionHasNoErrors()->assertRedirect('/it/profile');

        $user->refresh();
        $this->assertSame('it_IT', $user->locale);
        $this->assertNotNull($user->preferred_language_confirmed_at);
        $this->assertTrue($user->hasCompleteProfile());
        $this->assertFalse($user->notify_product);
        Queue::assertPushed(SendWelcomeEmail::class, fn ($job) => $job->userId === $user->id);
        Mail::assertNothingSent();
    }

    public function test_welcome_is_sent_once_in_saved_language_even_after_repeated_saves(): void
    {
        Queue::fake();
        $user = $this->newUser();
        $this->actingAs($user)->post('/en/profile', $this->profile())->assertSessionHasNoErrors();
        (new SendWelcomeEmail($user->id))->handle();
        $this->actingAs($user)->post('/it/profile', $this->profile())->assertSessionHasNoErrors();
        (new SendWelcomeEmail($user->id))->handle();

        Mail::assertSent(WelcomeAccountMail::class, fn ($mail) => $mail->hasTo($user->email) && $mail->emailLocale === 'it');
        Mail::assertSentCount(1);
        $this->assertNotNull($user->fresh()->welcome_email_sent_at);
        $this->assertFalse($user->fresh()->welcome_email_pending);
    }

    public function test_password_registration_waits_for_profile_completion(): void
    {
        Queue::fake();
        Settings::set('require_email_verification', false);
        $this->post('/en/register', [
            'name' => 'Test', 'surname' => 'Person',
            'email' => 'welcome-test@example.test', 'password' => 'test-password123',
            'password_confirmation' => 'test-password123',
            'agree_terms' => 'on',
        ])->assertSessionHasNoErrors()->assertRedirectContains('/en/profile');

        $user = User::where('email', 'welcome-test@example.test')->firstOrFail();
        $this->assertTrue($user->welcome_email_pending);
        Queue::assertNothingPushed();
        $this->actingAs($user)->post('/en/profile', $this->profile())->assertSessionHasNoErrors();
        Queue::assertPushed(SendWelcomeEmail::class);
    }

    public function test_existing_accounts_do_not_receive_retrospective_welcome(): void
    {
        Queue::fake();
        $user = $this->newUser(['welcome_email_pending' => false]);
        $this->actingAs($user)->post('/en/profile', $this->profile())->assertSessionHasNoErrors();
        Queue::assertNothingPushed();
        (new SendWelcomeEmail($user->id))->handle();
        Mail::assertNothingSent();
    }

    public function test_unverified_or_incomplete_accounts_do_not_receive_welcome(): void
    {
        foreach ([['verified' => false], ['country' => null], ['gender' => null], ['locale' => 'zz']] as $override) {
            $user = $this->newUser(array_merge([
                'country' => 'IT', 'gender' => 'female', 'preferred_language_confirmed_at' => now(),
            ], $override));
            (new SendWelcomeEmail($user->id))->handle();
            $this->assertTrue($user->fresh()->welcome_email_pending);
        }
        Mail::assertNothingSent();
    }

    public function test_delivery_uses_latest_saved_language_and_supports_every_site_language(): void
    {
        foreach (Locale::supported() as $locale) {
            $user = $this->newUser([
                'country' => 'IT', 'gender' => 'female', 'locale' => Locale::toFull($locale),
                'preferred_language_confirmed_at' => now(),
            ]);
            (new SendWelcomeEmail($user->id))->handle();
            $mail = new WelcomeAccountMail($locale);
            $html = $mail->render();
            $this->assertStringContainsString('lang="'.$locale.'"', $html);
            $this->assertStringContainsString('/'.$locale.'/petitions', $html);
            $this->assertStringNotContainsString('profile.welcome_', $html);
            $this->assertStringContainsString(e(__('profile.welcome_subject', [], $locale)), $html);
        }
        Mail::assertSentCount(count(Locale::supported()));
    }

    public function test_smtp_failure_preserves_pending_state_for_retry(): void
    {
        $user = $this->newUser(['country' => 'IT', 'gender' => 'female', 'preferred_language_confirmed_at' => now()]);
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Synthetic SMTP timeout'));
        try {
            (new SendWelcomeEmail($user->id))->handle();
            $this->fail('Expected SMTP failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic SMTP timeout', $e->getMessage());
        }
        $this->assertTrue($user->fresh()->welcome_email_pending);
        $this->assertNull($user->fresh()->welcome_email_sent_at);
    }

    public function test_exhausted_quota_defers_welcome_instead_of_dropping_it(): void
    {
        $user = $this->newUser(['country' => 'IT', 'gender' => 'female', 'preferred_language_confirmed_at' => now()]);
        MailQuota::trip();
        $job = (new SendWelcomeEmail($user->id))->withFakeQueueInteractions();
        $job->handle();
        $job->assertReleased(3600);
        Mail::assertNothingSent();
        $this->assertTrue($user->fresh()->welcome_email_pending);
    }
}
