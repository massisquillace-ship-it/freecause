<?php

namespace Tests\Feature;

use App\Mail\VerifyAccountMail;
use App\Models\User;
use App\Models\UserLevel;
use App\Services\WordPressUserDeletionService;
use App\Support\MailQuota;
use App\Support\Settings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProfileCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
    }

    private function passwordUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'google_id' => null,
            'password' => Hash::make('correct-current'),
            'password_changed_at' => now(),
            'verified' => true,
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'locale' => 'en_US',
        ], $overrides));
    }

    public function test_profile_page_renders_for_a_password_user(): void
    {
        $this->actingAs($this->passwordUser())
            ->get('/en/profile')
            ->assertOk();
    }

    public function test_profile_page_renders_for_a_google_only_user(): void
    {
        $user = $this->passwordUser([
            'google_id' => 'google-account-id',
            'password' => Hash::make('unknown'),
            'password_changed_at' => null,
        ]);

        $this->actingAs($user)
            ->get('/en/profile')
            ->assertOk();
    }

    public function test_profile_page_renders_for_a_user_not_connected_to_google(): void
    {
        $this->actingAs($this->passwordUser(['google_id' => null]))
            ->get('/en/profile')
            ->assertOk();
    }

    public function test_user_can_update_core_profile_fields(): void
    {
        $user = $this->passwordUser();

        $this->actingAs($user)
            ->post('/en/profile', [
                'first_name' => 'Grace',
                'last_name' => 'Hopper',
                'nickname' => 'amazinggrace',
                'city' => 'New York',
                'country' => 'US',
                'gender' => 'female',
                'locale' => 'en',
                'identify_mode' => 'nick',
            ])
            ->assertRedirect('/en/profile')
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Grace', $user->first_name);
        $this->assertSame('Hopper', $user->last_name);
        $this->assertSame('amazinggrace', $user->nickname);
        $this->assertSame('New York', $user->city);
        $this->assertSame('US', $user->country);
        $this->assertSame('female', $user->gender);
        $this->assertSame('nick', $user->identify_mode);
    }

    public function test_profile_update_requires_first_and_last_name(): void
    {
        $this->actingAs($this->passwordUser())
            ->from('/en/profile')
            ->post('/en/profile', [
                'first_name' => '',
                'last_name' => '',
                'locale' => 'en',
            ])
            ->assertRedirect('/en/profile')
            ->assertSessionHasErrors(['first_name', 'last_name']);
    }

    public function test_profile_update_rejects_nick_mode_without_a_nickname(): void
    {
        $this->actingAs($this->passwordUser(['nickname' => null]))
            ->from('/en/profile')
            ->post('/en/profile', [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'nickname' => '',
                'country' => 'US',
                'gender' => 'female',
                'locale' => 'en',
                'identify_mode' => 'nick',
            ])
            ->assertRedirect('/en/profile')
            ->assertSessionHasErrors('nickname');
    }

    public function test_profile_update_rejects_an_invalid_country(): void
    {
        $this->actingAs($this->passwordUser())
            ->from('/en/profile')
            ->post('/en/profile', [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'country' => 'ZZ',
                'locale' => 'en',
            ])
            ->assertRedirect('/en/profile')
            ->assertSessionHasErrors('country');
    }

    public function test_profile_update_rejects_an_inactive_or_unknown_locale(): void
    {
        $this->actingAs($this->passwordUser())
            ->from('/en/profile')
            ->post('/en/profile', [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'locale' => 'zz',
            ])
            ->assertRedirect('/en/profile')
            ->assertSessionHasErrors('locale');
    }

    public function test_email_change_is_rejected_when_smtp_is_disabled(): void
    {
        $user = $this->passwordUser();

        $this->actingAs($user)
            ->from('/en/profile')
            ->post('/en/profile', [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'country' => 'US',
                'gender' => 'male',
                'locale' => 'en',
                'new_email' => 'new-address@example.com',
                'new_email_confirmation' => 'new-address@example.com',
                'current_password' => 'correct-current',
            ])
            ->assertRedirect('/en/profile')
            ->assertSessionHasErrors('new_email');

        $this->assertNotSame('new-address@example.com', $user->fresh()->email);
    }

    public function test_email_change_rejects_an_address_already_taken(): void
    {
        $taken = $this->passwordUser(['first_name' => 'Taken', 'last_name' => 'User']);
        $user = $this->passwordUser();

        $this->actingAs($user)
            ->from('/en/profile')
            ->post('/en/profile', [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'country' => 'US',
                'gender' => 'male',
                'locale' => 'en',
                'new_email' => $taken->email,
                'new_email_confirmation' => $taken->email,
                'current_password' => 'correct-current',
            ])
            ->assertRedirect('/en/profile')
            ->assertSessionHasErrors('new_email');
    }

    public function test_email_change_succeeds_and_queues_verification_when_smtp_enabled(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true);

        $user = $this->passwordUser();

        $this->actingAs($user)
            ->post('/en/profile', [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'country' => 'US',
                'gender' => 'male',
                'locale' => 'en',
                'new_email' => 'fresh-address@example.com',
                'new_email_confirmation' => 'fresh-address@example.com',
                'current_password' => 'correct-current',
            ])
            ->assertRedirect('/en/profile')
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertNotSame('fresh-address@example.com', $user->email);
        $this->assertSame('fresh-address@example.com', $user->pending_email);
        $this->assertTrue((bool) $user->verified);
        Mail::assertQueued(VerifyAccountMail::class, fn ($mail) => $mail->hasTo('fresh-address@example.com'));
    }

    private function requestEmailChange(User $user, string $email)
    {
        return $this->actingAs($user)
            ->from('/en/profile')
            ->post('/en/profile', [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'country' => 'US',
                'gender' => 'male',
                'locale' => 'en',
                'new_email' => $email,
                'new_email_confirmation' => $email,
                'current_password' => 'correct-current',
            ]);
    }

    public function test_verify_link_moves_pending_email_into_email(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true);

        $user = $this->passwordUser();
        $this->requestEmailChange($user, 'moved@example.com')->assertSessionHasNoErrors();

        $this->post('/en/verify/'.$user->fresh()->verification_token)->assertRedirect('/en');

        $user->refresh();
        $this->assertSame('moved@example.com', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNull($user->verification_token);
        $this->assertTrue((bool) $user->verified);
    }

    public function test_verify_link_deletes_the_wordpress_user_of_the_old_email_once(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true);

        $wordPress = Mockery::mock(WordPressUserDeletionService::class);
        $wordPress->shouldReceive('delete')->once()->withArgs(fn (User $u) => $u->email === 'old-address@example.com');
        $this->app->instance(WordPressUserDeletionService::class, $wordPress);

        $user = $this->passwordUser(['email' => 'old-address@example.com']);
        $this->requestEmailChange($user, 'moved@example.com')->assertSessionHasNoErrors();

        $this->post('/en/verify/'.$user->fresh()->verification_token)->assertRedirect('/en');

        $this->assertSame('moved@example.com', $user->fresh()->email);
    }

    public function test_wordpress_cleanup_failure_does_not_block_the_email_swap(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true);

        $wordPress = Mockery::mock(WordPressUserDeletionService::class);
        $wordPress->shouldReceive('delete')->once()->andThrow(new RuntimeException('boom'));
        $this->app->instance(WordPressUserDeletionService::class, $wordPress);

        $user = $this->passwordUser();
        $this->requestEmailChange($user, 'moved@example.com')->assertSessionHasNoErrors();

        $this->post('/en/verify/'.$user->fresh()->verification_token)->assertRedirect('/en');

        $this->assertSame('moved@example.com', $user->fresh()->email);
    }

    public function test_pending_email_cannot_be_registered_or_claimed_by_another_account(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true);

        $other = $this->passwordUser();
        $this->requestEmailChange($this->passwordUser(), 'claimed@example.com')->assertSessionHasNoErrors();

        $this->flushSession();
        $this->requestEmailChange($other, 'claimed@example.com')->assertSessionHasErrors('new_email');
        $this->assertNull($other->fresh()->pending_email);

        auth()->logout();
        $this->post('/en/register', [
            'name' => 'Other',
            'surname' => 'User',
            'email' => 'claimed@example.com',
            'password' => 'password123',
            'agree_terms' => 'on',
        ])->assertSessionHasErrors('email');
        $this->assertSame(0, User::where('email', 'claimed@example.com')->count());
    }

    public function test_resend_verification_clears_pending_email_and_voids_the_old_link(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true);

        $user = $this->passwordUser(['verified' => false, 'email' => 'old@example.com']);
        $this->requestEmailChange($user, 'pending@example.com')->assertSessionHasNoErrors();
        $oldToken = $user->fresh()->verification_token;
        $this->assertSame('pending@example.com', $user->fresh()->pending_email);

        $user->forceFill(['verification_token_sent_at' => now()->subMinutes(11)])->save();

        auth()->logout();
        $this->flushSession();
        $this->post('/en/resend-verification', ['email' => 'old@example.com'])->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertNull($user->pending_email);
        $this->assertNotSame($oldToken, $user->verification_token);

        $this->post("/en/verify/{$oldToken}", []);
        $this->assertSame('old@example.com', $user->fresh()->email);
    }

    public function test_expired_pending_link_clears_pending_email_without_swapping(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true);

        $user = $this->passwordUser(['email' => 'kept@example.com']);
        $this->requestEmailChange($user, 'late@example.com')->assertSessionHasNoErrors();
        $token = $user->fresh()->verification_token;

        $this->travel(49)->hours();
        $this->post("/en/verify/{$token}", [])->assertRedirect('/en/profile');

        $user->refresh();
        $this->assertSame('kept@example.com', $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNull($user->verification_token);
    }

    public function test_pending_email_can_be_registered_after_the_link_expires(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true);

        $this->requestEmailChange($this->passwordUser(), 'released@example.com')->assertSessionHasNoErrors();
        auth()->logout();
        $this->flushSession();

        $this->travel(49)->hours();
        $this->post('/en/register', [
            'name' => 'Other',
            'surname' => 'User',
            'email' => 'released@example.com',
            'password' => 'password123',
            'agree_terms' => 'on',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, User::where('email', 'released@example.com')->count());
    }

    public function test_admin_email_change_clears_pending_email(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true, 'global');

        $admin = User::factory()->create(['level_id' => UserLevel::where('name', 'admin')->value('id'), 'verified' => true]);
        // Unverified, so only the email change (not reverification) can clear it.
        $target = $this->passwordUser([
            'verified' => false,
            'pending_email' => 'stale@example.com',
            'verification_token' => str_repeat('p', 64),
            'verification_token_sent_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/users/save', ['id' => $target->id, 'email' => 'admin-set@example.com'])
            ->assertSessionHasNoErrors();

        $target->refresh();
        $this->assertSame('admin-set@example.com', $target->email);
        $this->assertNull($target->pending_email);
    }

    public function test_email_change_is_rejected_while_the_mail_quota_is_tripped(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true);
        MailQuota::trip();

        $user = $this->passwordUser();
        $this->requestEmailChange($user, 'quota@example.com')->assertSessionHasErrors('new_email');

        $this->assertNull($user->fresh()->pending_email);
        Mail::assertNothingQueued();
    }

    public function test_mixed_case_new_email_is_stored_lowercase(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true);

        $user = $this->passwordUser();
        $this->requestEmailChange($user, '  Mixed.Case@Example.COM ')->assertSessionHasNoErrors();

        $this->assertSame('mixed.case@example.com', $user->fresh()->pending_email);
    }

    public function test_verify_does_not_swap_an_address_taken_in_the_meantime(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true);

        $user = $this->passwordUser();
        $originalEmail = $user->email;
        $this->requestEmailChange($user, 'raced@example.com')->assertSessionHasNoErrors();

        // e.g. a Google sign-up or guest signature created the account directly.
        $this->passwordUser(['email' => 'raced@example.com']);

        $this->post('/en/verify/'.$user->fresh()->verification_token)->assertRedirect('/en');

        $user->refresh();
        $this->assertSame($originalEmail, $user->email);
        $this->assertNull($user->pending_email);
        $this->assertNull($user->verification_token);
    }

    public function test_user_with_a_password_can_disconnect_google(): void
    {
        $user = $this->passwordUser(['google_id' => 'google-account-id']);

        $this->actingAs($user)
            ->post('/en/account/unlink/google')
            ->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->google_id);
    }

    public function test_resubmitting_the_same_email_does_not_trigger_a_change(): void
    {
        $user = $this->passwordUser(['email' => 'stable@example.com']);

        $this->actingAs($user)
            ->post('/en/profile', [
                'first_name' => 'Ada',
                'last_name' => 'Lovelace',
                'country' => 'US',
                'gender' => 'male',
                'locale' => 'en',
                'new_email' => 'stable@example.com',
                'new_email_confirmation' => 'stable@example.com',
                'current_password' => 'correct-current',
            ])
            ->assertRedirect('/en/profile')
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) $user->fresh()->verified);
    }

    public function test_email_change_requires_current_password_for_a_password_user(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true);

        $user = $this->passwordUser();
        $payload = [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'country' => 'US',
            'gender' => 'male',
            'locale' => 'en',
            'new_email' => 'guarded@example.com',
            'new_email_confirmation' => 'guarded@example.com',
        ];

        $this->actingAs($user)->from('/en/profile')->post('/en/profile', $payload)
            ->assertSessionHasErrors('current_password');
        $this->assertNull($user->fresh()->pending_email);

        $this->actingAs($user)->from('/en/profile')->post('/en/profile', $payload + ['current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        $this->assertNull($user->fresh()->pending_email);

        $this->actingAs($user)->post('/en/profile', $payload + ['current_password' => 'correct-current'])
            ->assertSessionHasNoErrors();
        $this->assertSame('guarded@example.com', $user->fresh()->pending_email);
    }

    public function test_google_only_user_cannot_set_a_new_email(): void
    {
        Mail::fake();
        Settings::set('smtp_enabled', true);

        $user = $this->passwordUser([
            'google_id' => 'google-account-id',
            'password' => Hash::make('unknown'),
            'password_changed_at' => null,
        ]);

        $this->requestEmailChange($user, 'google-swap@example.com')->assertSessionHasErrors('new_email');

        $this->assertNull($user->fresh()->pending_email);
        Mail::assertNothingQueued();
    }

    public function test_google_only_user_can_save_profile_without_or_with_unchanged_email(): void
    {
        $user = $this->passwordUser([
            'google_id' => 'google-account-id',
            'password' => Hash::make('unknown'),
            'password_changed_at' => null,
        ]);
        $payload = [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'country' => 'US',
            'gender' => 'male',
            'locale' => 'en',
        ];

        $this->actingAs($user)->from('/en/profile')->post('/en/profile', $payload)
            ->assertSessionHasNoErrors();

        $same = strtoupper($user->email);
        $this->actingAs($user)->from('/en/profile')->post('/en/profile', $payload + ['new_email' => $same, 'new_email_confirmation' => $same])
            ->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->pending_email);
    }
}
