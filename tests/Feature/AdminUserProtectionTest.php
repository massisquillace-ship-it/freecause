<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Petition;
use App\Models\Signature;
use App\Models\User;
use App\Models\UserLevel;
use App\Services\PetitionSigning;
use App\Services\WordPressUserDeletionService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
    }

    private function sysAdmin(array $o = []): User
    {
        return User::factory()->create($o + [
            'level_id' => UserLevel::where('name', 'admin')->value('id'),
            'verified' => true,
        ]);
    }

    private function editor(string $name, array $o = []): User
    {
        $level = UserLevel::firstOrCreate(['name' => $name], ['visible_name' => 'Editor', 'is_system' => false]);
        Permission::firstOrCreate(['level_id' => $level->id, 'module' => 'users', 'action' => 'edit']);
        Permission::firstOrCreate(['level_id' => $level->id, 'module' => 'users', 'action' => 'view']);

        return User::factory()->create($o + ['level_id' => $level->id, 'verified' => true]);
    }

    public function test_non_system_editor_cannot_change_email_or_password_of_another_admin(): void
    {
        $actor = $this->editor('ed_users');
        $target = $this->editor('ed_other', ['email' => 'mod@example.com', 'password' => Hash::make('keep-me-123')]);

        $this->actingAs($actor, 'admin')
            ->post('/admin/users/save', ['id' => $target->id, 'password' => 'hijacked-123'])
            ->assertSessionHasErrors('id');
        $this->actingAs($actor, 'admin')
            ->post('/admin/users/save', ['id' => $target->id, 'email' => 'evil@example.com'])
            ->assertSessionHasErrors('id');

        $target->refresh();
        $this->assertSame('mod@example.com', $target->email);
        $this->assertTrue(Hash::check('keep-me-123', $target->password));

        $this->actingAs($actor, 'admin')
            ->post('/admin/users/save', ['id' => $target->id, 'first_name' => 'Renamed'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Renamed', $target->fresh()->first_name);
    }

    public function test_admin_save_clears_emptied_name_nickname_and_city_but_keeps_omitted_ones(): void
    {
        $target = User::factory()->create([
            'level_id' => UserLevel::where('name', 'user')->value('id'),
            'first_name' => 'Ann',
            'last_name' => 'Lee',
            'nickname' => 'offensive',
            'city' => 'Paris',
        ]);
        $admin = $this->sysAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/users/save', ['id' => $target->id, 'last_name' => '', 'nickname' => '', 'city' => ''])
            ->assertSessionHasNoErrors();

        $target->refresh();
        $this->assertNull($target->last_name);
        $this->assertNull($target->nickname);
        $this->assertNull($target->city);
        $this->assertSame('Ann', $target->first_name);

        $this->actingAs($admin, 'admin')
            ->post('/admin/users/save', ['id' => $target->id, 'first_name' => 'Bob'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Bob', $target->fresh()->first_name);
        $this->assertNull($target->fresh()->city);
    }

    public function test_system_admin_can_change_password_of_an_editor(): void
    {
        $target = $this->editor('ed_other');

        $this->actingAs($this->sysAdmin(), 'admin')
            ->post('/admin/users/save', ['id' => $target->id, 'password' => 'changed-pass-123'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('changed-pass-123', $target->fresh()->password));
    }

    public function test_bulk_and_single_ban_skip_anyone_who_can_access_admin(): void
    {
        $editor = $this->editor('ed_other');
        $member = User::factory()->create(['level_id' => UserLevel::where('name', 'user')->value('id')]);
        $bannedId = UserLevel::where('name', 'banned')->value('id');

        $this->actingAs($this->sysAdmin(), 'admin')
            ->postJson('/admin/users/bulk-banned', ['ids' => [$editor->id, $member->id], 'days' => 3])
            ->assertOk()
            ->assertJson(['banned' => 1, 'skipped' => 1]);

        $this->assertSame($bannedId, $member->fresh()->level_id);
        $this->assertNotSame($bannedId, $editor->fresh()->level_id);

        $this->postJson(route('admin.users.ban', $editor), ['days' => 0])
            ->assertForbidden()
            ->assertJsonPath('ok', false);
        $this->assertNotSame($bannedId, $editor->fresh()->level_id);
    }

    public function test_bulk_delete_skips_anyone_who_can_access_admin(): void
    {
        $this->mock(WordPressUserDeletionService::class)->shouldReceive('delete');
        $editor = $this->editor('ed_other');
        $member = User::factory()->create(['level_id' => UserLevel::where('name', 'user')->value('id')]);

        $this->actingAs($this->sysAdmin(), 'admin')
            ->postJson('/admin/users/bulk-delete', ['ids' => [$editor->id, $member->id]])
            ->assertOk()
            ->assertJson(['ok' => true, 'deleted' => 1]);

        $this->assertNotNull($editor->fresh());
        $this->assertNull($member->fresh());
    }

    public function test_bulk_delete_is_capped_at_25_users_per_request(): void
    {
        $this->mock(WordPressUserDeletionService::class)->shouldReceive('delete');
        $admin = $this->sysAdmin();
        $ids = User::factory()->count(26)->create(['level_id' => UserLevel::where('name', 'user')->value('id')])->pluck('id');

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/users/bulk-delete', ['ids' => $ids->all()])
            ->assertStatus(422)
            ->assertJsonPath('ok', false);
        $this->assertSame(26, User::whereIn('id', $ids)->count());

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/users/bulk-delete', ['ids' => $ids->take(25)->all()])
            ->assertOk()
            ->assertJson(['ok' => true, 'deleted' => 25]);
        $this->assertSame(1, User::whereIn('id', $ids)->count());
    }

    public function test_my_account_email_taken_by_another_user_is_a_validation_error(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $admin = $this->sysAdmin(['email' => 'me@example.com']);

        $this->actingAs($admin, 'admin')
            ->post('/admin/system/user-info', ['email' => 'taken@example.com'])
            ->assertSessionHasErrors('email');
        $this->assertSame('me@example.com', $admin->fresh()->email);

        $this->actingAs($admin, 'admin')
            ->post('/admin/system/user-info', ['email' => 'me@example.com'])
            ->assertSessionHasNoErrors();
    }

    public function test_admin_marking_user_verified_confirms_pending_signatures_once(): void
    {
        $user = User::factory()->create(['verified' => false, 'verification_token' => 'synthetic-token']);
        $petition = Petition::factory()->create(['signature_count' => 0]);
        $signature = Signature::factory()->create([
            'petition_id' => $petition->id,
            'user_id' => $user->id,
            'email' => $user->email,
            'confirmed' => false,
        ]);
        $spamPetition = Petition::factory()->create(['signature_count' => 0]);
        $spam = Signature::factory()->create([
            'petition_id' => $spamPetition->id,
            'user_id' => $user->id,
            'confirmed' => false,
            'is_spam' => true,
        ]);
        $admin = $this->sysAdmin();

        $this->actingAs($admin, 'admin')
            ->post('/admin/users/save', ['id' => $user->id, 'verified' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertTrue((bool) $signature->fresh()->confirmed);
        $this->assertSame(1, (int) $petition->fresh()->signature_count);

        PetitionSigning::markVerified($user->fresh());

        $this->assertTrue((bool) $user->fresh()->verified);
        $this->assertNull($user->fresh()->verification_token);
        $this->assertTrue((bool) $signature->fresh()->confirmed);
        $this->assertSame(1, (int) $petition->fresh()->signature_count);
        $this->assertFalse((bool) $spam->fresh()->confirmed);
        $this->assertSame(0, (int) $spamPetition->fresh()->signature_count);
    }

    public function test_admin_save_does_not_reconfirm_an_admin_rejected_signature(): void
    {
        $user = User::factory()->create(['verified' => true]);
        $petition = Petition::factory()->create(['signature_count' => 0]);
        $rejected = Signature::factory()->create([
            'petition_id' => $petition->id,
            'user_id' => $user->id,
            'confirmed' => false,
            'is_spam' => false,
        ]);

        $this->actingAs($this->sysAdmin(), 'admin')
            ->post('/admin/users/save', ['id' => $user->id, 'verified' => '1', 'first_name' => 'Resaved'])
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) $rejected->fresh()->confirmed);
        $this->assertSame(0, (int) $petition->fresh()->signature_count);
    }

    public function test_mark_verified_is_a_no_op_for_an_already_verified_user(): void
    {
        $user = User::factory()->create(['verified' => true]);
        $petition = Petition::factory()->create(['signature_count' => 0]);
        $rejected = Signature::factory()->create([
            'petition_id' => $petition->id,
            'user_id' => $user->id,
            'confirmed' => false,
            'is_spam' => false,
        ]);

        PetitionSigning::markVerified($user);

        $this->assertFalse((bool) $rejected->fresh()->confirmed);
        $this->assertSame(0, (int) $petition->fresh()->signature_count);
    }

    public function test_is_banned_uses_level_id_without_loading_the_level(): void
    {
        $banned = User::factory()->create(['level_id' => UserLevel::where('name', 'banned')->value('id')]);
        $member = User::factory()->create(['level_id' => UserLevel::where('name', 'user')->value('id')]);

        $banned = User::find($banned->id);
        $member = User::find($member->id);

        $this->assertTrue($banned->isBanned());
        $this->assertFalse($member->isBanned());
        $this->assertFalse($banned->relationLoaded('level'));
        $this->assertFalse($member->relationLoaded('level'));
    }
}
