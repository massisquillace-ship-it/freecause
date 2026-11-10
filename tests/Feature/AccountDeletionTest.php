<?php

namespace Tests\Feature;

use App\Jobs\CleanupDeletedAccount;
use App\Models\AccountDeletionCleanup;
use App\Models\User;
use App\Models\UserLevel;
use App\Services\OrganizationLifecycle;
use App\Services\WordPressUserDeletionService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);

        Queue::fake();
        $wordPressDeletion = Mockery::mock(WordPressUserDeletionService::class);
        $wordPressDeletion->shouldReceive('delete')->byDefault();
        $this->app->instance(WordPressUserDeletionService::class, $wordPressDeletion);
    }

    public function test_google_only_user_is_offered_google_verification_instead_of_a_deletion_password(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)
            ->get('/en/profile')
            ->assertOk()
            ->assertSee('Verify with Google')
            ->assertDontSee('name="password"', false);
    }

    public function test_google_only_user_cannot_delete_without_fresh_google_verification(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)
            ->post('/en/account/delete', ['confirm_delete' => '1'])
            ->assertSessionHasErrors('google_delete');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_google_only_user_can_delete_with_fresh_single_use_google_verification(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)
            ->withSession(['account_delete_google_verified' => [
                'user_id' => $user->id,
                'verified_at' => now()->timestamp,
            ]])
            ->post('/en/account/delete', ['confirm_delete' => '1'])
            ->assertRedirect('/en');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_expired_google_verification_is_rejected_and_consumed(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)
            ->withSession(['account_delete_google_verified' => [
                'user_id' => $user->id,
                'verified_at' => now()->subMinutes(6)->timestamp,
            ]])
            ->post('/en/account/delete', ['confirm_delete' => '1'])
            ->assertSessionHasErrors('google_delete')
            ->assertSessionMissing('account_delete_google_verified');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_password_user_still_needs_the_correct_password_to_delete(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
            'password_changed_at' => now(),
            'google_id' => null,
        ]);

        $this->actingAs($user)
            ->post('/en/account/delete', [
                'password' => 'wrong-password',
                'confirm_delete' => '1',
            ])
            ->assertSessionHasErrors('password');

        $this->actingAs($user)
            ->post('/en/account/delete', [
                'password' => 'correct-password',
                'confirm_delete' => '1',
            ])
            ->assertRedirect('/en');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_account_deletion_clears_user_level_counts_cache(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
            'password_changed_at' => now(),
            'google_id' => null,
            'level_id' => UserLevel::where('name', 'user')->value('id'),
        ]);
        Cache::put('level:user_counts', ['user' => 1], 3600);

        $this->actingAs($user)
            ->post('/en/account/delete', [
                'password' => 'correct-password',
                'confirm_delete' => '1',
            ])
            ->assertRedirect('/en');

        $this->assertFalse(Cache::has('level:user_counts'));
    }

    public function test_google_only_user_cannot_unlink_their_only_login_method(): void
    {
        $user = $this->googleOnlyUser();

        $this->actingAs($user)
            ->post('/en/account/unlink/google')
            ->assertSessionHasErrors('google_unlink');

        $this->assertSame('google-account-id', $user->fresh()->google_id);
    }

    public function test_wordpress_deletion_failure_leaves_local_deletion_committed_and_pending(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
            'password_changed_at' => now(),
            'google_id' => null,
        ]);

        $wordPressDeletion = Mockery::mock(WordPressUserDeletionService::class);
        $wordPressDeletion->shouldReceive('delete')
            ->andThrow(new RuntimeException('Synthetic WordPress failure'));
        $this->app->instance(WordPressUserDeletionService::class, $wordPressDeletion);

        $this->actingAs($user)
            ->post('/en/account/delete', [
                'password' => 'correct-password',
                'confirm_delete' => '1',
            ])
            ->assertRedirect('/en');

        $cleanup = AccountDeletionCleanup::where('user_id', $user->id)->firstOrFail();
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);

        try {
            (new CleanupDeletedAccount($cleanup->id))->handle($wordPressDeletion);
        } catch (RuntimeException) {
        }

        $this->assertSame('Synthetic WordPress failure', $cleanup->fresh()->last_error);
    }

    public function test_local_failure_does_not_record_cleanup_or_delete_user(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
            'password_changed_at' => now(),
            'google_id' => null,
        ]);
        $lifecycle = Mockery::mock(OrganizationLifecycle::class);
        $lifecycle->shouldReceive('suspendForDeletedOwner')->once()->andThrow(new RuntimeException('Synthetic local failure'));
        $this->app->instance(OrganizationLifecycle::class, $lifecycle);

        $this->actingAs($user)
            ->from('/en/profile')
            ->post('/en/account/delete', [
                'password' => 'correct-password',
                'confirm_delete' => '1',
            ])
            ->assertRedirect('/en/profile')
            ->assertSessionHasErrors('delete_account');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('account_deletion_cleanups', ['user_id' => $user->id]);
    }

    public function test_successful_deletion_queues_cleanup_after_local_commit(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('correct-password'),
            'password_changed_at' => now(),
            'google_id' => null,
        ]);

        $this->actingAs($user)
            ->post('/en/account/delete', [
                'password' => 'correct-password',
                'confirm_delete' => '1',
            ])
            ->assertRedirect('/en');

        $cleanup = AccountDeletionCleanup::where('user_id', $user->id)->firstOrFail();
        Queue::assertPushed(CleanupDeletedAccount::class, fn ($job) => $job->cleanupId === $cleanup->id);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_cleanup_retries_after_wordpress_failure_and_completes(): void
    {
        $cleanup = AccountDeletionCleanup::create([
            'user_id' => 999,
            'email' => 'deleted@example.com',
        ]);
        $wordPressDeletion = Mockery::mock(WordPressUserDeletionService::class);
        $wordPressDeletion->shouldReceive('delete')
            ->once()
            ->andThrow(new RuntimeException('Temporary WordPress failure'));
        $wordPressDeletion->shouldReceive('delete')->once();

        try {
            (new CleanupDeletedAccount($cleanup->id))->handle($wordPressDeletion);
        } catch (RuntimeException) {
        }
        (new CleanupDeletedAccount($cleanup->id))->handle($wordPressDeletion);

        $this->assertDatabaseMissing('account_deletion_cleanups', ['id' => $cleanup->id]);
    }

    public function test_cleanup_skips_wordpress_deletion_when_email_belongs_to_a_user(): void
    {
        $user = User::factory()->create();
        $cleanup = AccountDeletionCleanup::create([
            'user_id' => 999,
            'email' => $user->email,
        ]);
        $wordPressDeletion = Mockery::mock(WordPressUserDeletionService::class);
        $wordPressDeletion->shouldNotReceive('delete');

        (new CleanupDeletedAccount($cleanup->id))->handle($wordPressDeletion);

        $this->assertDatabaseMissing('account_deletion_cleanups', ['id' => $cleanup->id]);
    }

    public function test_cleanup_job_is_dispatched_only_once_per_cleanup_id(): void
    {
        Queue::fake();
        $id = 123;

        CleanupDeletedAccount::dispatch($id);
        CleanupDeletedAccount::dispatch($id);

        Queue::assertPushed(CleanupDeletedAccount::class, 1);
    }

    private function googleOnlyUser(): User
    {
        return User::factory()->create([
            'google_id' => 'google-account-id',
            'password' => Hash::make('unknown-random-password'),
            'password_changed_at' => null,
            'verified' => true,
        ]);
    }
}
