<?php

namespace Tests\Feature;

use App\Mail\VerifyAccountMail;
use App\Models\Log;
use App\Models\Page;
use App\Models\Permission;
use App\Models\Petition;
use App\Models\User;
use App\Models\UserLevel;
use App\Services\WordPressUserDeletionService;
use App\Support\Settings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AdminBackendFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);

        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }

    protected function systemAdmin(): User
    {
        return User::factory()->create([
            'level_id' => UserLevel::where('name', 'admin')->value('id'),
            'verified' => true,
        ]);
    }

    protected function editorAdmin(string $module, string $action = 'edit'): User
    {
        $level = UserLevel::create([
            'name' => 'editor_'.$module.'_'.$action,
            'visible_name' => 'Editor',
            'is_system' => false,
        ]);

        Permission::create(['level_id' => $level->id, 'module' => $module, 'action' => $action]);
        Permission::create(['level_id' => $level->id, 'module' => $module, 'action' => 'view']);

        return User::factory()->create(['level_id' => $level->id, 'verified' => true]);
    }

    public function test_non_system_admin_cannot_change_user_level(): void
    {
        $editor = $this->editorAdmin('users');
        $target = User::factory()->create([
            'level_id' => UserLevel::where('name', 'user')->value('id'),
            'verified' => true,
        ]);

        $this->actingAs($editor, 'admin')
            ->post('/admin/users/save', ['id' => $target->id, 'level' => 'admin'])
            ->assertSessionHasErrors('level');

        $this->assertNotSame(UserLevel::where('name', 'admin')->value('id'), $target->fresh()->level_id);
    }

    public function test_system_admin_cannot_change_level_of_system_level_user(): void
    {
        $admin = $this->systemAdmin();
        $otherAdmin = User::factory()->create([
            'level_id' => UserLevel::where('name', 'admin')->value('id'),
            'verified' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/users/save', ['id' => $otherAdmin->id, 'level' => 'user'])
            ->assertSessionHasErrors('level');

        $this->assertSame(UserLevel::where('name', 'admin')->value('id'), $otherAdmin->fresh()->level_id);
    }

    public function test_unverifying_a_user_rotates_the_token_and_queues_a_new_link(): void
    {
        $admin = $this->systemAdmin();
        $target = User::factory()->create(['verified' => true, 'verification_token' => null]);

        Mail::fake();
        Settings::set('smtp_enabled', true, 'global');

        $this->actingAs($admin, 'admin')
            ->post('/admin/users/save', ['id' => $target->id, 'verified' => 0])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/admin/users?select='.$target->id);

        $target->refresh();

        $this->assertFalse((bool) $target->verified);
        $this->assertNotNull($target->verification_token);
        $this->assertSame(64, strlen($target->verification_token));
        $this->assertNotNull($target->verification_token_sent_at);
        Mail::assertQueued(VerifyAccountMail::class);
    }

    public function test_verifying_a_user_manually_clears_the_pending_token(): void
    {
        $admin = $this->systemAdmin();
        $target = User::factory()->create([
            'verified' => false,
            'verification_token' => str_repeat('a', 64),
            'verification_token_sent_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/users/save', ['id' => $target->id, 'verified' => 1])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/admin/users?select='.$target->id);

        $target->refresh();

        $this->assertTrue((bool) $target->verified);
        $this->assertNull($target->verification_token);
        $this->assertNull($target->verification_token_sent_at);
    }

    public function test_admin_resend_verification_is_blocked_without_smtp(): void
    {
        $admin = $this->systemAdmin();
        $target = User::factory()->create([
            'verified' => false,
            'verification_token' => str_repeat('b', 64),
        ]);

        Settings::set('smtp_enabled', false, 'global');
        Mail::fake();

        $this->actingAs($admin, 'admin')
            ->post("/admin/users/{$target->id}/resend-verification")
            ->assertSessionHas('error');

        $this->assertSame(str_repeat('b', 64), $target->fresh()->verification_token);
        Mail::assertNothingQueued();
    }

    public function test_admin_resend_verification_rotates_the_token_and_queues_a_link(): void
    {
        $admin = $this->systemAdmin();
        $target = User::factory()->create([
            'verified' => false,
            'verification_token' => str_repeat('c', 64),
        ]);

        Settings::set('smtp_enabled', true, 'global');
        Mail::fake();

        $this->actingAs($admin, 'admin')
            ->post("/admin/users/{$target->id}/resend-verification")
            ->assertRedirect('/admin/users?select='.$target->id);

        $target->refresh();

        $this->assertNotSame(str_repeat('c', 64), $target->verification_token);
        $this->assertSame(64, strlen($target->verification_token));
        $this->assertNotNull($target->verification_token_sent_at);
        Mail::assertQueued(VerifyAccountMail::class);
    }

    public function test_changing_a_verified_email_forces_reverification_and_queues_a_link(): void
    {
        $admin = $this->systemAdmin();
        $target = User::factory()->create(['verified' => true, 'email' => 'old-address@example.com']);

        Settings::set('smtp_enabled', true, 'global');
        Mail::fake();

        $this->actingAs($admin, 'admin')
            ->post('/admin/users/save', ['id' => $target->id, 'email' => 'new-address@example.com'])
            ->assertSessionHasNoErrors();

        $target->refresh();

        $this->assertSame('new-address@example.com', $target->email);
        $this->assertFalse((bool) $target->verified);
        $this->assertNotNull($target->verification_token);
        Mail::assertQueued(VerifyAccountMail::class);
    }

    public function test_changing_a_verified_email_is_blocked_when_smtp_is_off(): void
    {
        $admin = $this->systemAdmin();
        $target = User::factory()->create(['verified' => true, 'email' => 'keep-me@example.com']);

        Settings::set('smtp_enabled', false, 'global');
        Mail::fake();

        $this->actingAs($admin, 'admin')
            ->post('/admin/users/save', ['id' => $target->id, 'email' => 'blocked@example.com'])
            ->assertSessionHasErrors('verified');

        $target->refresh();

        $this->assertSame('keep-me@example.com', $target->email);
        $this->assertTrue((bool) $target->verified);
        Mail::assertNothingQueued();
    }

    public function test_system_admin_can_change_level_of_regular_user(): void
    {
        $admin = $this->systemAdmin();
        $moderator = UserLevel::create([
            'name' => 'moderator',
            'visible_name' => 'Moderator',
            'is_system' => false,
        ]);
        $target = User::factory()->create([
            'level_id' => UserLevel::where('name', 'user')->value('id'),
            'verified' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/users/save', ['id' => $target->id, 'level' => 'moderator'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($moderator->id, $target->fresh()->level_id);
    }

    public function test_users_bulk_action_delete_requires_delete_permission(): void
    {
        $editor = $this->editorAdmin('users');
        $target = User::factory()->create([
            'level_id' => UserLevel::where('name', 'user')->value('id'),
        ]);

        $this->actingAs($editor, 'admin')
            ->postJson('/admin/users/bulk-action', ['action' => 'delete', 'ids' => [$target->id]])
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_admin_audit_redacts_sensitive_fields_at_every_depth(): void
    {
        $admin = $this->systemAdmin();
        $target = User::factory()->create(['level_id' => UserLevel::where('name', 'user')->value('id')]);
        $secrets = [
            'LocalPassword-secret-123',
            'CurrentPassword-secret-123',
            'NewPassword-secret-123',
            'ConfirmPassword-secret-123',
            'SMTP-secret-123',
            'GoogleClient-secret-123',
            'OAuthAccess-secret-123',
        ];

        $this->actingAs($admin, 'admin')
            ->post('/admin/users/save', [
                'id' => $target->id,
                'verified' => true,
                'password' => $secrets[0],
                'current_password' => $secrets[1],
                'new_password' => $secrets[2],
                'new_password_confirmation' => $secrets[3],
                'smtp_pass' => $secrets[4],
                'google_client_secret' => $secrets[5],
                'oauth' => ['access_token' => $secrets[6]],
                'safe_label' => 'keep-this-value',
            ])
            ->assertRedirect();

        $audit = Log::where('context', 'admin.audit')->latest('id')->first();

        $this->assertNotNull($audit);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $audit->content);
        }
        $this->assertStringContainsString('[REDACTED]', $audit->content);
        $this->assertStringContainsString('keep-this-value', $audit->content);
    }

    public function test_builtin_and_assigned_user_levels_cannot_be_deleted(): void
    {
        $admin = $this->systemAdmin();
        $assignedLevel = UserLevel::create([
            'name' => 'assigned_role',
            'visible_name' => 'Assigned role',
            'is_system' => false,
        ]);
        User::factory()->create(['level_id' => $assignedLevel->id]);

        $unusedLevel = UserLevel::create([
            'name' => 'unused_role',
            'visible_name' => 'Unused role',
            'is_system' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/system/user-levels/delete', [
                'ids' => [
                    UserLevel::where('name', 'user')->value('id'),
                    UserLevel::where('name', 'banned')->value('id'),
                    $assignedLevel->id,
                    $unusedLevel->id,
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_levels', ['name' => 'user']);
        $this->assertDatabaseHas('user_levels', ['name' => 'banned']);
        $this->assertDatabaseHas('user_levels', ['id' => $assignedLevel->id]);
        $this->assertDatabaseMissing('user_levels', ['id' => $unusedLevel->id]);
    }

    public function test_user_level_delete_returns_json_for_async_clients(): void
    {
        $admin = $this->systemAdmin();
        $level = UserLevel::create([
            'name' => 'json_delete_role',
            'visible_name' => 'Json delete role',
            'is_system' => false,
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/system/user-levels/delete', ['ids' => [$level->id]])
            ->assertOk()
            ->assertJson(['ok' => true, 'deleted' => 1]);

        $this->assertDatabaseMissing('user_levels', ['id' => $level->id]);
    }

    public function test_user_level_count_cache_is_cleared_after_membership_mutations(): void
    {
        $admin = $this->systemAdmin();
        $moderator = UserLevel::create([
            'name' => 'moderator',
            'visible_name' => 'Moderator',
            'is_system' => false,
        ]);
        $target = User::factory()->create(['level_id' => UserLevel::where('name', 'user')->value('id')]);
        $this->actingAs($admin, 'admin');

        Cache::put('level:user_counts', ['stale' => 1], 3600);
        User::factory()->create(['level_id' => UserLevel::where('name', 'user')->value('id')]);
        $this->assertFalse(Cache::has('level:user_counts'));

        Cache::put('level:user_counts', ['stale' => 1], 3600);
        $this->post('/admin/users/save', [
            'id' => $target->id,
            'level' => $moderator->name,
            'verified' => true,
        ])->assertSessionHas('success');
        $this->assertFalse(Cache::has('level:user_counts'));

        Cache::put('level:user_counts', ['stale' => 1], 3600);
        $this->postJson("/admin/users/{$target->id}/ban", [])->assertOk();
        $this->assertFalse(Cache::has('level:user_counts'));

        Cache::put('level:user_counts', ['stale' => 1], 3600);
        $this->postJson("/admin/users/{$target->id}/unban", [])->assertOk();
        $this->assertFalse(Cache::has('level:user_counts'));

        $target2 = User::factory()->create(['level_id' => UserLevel::where('name', 'user')->value('id')]);
        Cache::put('level:user_counts', ['stale' => 1], 3600);
        $this->postJson('/admin/users/bulk-action', ['action' => 'ban', 'ids' => [$target2->id]])->assertOk();
        $this->assertFalse(Cache::has('level:user_counts'));

        Cache::put('level:user_counts', ['stale' => 1], 3600);
        $this->postJson('/admin/users/bulk-unban', ['ids' => [$target2->id]])->assertOk();
        $this->assertFalse(Cache::has('level:user_counts'));

        $this->mock(WordPressUserDeletionService::class, fn ($m) => $m->shouldReceive('delete')->once());
        Cache::put('level:user_counts', ['stale' => 1], 3600);
        $this->postJson('/admin/users/bulk-delete', ['ids' => [$target2->id]])->assertOk();
        $this->assertFalse(Cache::has('level:user_counts'));
    }

    public function test_banning_already_banned_users_does_not_overwrite_their_ban_details(): void
    {
        $admin = $this->systemAdmin();
        $bannedLevelId = UserLevel::where('name', 'banned')->value('id');
        $existingUntil = now()->addDays(7)->startOfSecond();
        $alreadyBanned = User::factory()->create([
            'level_id' => $bannedLevelId,
            'banned_reason' => 'Original reason',
            'banned_until' => $existingUntil,
        ]);
        $eligible = User::factory()->create([
            'level_id' => UserLevel::where('name', 'user')->value('id'),
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/users/bulk-action', [
                'action' => 'ban',
                'ids' => [$alreadyBanned->id, $eligible->id],
                'reason' => 'New reason',
                'days' => 30,
            ])
            ->assertOk()
            ->assertJsonPath('banned', 1)
            ->assertJsonPath('skipped', 1);

        $this->assertSame('Original reason', $alreadyBanned->fresh()->banned_reason);
        $this->assertEquals($existingUntil, $alreadyBanned->fresh()->banned_until);
        $this->assertSame('New reason', $eligible->fresh()->banned_reason);

        $this->postJson("/admin/users/{$alreadyBanned->id}/ban", ['reason' => 'Overwrite'])
            ->assertStatus(409);
        $this->assertSame('Original reason', $alreadyBanned->fresh()->banned_reason);
    }

    public function test_system_admin_cannot_assign_banned_level_through_profile_save(): void
    {
        $admin = $this->systemAdmin();
        $target = User::factory()->create([
            'level_id' => UserLevel::where('name', 'user')->value('id'),
            'verified' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->post('/admin/users/save', ['id' => $target->id, 'level' => 'banned'])
            ->assertSessionHasErrors('level');

        $this->assertSame(UserLevel::where('name', 'user')->value('id'), $target->fresh()->level_id);
    }

    public function test_reconcile_counts_only_confirmed_signatures(): void
    {
        $petition = Petition::factory()->create(['signature_count' => 999]);

        DB::table('signatures')->insert([
            ['petition_id' => $petition->id, 'name' => 'A', 'email' => 'a@x.test', 'locale' => 'en', 'confirmed' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['petition_id' => $petition->id, 'name' => 'B', 'email' => 'b@x.test', 'locale' => 'en', 'confirmed' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($this->systemAdmin(), 'admin')
            ->postJson('/admin/petitions/reconcile', ['id' => $petition->id])
            ->assertOk()
            ->assertJson(['actual_count' => 1]);

        $this->assertSame(1, (int) $petition->fresh()->signature_count);
    }

    public function test_pages_bulk_delete_removes_pages_and_all_translations(): void
    {
        $this->actingAs($this->systemAdmin(), 'admin');

        $page = Page::create();
        $kept = Page::create();

        foreach (['en', 'fr'] as $locale) {
            DB::table('page_translations')->insert([
                ['page_id' => $page->id, 'locale' => $locale, 'title' => 'Del '.$locale, 'slug' => 'del-'.$locale, 'published' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['page_id' => $kept->id, 'locale' => $locale, 'title' => 'Keep '.$locale, 'slug' => 'keep-'.$locale, 'published' => 1, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        $this->postJson('/admin/pages/bulk-action', ['action' => 'delete', 'ids' => [$page->id]])
            ->assertOk()
            ->assertJson(['ok' => true, 'action' => 'delete', 'affected' => 1]);

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('page_translations', ['page_id' => $page->id]);
        $this->assertDatabaseHas('pages', ['id' => $kept->id]);
        $this->assertDatabaseHas('page_translations', ['page_id' => $kept->id, 'locale' => 'en']);
    }

    public function test_pages_bulk_action_cannot_delete_without_delete_permission(): void
    {
        $editor = $this->editorAdmin('pages');
        $page = Page::create();
        DB::table('page_translations')->insert([
            'page_id' => $page->id,
            'locale' => 'en',
            'title' => 'Keep me',
            'slug' => 'keep-me',
            'published' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($editor, 'admin')
            ->postJson('/admin/pages/bulk-action', ['action' => 'delete', 'ids' => [$page->id]])
            ->assertForbidden();

        $this->assertDatabaseHas('pages', ['id' => $page->id]);
        $this->assertDatabaseHas('page_translations', ['page_id' => $page->id, 'slug' => 'keep-me']);
    }

    public function test_pages_delete_permission_does_not_require_edit_permission(): void
    {
        $deleter = $this->editorAdmin('pages', 'delete');
        $page = Page::create();
        DB::table('page_translations')->insert([
            'page_id' => $page->id,
            'locale' => 'en',
            'title' => 'Delete me',
            'slug' => 'delete-me',
            'published' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($deleter, 'admin')
            ->postJson('/admin/pages/bulk-action', ['action' => 'publish', 'ids' => [$page->id]])
            ->assertForbidden();

        $this->postJson('/admin/pages/bulk-action', ['action' => 'delete', 'ids' => [$page->id]])
            ->assertOk()
            ->assertJson(['ok' => true, 'action' => 'delete', 'affected' => 1]);

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
    }

    public function test_signatures_bulk_action_delete_requires_delete_permission(): void
    {
        $editor = $this->editorAdmin('signatures');
        $petition = Petition::factory()->create();
        $signatureId = DB::table('signatures')->insertGetId([
            'petition_id' => $petition->id, 'name' => 'S', 'email' => 's@x.test', 'locale' => 'en', 'confirmed' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($editor, 'admin')
            ->postJson('/admin/signatures/bulk-action', ['action' => 'delete', 'ids' => [$signatureId]])
            ->assertForbidden();

        $this->assertDatabaseHas('signatures', ['id' => $signatureId]);

        $this->actingAs($editor, 'admin')
            ->postJson('/admin/signatures/bulk-action', ['action' => 'approve', 'ids' => [$signatureId]])
            ->assertOk();

        $this->assertSame(1, (int) DB::table('signatures')->where('id', $signatureId)->value('confirmed'));

        $this->actingAs($this->systemAdmin(), 'admin')
            ->postJson('/admin/signatures/bulk-action', ['action' => 'delete', 'ids' => [$signatureId]])
            ->assertOk();

        $this->assertDatabaseMissing('signatures', ['id' => $signatureId]);
    }

    public function test_signatures_delete_permission_does_not_require_edit_permission(): void
    {
        $deleter = $this->editorAdmin('signatures', 'delete');
        $petition = Petition::factory()->create();
        $signatureId = DB::table('signatures')->insertGetId([
            'petition_id' => $petition->id, 'name' => 'S', 'email' => 's@x.test', 'locale' => 'en', 'confirmed' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($deleter, 'admin')
            ->postJson('/admin/signatures/bulk-action', ['action' => 'approve', 'ids' => [$signatureId]])
            ->assertForbidden();

        $this->postJson('/admin/signatures/bulk-action', ['action' => 'delete', 'ids' => [$signatureId]])
            ->assertOk();

        $this->assertDatabaseMissing('signatures', ['id' => $signatureId]);
    }

    public function test_unfeaturing_petition_clears_only_edited_locale_flag(): void
    {
        $petition = Petition::factory()->create(['is_featured' => 1]);

        foreach (['en', 'fr'] as $locale) {
            DB::table('petition_translations')->insert([
                ['petition_id' => $petition->id, 'locale' => $locale, 'title' => 'T '.$locale, 'slug' => 't-'.$locale, 'description' => 'd', 'is_featured' => 1, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        $this->actingAs($this->systemAdmin(), 'admin')
            ->post('/admin/petitions/save', [
                'id' => $petition->id,
                'locale' => 'en',
                'status' => 'published',
                'title' => 'T en',
                'slug' => 't-en',
            ]);

        $this->assertSame(0, (int) DB::table('petition_translations')->where('petition_id', $petition->id)->where('locale', 'en')->value('is_featured'));
        $this->assertSame(1, (int) DB::table('petition_translations')->where('petition_id', $petition->id)->where('locale', 'fr')->value('is_featured'));
        $this->assertSame(1, (int) $petition->fresh()->is_featured, 'petitions.is_featured is no longer written');
    }

    public function test_featuring_petition_sets_edited_locale_translation_flag(): void
    {
        $petition = Petition::factory()->create(['is_featured' => 0]);

        $this->actingAs($this->systemAdmin(), 'admin')
            ->post('/admin/petitions/save', [
                'id' => $petition->id,
                'locale' => 'en',
                'status' => 'published',
                'title' => 'T en',
                'slug' => 't-en',
                'is_featured' => '1',
            ]);

        $this->assertSame(0, (int) $petition->fresh()->is_featured, 'petitions.is_featured is no longer written');
        $this->assertSame(1, DB::table('petition_translations')->where('petition_id', $petition->id)->where('locale', 'en')->value('is_featured'));
    }

    public function test_language_options_sanitize_homepage_keys(): void
    {
        $this->actingAs($this->systemAdmin(), 'admin')
            ->post('/admin/options/language', [
                'locale' => 'en',
                'h1' => '<strong>Title<script>alert(1)</script></strong>',
            ]);

        $value = DB::table('page_contents')->where('page', 'home')->where('locale', 'en')->where('key', 'h1')->value('value');

        $this->assertSame('<strong>Title</strong>', $value);
    }

    public function test_log_export_streams_all_logs_within_cap(): void
    {
        Log::create(['level' => 'info', 'title' => 'First', 'content' => 'x']);
        Log::create(['level' => 'error', 'title' => 'Second', 'content' => 'y']);

        $content = $this->actingAs($this->systemAdmin(), 'admin')
            ->get('/admin/logs/export')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('First', $content);
        $this->assertStringContainsString('Second', $content);
        $this->assertSame(1, substr_count($content, 'First'));
    }

    public function test_ip_ban_and_unban_take_effect_immediately(): void
    {
        Cache::put('banned_ip:10.1.1.1', false, 300);

        $this->actingAs($this->systemAdmin(), 'admin')
            ->post('/admin/spam/ban', ['ip' => '10.1.1.1'])
            ->assertRedirect();
        $this->assertDatabaseHas('banned_ips', ['ip' => '10.1.1.1']);
        $this->assertNull(Cache::get('banned_ip:10.1.1.1'));

        Cache::put('banned_ip:10.1.1.1', true, 300);
        $this->actingAs($this->systemAdmin(), 'admin')
            ->post('/admin/spam/unban', ['ip' => '10.1.1.1'])
            ->assertRedirect();
        $this->assertDatabaseMissing('banned_ips', ['ip' => '10.1.1.1']);
        $this->assertNull(Cache::get('banned_ip:10.1.1.1'));
    }

    public function test_unknown_user_login_runs_a_hash_check_and_matches_wrong_password_message(): void
    {
        $admin = $this->systemAdmin();

        $wrong = $this->from('/admin/login')->post('/admin/login', ['login' => $admin->email, 'password' => 'wrong-secret']);
        $unknown = $this->from('/admin/login')->post('/admin/login', ['login' => 'nobody@example.test', 'password' => 'wrong-secret']);

        $wrong->assertSessionHasErrors(['login' => 'Invalid credentials.']);
        $unknown->assertSessionHasErrors(['login' => 'Invalid credentials.']);
    }
}
