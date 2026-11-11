<?php

namespace Tests\Feature;

use App\Mail\VerifyAccountMail;
use App\Models\Language;
use App\Models\Petition;
use App\Models\Signature;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\Countries;
use App\Support\Settings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\HashManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Laravel\Socialite\Facades\Socialite;
use Mockery;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
    }

    public function test_login_page_loads(): void
    {
        $response = $this->get('/en/login');
        $response->assertStatus(200);
    }

    public function test_guest_intended_url_reaches_sso_as_path(): void
    {
        config(['app.sso_secret' => 'test-secret']);
        $user = User::factory()->create([
            'verified' => true,
            'password' => Hash::make('secret-pass-1'),
            'locale' => 'en_US',
            'country' => 'IT',
            'gender' => 'prefer_not_to_say',
            'welcome_email_pending' => false,
        ]);

        $this->get('/en/my-petitions')->assertRedirect();

        $response = $this->post('/en/login', ['email' => $user->email, 'password' => 'secret-pass-1']);

        $location = $response->headers->get('Location');
        $this->assertSame('/magazine/sso.php', parse_url($location, PHP_URL_PATH));
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('/en/my-petitions', $query['r']);
    }

    public function test_login_page_renders_sized_google_button(): void
    {
        $this->get('/en/login')
            ->assertOk()
            ->assertSee('legacy/images-v2/google-g.svg', false)
            ->assertSee('fc-google-btn fc-oauth-btn', false)
            ->assertSee('width="18" height="18"', false)
            ->assertDontSee('legacy/images-v2/google.png', false);
    }

    public function test_register_page_renders_sized_google_button(): void
    {
        $this->get('/en/register')
            ->assertOk()
            ->assertSee('legacy/images-v2/google-g.svg', false)
            ->assertSee('fc-google-btn fc-oauth-btn', false)
            ->assertSee('width="18" height="18"', false)
            ->assertDontSee('legacy/images-v2/google.png', false);
    }

    public function test_google_oauth_keeps_environment_credentials_when_database_settings_are_empty(): void
    {
        config()->set('services.google.client_id', 'environment-client-id');
        config()->set('services.google.client_secret', 'environment-client-secret');
        Settings::set('google_client_id', '');
        Settings::set('google_client_secret', '');

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('environment-client-id', config('services.google.client_id'));
        $this->assertSame('environment-client-secret', config('services.google.client_secret'));
    }

    public function test_google_oauth_stays_on_site_when_credentials_are_missing(): void
    {
        config()->set('services.google.client_id');
        config()->set('services.google.client_secret');

        $this->from('/en/login')
            ->get('/en/oauth/google?flow=login')
            ->assertRedirect('/en/login')
            ->assertSessionHasErrors('oauth');
    }

    public function test_google_oauth_preserves_embedded_return_path(): void
    {
        config()->set('services.google.client_id', 'client-id');
        config()->set('services.google.client_secret', 'client-secret');
        config()->set('services.google.redirect', 'https://www.freecause.com/en/oauth/google/callback');

        $driver = Mockery::mock();
        $driver->shouldReceive('redirect')
            ->once()
            ->andReturn(redirect()->away('https://accounts.google.test/oauth'));
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $this->get('/en/oauth/google?flow=register&redirect=%2Fen%2Fcreate-petition')
            ->assertRedirect('https://accounts.google.test/oauth')
            ->assertSessionHas('oauth_ctx', [
                'flow' => 'register',
                'locale' => 'en',
                'redirect' => '/en/create-petition',
            ])
            ->assertSessionMissing('oauth_state');
    }

    public function test_google_oauth_callback_returns_to_embedded_page(): void
    {
        $googleUser = Mockery::mock();
        $googleUser->user = ['email_verified' => true];
        $googleUser->shouldReceive('getEmail')->once()->andReturn('google@example.com');
        $googleUser->shouldReceive('getName')->once()->andReturn('Google User');
        $googleUser->shouldReceive('getId')->atLeast()->once()->andReturn('google-user-id');

        $driver = Mockery::mock();
        $driver->shouldReceive('user')->once()->andReturn($googleUser);
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $this->withSession([
            'oauth_ctx' => [
                'flow' => 'register',
                'locale' => 'en',
                'redirect' => '/en/create-petition',
            ],
        ])->get('/en/oauth/google/callback')
            ->assertRedirect('/en/profile?return=%2Fen%2Fcreate-petition');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'google@example.com',
            'google_id' => 'google-user-id',
            'verified' => true,
        ]);
        $this->assertNull(User::where('email', 'google@example.com')->value('password'));
    }

    public function test_google_oauth_rejects_external_return_path(): void
    {
        config()->set('services.google.client_id', 'client-id');
        config()->set('services.google.client_secret', 'client-secret');
        config()->set('services.google.redirect', 'https://www.freecause.com/en/oauth/google/callback');

        $driver = Mockery::mock();
        $driver->shouldReceive('redirect')
            ->once()
            ->andReturn(redirect()->away('https://accounts.google.test/oauth'));
        Socialite::shouldReceive('driver')->once()->with('google')->andReturn($driver);

        $this->get('/en/oauth/google?flow=login&redirect=https%3A%2F%2Fevil.test')
            ->assertSessionHas('oauth_ctx', [
                'flow' => 'login',
                'locale' => 'en',
                'redirect' => '',
            ])
            ->assertSessionMissing('oauth_state');
    }

    public function test_authenticated_user_cannot_reopen_auth_pages(): void
    {
        $user = User::factory()->create(['verified' => true]);

        $this->actingAs($user)->get('/en/login')->assertRedirect('/en');
        $this->actingAs($user)->get('/en/register')->assertRedirect('/en');
    }

    public function test_user_can_login_with_email(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'verified' => true,
        ]);

        $response = $this->post('/en/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/en');
        $this->assertAuthenticatedAs($user);
    }

    public function test_banned_user_with_wrong_password_gets_invalid_credentials_response(): void
    {
        $user = User::factory()->create([
            'email' => 'banned@example.com',
            'password' => Hash::make('password123'),
            'banned_until' => now()->addDay(),
        ]);

        $response = $this->post('/en/login', [
            'email' => $user->email,
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors(['email' => __('auth.err_invalid_credentials')]);
        $response->assertSessionMissing('toast');
        $this->assertGuest();
    }

    public function test_banned_user_with_correct_password_gets_suspended_response_without_login(): void
    {
        $user = User::factory()->create([
            'email' => 'banned@example.com',
            'password' => Hash::make('password123'),
            'banned_until' => now()->addDay(),
        ]);

        $response = $this->from('/en/login')->post('/en/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertRedirect('/en/login');
        $response->assertSessionHas('toast', [
            'type' => 'error',
            'message' => __('auth.err_suspended'),
        ]);
        $this->assertGuest();
    }

    public function test_successful_password_login_backfills_password_timestamp_for_older_google_linked_user(): void
    {
        $user = User::factory()->create([
            'email' => 'older-linked@example.com',
            'password' => Hash::make('password123'),
            'password_changed_at' => null,
            'google_id' => 'older-google-id',
            'verified' => true,
        ]);

        $this->post('/en/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertRedirect('/en');

        $user->refresh();
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue($user->hasLocalPassword());
    }

    public function test_embedded_login_returns_to_create_page(): void
    {
        $user = User::factory()->create([
            'email' => 'creator@example.com',
            'password' => Hash::make('password123'),
            'verified' => true,
        ]);

        $this->post('/en/login', [
            'email' => $user->email,
            'password' => 'password123',
            'redirect' => '/en/create-petition',
        ])->assertRedirect('/en/create-petition');
    }

    public function test_password_login_uses_saved_preferred_language_without_explicit_destination(): void
    {
        $user = User::factory()->create([
            'email' => 'preferred-language@example.com',
            'password' => Hash::make('password123'),
            'locale' => 'fr_FR',
            'verified' => true,
        ]);

        $this->post('/en/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertRedirect('/fr');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_normalizes_email_case_and_spaces(): void
    {
        $user = User::factory()->create([
            'email' => 'normalized@example.com',
            'password' => bcrypt('password123'),
            'verified' => true,
        ]);

        $this->post('/en/login', [
            'email' => '  NORMALIZED@EXAMPLE.COM ',
            'password' => 'password123',
        ])->assertRedirect('/en');

        $this->assertAuthenticatedAs($user);
    }

    public function test_migrated_legacy_user_can_login_and_password_is_rehashed(): void
    {
        $salt = 'legacy-salt';
        $password = 'password123';
        $user = User::factory()->create([
            'email' => 'legacy@example.com',
            'password' => md5(md5($password).$salt).':'.$salt,
            'verified' => true,
        ]);

        $this->post('/en/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertRedirect('/en');

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(password_verify($password, $user->fresh()->password));
    }

    public function test_login_requires_an_email_address(): void
    {
        $response = $this->post('/en/login', [
            'email' => 'testuser',
            'password' => 'password123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post('/en/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_with_unknown_email_runs_one_hash_check_and_fails_like_wrong_password(): void
    {
        User::factory()->create([
            'email' => 'known@example.com',
            'password' => bcrypt('password123'),
        ]);

        $hasher = new class($this->app) extends HashManager
        {
            public int $checks = 0;

            public function check($value, $hashedValue, array $options = [])
            {
                $this->checks++;

                return parent::check($value, $hashedValue, $options);
            }
        };
        Hash::swap($hasher);

        $unknown = $this->post('/en/login', [
            'email' => 'unknown@example.com',
            'password' => 'wrongpassword',
        ]);

        $unknown->assertSessionHasErrors(['email' => __('auth.err_invalid_credentials')]);
        $this->assertGuest();
        $this->assertSame(1, $hasher->checks);

        $known = $this->post('/en/login', [
            'email' => 'known@example.com',
            'password' => 'wrongpassword',
        ]);

        $known->assertSessionHasErrors(['email' => __('auth.err_invalid_credentials')]);
    }

    public function test_embedded_login_error_appears_once_on_create_page(): void
    {
        User::factory()->create([
            'email' => 'embedded@example.com',
            'password' => Hash::make('correct-password'),
            'verified' => true,
        ]);

        $this->post('/en/login', [
            'email' => 'embedded@example.com',
            'password' => 'wrong-password',
            'auth_form' => 'login',
            'redirect' => '/en/create-petition',
        ]);

        $response = $this->get('/en/create-petition');

        $response->assertOk();
        $this->assertSame(1, substr_count($response->getContent(), __('auth.err_invalid_credentials')));
    }

    public function test_login_fails_for_unverified_user(): void
    {
        User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'verified' => false,
        ]);

        $response = $this->post('/en/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_unverified_login_lands_on_the_verification_notice(): void
    {
        User::factory()->create([
            'email' => 'pending@example.com',
            'password' => bcrypt('password123'),
            'verified' => false,
        ]);

        $this->post('/en/login', [
            'email' => 'pending@example.com',
            'password' => 'password123',
        ])->assertRedirect('/en/verify-email');

        $this->get('/en/verify-email')
            ->assertOk()
            ->assertSee('p••••••@example.com')
            ->assertSee(__('auth.verify_notice_heading'));
    }

    public function test_register_then_verify_in_the_same_session_auto_logs_in(): void
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

        $this->post('/en/verify/'.$this->knownVerificationToken($user), [])
            ->assertRedirectContains('/en/profile');

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertTrue((bool) $user->fresh()->verified);
    }

    public function test_registration_rejects_failed_turnstile_check(): void
    {
        Settings::set('turnstile_enabled', true, 'global');
        Settings::set('turnstile_site_key', 'site-key', 'global');
        Settings::set('turnstile_secret', 'secret-key', 'global');
        Settings::set('require_email_verification', true);
        Settings::set('smtp_enabled', true);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false], 200)]);
        Mail::fake();

        $this->post('/en/register', [
            'name' => 'Captcha',
            'surname' => 'Rejected',
            'email' => 'captcha-rejected@example.com',
            'password' => 'password123',
            'agree_terms' => 'on',
            'cf-turnstile-response' => 'failed-token',
        ]);

        $this->assertDatabaseMissing('users', ['email' => 'captcha-rejected@example.com']);
        Mail::assertNothingQueued();
    }

    public function test_register_stops_after_per_email_cap(): void
    {
        Settings::set('require_email_verification', true);
        Settings::set('smtp_enabled', true);
        Mail::fake();

        $payload = [
            'name' => 'Capped',
            'surname' => 'User',
            'email' => 'capped@example.com',
            'password' => 'password123',
            'agree_terms' => 'on',
        ];

        $this->post('/en/register', $payload)->assertRedirect('/en/verify-email');
        $this->post('/en/register', $payload);
        $this->post('/en/register', $payload);
        $this->post('/en/register', $payload)->assertSessionHasErrors(['email' => 'Too many attempts. Please try again in a minute.']);

        Mail::assertQueued(VerifyAccountMail::class, 1);
        $this->assertSame(1, User::where('email', 'capped@example.com')->count());
    }

    public function test_verify_link_in_a_fresh_session_lands_on_the_verified_page(): void
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
        $token = $this->knownVerificationToken($user);

        $this->flushSession();

        $this->post("/en/verify/{$token}", [])
            ->assertRedirect('/en/email-verified');

        $this->assertGuest();
    }

    public function test_verification_token_is_stored_hashed_and_the_emailed_link_still_works(): void
    {
        Settings::set('require_email_verification', true);
        Settings::set('smtp_enabled', true);
        Mail::fake();

        $this->post('/en/register', [
            'name' => 'Hashed',
            'surname' => 'User',
            'email' => 'hashed@example.com',
            'password' => 'password123',
            'agree_terms' => 'on',
        ])->assertRedirect('/en/verify-email');

        $user = User::where('email', 'hashed@example.com')->firstOrFail();
        $emailed = null;
        Mail::assertQueued(VerifyAccountMail::class, function (VerifyAccountMail $mail) use (&$emailed) {
            $emailed = $mail->verificationToken;

            return true;
        });

        $this->assertNotSame($emailed, $user->verification_token);
        $this->assertSame(hash('sha256', $emailed), $user->verification_token);

        $this->flushSession();
        $this->post('/en/verify/'.$user->verification_token)->assertRedirect('/en');
        $this->assertFalse((bool) $user->fresh()->verified);

        $this->post('/en/verify/'.$emailed)->assertRedirect('/en/email-verified');
        $this->assertTrue((bool) $user->fresh()->verified);
    }

    public function test_verification_get_is_passive_and_post_consumes_token_once(): void
    {
        $user = User::factory()->create([
            'verified' => false,
            'verification_token' => User::hashVerificationToken('passive-token'),
            'verification_token_sent_at' => now(),
        ]);

        $this->get('/en/verify/passive-token')
            ->assertOk()
            ->assertSee(__('auth.verify_confirm_heading'));

        $this->assertFalse((bool) $user->fresh()->verified);
        $this->assertSame(User::hashVerificationToken('passive-token'), $user->fresh()->verification_token);

        $this->post('/en/verify/passive-token')
            ->assertRedirect('/en/email-verified');

        $this->assertTrue((bool) $user->fresh()->verified);
        $this->assertNull($user->fresh()->verification_token);

        $this->post('/en/verify/passive-token')
            ->assertRedirect('/en');
    }

    public function test_password_reset_auto_logs_in_and_returns_to_the_intended_url(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => bcrypt('old-password-123'),
            'verified' => true,
            'country' => 'NL',
            'gender' => 'female',
        ]);

        $token = Password::createToken($user);

        $this->post('/en/reset-password', [
            'token' => $token,
            'email' => 'reset@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
            'redirect' => '/en/petitions',
        ])->assertRedirect('/en/petitions');

        $this->assertAuthenticatedAs($user->fresh());
        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_password_reset_redirects_through_wordpress_sso(): void
    {
        config(['app.sso_secret' => 'test-secret']);
        $user = User::factory()->create([
            'email' => 'sso-reset@example.com',
            'verified' => true,
            'country' => 'NL',
            'gender' => 'female',
        ]);

        $response = $this->post('/en/reset-password', [
            'token' => Password::createToken($user),
            'email' => 'sso-reset@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
            'redirect' => '/en/petitions',
        ]);

        $location = $response->headers->get('Location');
        $this->assertSame('/magazine/sso.php', parse_url($location, PHP_URL_PATH));
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('/en/petitions', $query['r']);
    }

    public function test_verify_auto_login_redirects_through_wordpress_sso_to_profile_when_incomplete(): void
    {
        config(['app.sso_secret' => 'test-secret']);
        Settings::set('require_email_verification', true);
        Settings::set('smtp_enabled', true);
        Mail::fake();

        $this->post('/en/register', [
            'name' => 'Pending',
            'surname' => 'User',
            'email' => 'sso-verify@example.com',
            'password' => 'password123',
            'agree_terms' => 'on',
        ])->assertRedirect('/en/verify-email');

        $user = User::where('email', 'sso-verify@example.com')->firstOrFail();

        $response = $this->post('/en/verify/'.$this->knownVerificationToken($user), []);

        $location = $response->headers->get('Location');
        $this->assertSame('/magazine/sso.php', parse_url($location, PHP_URL_PATH));
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertStringStartsWith('/en/profile', $query['r']);
    }

    public function test_password_reset_cancels_pending_email_change(): void
    {
        $user = User::factory()->create([
            'email' => 'reset-pending@example.com',
            'password' => bcrypt('old-password-123'),
            'verified' => true,
            'country' => 'NL',
            'gender' => 'female',
        ]);
        $user->pending_email = 'pending-target@example.com';
        $plain = $user->issueVerificationToken();
        $user->save();

        $this->post('/en/reset-password', [
            'token' => Password::createToken($user),
            'email' => 'reset-pending@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect();

        $this->assertNull($user->fresh()->pending_email);
        $this->assertNull($user->fresh()->verification_token);
        $this->assertNull($user->fresh()->verification_token_sent_at);

        $this->post('/en/verify/'.$plain, [])->assertRedirect('/en');

        $user->refresh();
        $this->assertSame('reset-pending@example.com', $user->email);
        $this->assertNull($user->pending_email);
    }

    public function test_password_reset_caps_password_at_72_characters(): void
    {
        $user = User::factory()->create([
            'email' => 'cap-reset@example.com',
            'verified' => true,
            'country' => 'NL',
            'gender' => 'female',
        ]);

        $tooLong = str_repeat('a', 73);
        $this->post('/en/reset-password', [
            'token' => Password::createToken($user),
            'email' => 'cap-reset@example.com',
            'password' => $tooLong,
            'password_confirmation' => $tooLong,
        ])->assertSessionHasErrors('password');

        $max = str_repeat('a', 72);
        $this->post('/en/reset-password', [
            'token' => Password::createToken($user),
            'email' => 'cap-reset@example.com',
            'password' => $max,
            'password_confirmation' => $max,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check($max, $user->fresh()->password));
    }

    public function test_password_reset_verifies_user_and_confirms_pending_signatures(): void
    {
        $user = User::factory()->create([
            'email' => 'unverified-reset@example.com',
            'verified' => false,
            'country' => 'NL',
            'gender' => 'female',
        ]);
        $petition = Petition::factory()->create(['signature_count' => 0]);
        $signature = Signature::factory()->create([
            'petition_id' => $petition->id,
            'user_id' => $user->id,
            'confirmed' => false,
            'is_spam' => false,
        ]);

        $this->post('/en/reset-password', [
            'token' => Password::createToken($user),
            'email' => 'unverified-reset@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect();

        $this->assertTrue($user->fresh()->verified);
        $this->assertDatabaseHas('signatures', ['id' => $signature->id, 'confirmed' => 1]);
        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'signature_count' => 1]);
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_password_reset_does_not_verify_banned_user(): void
    {
        $user = User::factory()->create([
            'email' => 'banned-reset@example.com',
            'verified' => false,
            'banned_until' => now()->addDay(),
        ]);
        $petition = Petition::factory()->create(['signature_count' => 0]);
        $signature = Signature::factory()->create([
            'petition_id' => $petition->id,
            'user_id' => $user->id,
            'confirmed' => false,
            'is_spam' => false,
        ]);

        $this->post('/en/reset-password', [
            'token' => Password::createToken($user),
            'email' => 'banned-reset@example.com',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect();

        $this->assertTrue($user->fresh()->isBanned());
        $this->assertFalse($user->fresh()->verified);
        $this->assertDatabaseHas('signatures', ['id' => $signature->id, 'confirmed' => 0]);
        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'signature_count' => 0]);
    }

    public function test_user_can_register(): void
    {
        $response = $this->post('/en/register', [
            'name' => 'Test User',
            'surname' => 'Example',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'agree_terms' => 'on',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'locale' => 'en_US',
        ]);
    }

    public function test_forgot_password_mail_failure_matches_unknown_email_response(): void
    {
        $user = User::factory()->create(['email' => 'reset-mail-failure@example.com']);

        $unknownResponse = $this->from('/en/forgot-password')
            ->post('/en/forgot-password', ['email' => 'unknown-reset@example.com']);

        $unknownResponse->assertRedirect('/en/forgot-password')
            ->assertSessionHas('status', __('auth.reset_link_sent'));

        Password::shouldReceive('sendResetLink')
            ->once()
            ->andThrow(new \RuntimeException('Synthetic mail outage'));

        $existingResponse = $this->from('/en/forgot-password')
            ->post('/en/forgot-password', ['email' => $user->email]);

        $existingResponse->assertRedirect('/en/forgot-password')
            ->assertSessionHas('status', __('auth.reset_link_sent'));
    }

    public function test_embedded_registration_returns_to_create_page(): void
    {
        Settings::set('require_email_verification', false);

        $this->post('/en/register', [
            'name' => 'Petition',
            'surname' => 'Creator',
            'email' => 'petition.creator@example.com',
            'password' => 'password123',
            'agree_terms' => 'on',
            'redirect' => '/en/create-petition',
        ])->assertRedirect('/en/profile?return=%2Fen%2Fcreate-petition');

        $this->assertAuthenticated();
    }

    public function test_registration_requires_valid_email(): void
    {
        $response = $this->post('/en/register', [
            'name' => 'Test User',
            'surname' => 'Example',
            'email' => 'invalid-email',
            'password' => 'password123',
            'agree_terms' => 'on',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_registration_rejects_existing_email_after_normalization(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $this->post('/en/register', [
            'name' => 'Test',
            'surname' => 'User',
            'email' => ' EXISTING@EXAMPLE.COM ',
            'password' => 'password123',
            'agree_terms' => 'on',
        ])->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'existing@example.com')->count());
    }

    public function test_registration_validates_legacy_nickname_rules(): void
    {
        $this->post('/en/register', [
            'name' => 'Test',
            'surname' => 'User',
            'email' => 'nickname@example.com',
            'password' => 'password123',
            'nickname' => 'invalid nickname',
            'agree_terms' => 'on',
        ])->assertSessionHasErrors('nickname');
    }

    public function test_registration_seeds_identify_mode_from_nickname(): void
    {
        $this->post('/en/register', [
            'name' => 'Nick',
            'surname' => 'Named',
            'email' => 'nicked@example.com',
            'password' => 'password123',
            'nickname' => 'nickname1',
            'agree_terms' => 'on',
        ]);

        $withNick = User::where('email', 'nicked@example.com')->firstOrFail();
        $this->assertSame('nick', $withNick->identify_mode);

        $this->post('/en/register', [
            'name' => 'Plain',
            'surname' => 'User',
            'email' => 'plain@example.com',
            'password' => 'password123',
            'agree_terms' => 'on',
        ]);

        $noNick = User::where('email', 'plain@example.com')->firstOrFail();
        $this->assertSame('full', $noNick->identify_mode);
    }

    public function test_registration_requires_terms_acceptance(): void
    {
        $response = $this->post('/en/register', [
            'name' => 'Test User',
            'surname' => 'Example',
            'email' => 'newuser@example.com',
            'password' => 'password123',
        ]);

        $response->assertSessionHasErrors('agree_terms');
    }

    public function test_registration_starts_verification_token_expiry_clock(): void
    {
        Settings::set('require_email_verification', true);

        $this->post('/en/register', [
            'name' => 'Pending',
            'surname' => 'User',
            'email' => 'pending@example.com',
            'password' => 'password123',
            'agree_terms' => 'on',
        ])->assertRedirect('/en/verify-email');

        $user = User::where('email', 'pending@example.com')->firstOrFail();

        $this->assertFalse($user->verified);
        $this->assertNotNull($user->verification_token);
        $this->assertNotNull($user->verification_token_sent_at);
    }

    public function test_resend_verification_does_not_reveal_account_status(): void
    {
        Settings::set('smtp_enabled', true);
        $verified = User::factory()->create(['verified' => true]);
        $message = 'If the account needs activation, a new email has been sent.';

        $this->from('/en/resend-verification')->post('/en/resend-verification', [
            'email' => 'missing@example.com',
        ])->assertRedirect('/en/resend-verification')->assertSessionHas('success', $message);

        $this->from('/en/resend-verification')->post('/en/resend-verification', [
            'email' => $verified->email,
        ])->assertRedirect('/en/resend-verification')->assertSessionHas('success', $message);
    }

    public function test_resend_verification_rotates_token_and_queues_email(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true);
        $user = User::factory()->create([
            'email' => 'pending@example.com',
            'verified' => false,
            'verification_token' => User::hashVerificationToken('old-token'),
            'verification_token_sent_at' => now()->subDay(),
        ]);

        $this->post('/en/resend-verification', [
            'email' => ' PENDING@EXAMPLE.COM ',
        ])->assertSessionHas('success', 'If the account needs activation, a new email has been sent.');

        $user->refresh();
        $this->assertNotSame(User::hashVerificationToken('old-token'), $user->verification_token);
        $this->assertTrue($user->verification_token_sent_at->isToday());
        Mail::assertQueued(VerifyAccountMail::class);
    }

    public function test_resend_verification_has_ten_minute_cooldown(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true);
        $user = User::factory()->create([
            'email' => 'pending@example.com',
            'verified' => false,
            'verification_token_sent_at' => now()->subDay(),
        ]);
        $message = 'If the account needs activation, a new email has been sent.';

        $this->post('/en/resend-verification', ['email' => 'pending@example.com'])
            ->assertSessionHas('success', $message);
        $token = $user->refresh()->verification_token;

        $this->post('/en/resend-verification', ['email' => 'pending@example.com'])
            ->assertSessionHas('success', $message);

        $this->assertSame($token, $user->refresh()->verification_token);
        Mail::assertQueued(VerifyAccountMail::class, 1);
    }

    public function test_auth_mail_routes_are_limited_per_email_across_ips(): void
    {
        Mail::fake();
        foreach (['/en/forgot-password', '/en/resend-verification'] as $url) {
            foreach (['victim@example.com', 'Victim@Example.com', ' victim@example.com '] as $i => $email) {
                $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.'.$i])
                    ->postJson($url, ['email' => $email])->assertStatus(302);
            }
            $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.9'])
                ->postJson($url, ['email' => 'victim@example.com'])->assertStatus(429);
            $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.10'])
                ->postJson($url, ['email' => 'other@example.com'])->assertStatus(302);
        }
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/en/logout');

        $response->assertRedirect('/en');
        $this->assertGuest();
    }

    public function test_profile_renders_complete_country_and_supported_language_selectors(): void
    {
        $user = User::factory()->languageConfirmed()->create([
            'country' => 'US',
            'locale' => 'en_US',
        ]);

        $this->assertCount(249, Countries::all());

        $this->actingAs($user)
            ->get('/en/profile')
            ->assertOk()
            ->assertSee('id="country"', false)
            ->assertSee('value="US" selected', false)
            ->assertSee('United States')
            ->assertSee('id="gender"', false)
            ->assertSee('value="male"', false)
            ->assertSee('Prefer not to say')
            ->assertSee('id="locale"', false)
            ->assertSee('value="en" selected', false)
            ->assertSee('Preferred language')
            ->assertSee('Danish')
            ->assertSee('Turkish');

        $this->actingAs($user)
            ->get('/da/profile')
            ->assertOk()
            ->assertSee('Foretrukket sprog');
    }

    public function test_user_can_update_country_and_preferred_language(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Profile',
            'last_name' => 'User',
            'country' => null,
            'locale' => 'en',
        ]);

        $this->actingAs($user)
            ->post('/en/profile', [
                'first_name' => 'Profile',
                'last_name' => 'User',
                'identify_mode' => 'full',
                'country' => 'FR',
                'gender' => 'female',
                'locale' => 'fr',
            ])
            ->assertRedirect('/fr/profile');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'country' => 'FR',
            'locale' => 'fr_FR',
        ]);
    }

    public function test_profile_rejects_unknown_country_and_inactive_language(): void
    {
        $user = User::factory()->create([
            'first_name' => 'Profile',
            'last_name' => 'User',
            'country' => 'US',
            'locale' => 'en',
        ]);
        Language::where('code', 'fr')->update(['is_active' => false]);

        $this->actingAs($user)
            ->from('/en/profile')
            ->post('/en/profile', [
                'first_name' => 'Profile',
                'last_name' => 'User',
                'identify_mode' => 'full',
                'country' => 'XX',
                'locale' => 'fr',
            ])
            ->assertRedirect('/en/profile')
            ->assertSessionHasErrors(['country', 'locale']);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'country' => 'US',
            'locale' => 'en',
        ]);
    }

    public function test_login_rate_limited(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/en/login', [
                'email' => 'test@example.com',
                'password' => 'wrongpassword',
            ]);
        }

        $response = $this->post('/en/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors();
    }

    private function failedLoginFrom(string $ip, string $email)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/en/login', [
            'email' => $email,
            'password' => 'wrongpassword',
        ]);
    }

    public function test_login_is_limited_per_email_across_ips(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->failedLoginFrom('10.0.0.'.$i, 'victim@example.com')->assertStatus(302);
        }

        $this->failedLoginFrom('10.0.0.7', 'victim@example.com')->assertStatus(429);
    }

    public function test_login_email_limit_does_not_block_other_emails(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->failedLoginFrom('10.0.1.'.$i, 'a@example.com');
        }

        $this->failedLoginFrom('10.0.1.1', 'b@example.com')->assertStatus(302);
        $this->failedLoginFrom('10.0.1.99', 'b@example.com')->assertStatus(302);
    }

    public function test_second_email_from_same_ip_is_not_blocked_by_first(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->failedLoginFrom('10.0.2.1', 'a@example.com');
        }

        $this->failedLoginFrom('10.0.2.1', 'b@example.com')->assertStatus(302);
    }

    public function test_admin_login_is_limited_per_login_across_ips(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.3.'.$i])
                ->postJson('/admin/login', ['login' => 'boss', 'password' => 'wrongpassword'])
                ->assertStatus(302);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.3.11'])
            ->postJson('/admin/login', ['login' => 'boss', 'password' => 'wrongpassword'])
            ->assertStatus(429);
    }

    public function test_admin_login_with_array_login_does_not_error(): void
    {
        $this->postJson('/admin/login', ['login' => ['x'], 'password' => 'wrongpassword'])
            ->assertStatus(422);
    }

    public function test_empty_email_does_not_share_a_bucket_across_ips(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->failedLoginFrom('10.0.4.'.$i, '')->assertStatus(422);
        }
    }
}
