<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckPermission;
use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Models\Signature;
use App\Models\User;
use App\Models\UserLevel;
use App\Services\PetitionStats;
use App\Services\WordPressUserDeletionService;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AuditMergeBehaviourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
    }

    private function admin(array $attributes = []): User
    {
        return User::factory()->create([
            'level_id' => UserLevel::where('name', 'admin')->value('id'),
            'verified' => true,
            ...$attributes,
        ]);
    }

    public function test_every_reconcile_path_excludes_spam_and_unconfirmed(): void
    {
        $petition = Petition::factory()->create(['signature_count' => 99]);
        Signature::factory()->count(2)->create(['petition_id' => $petition->id, 'confirmed' => true]);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'is_spam' => true]);
        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => false]);

        $this->actingAs($this->admin(), 'admin')->postJson(route('admin.petitions.reconcileAll'))->assertOk();
        $this->assertSame(2, $petition->fresh()->signature_count);

        Petition::whereKey($petition->id)->update(['signature_count' => 99]);
        $this->artisan('signatures:reconcile --dry-run')->assertSuccessful();
        $this->assertSame(99, $petition->fresh()->signature_count);

        $this->artisan('signatures:reconcile')->assertSuccessful();
        $this->assertSame(2, $petition->fresh()->signature_count);

        Petition::whereKey($petition->id)->update(['signature_count' => 99]);
        $this->assertSame(2, PetitionStats::recalculate($petition->id));
        $this->assertSame(2, $petition->fresh()->signature_count);
    }

    public function test_trending_ignores_spam_signatures(): void
    {
        $spammed = Petition::factory()->create(['status' => 'published', 'is_active' => true, 'signature_count' => 500]);
        $real = Petition::factory()->create(['status' => 'published', 'is_active' => true, 'signature_count' => 1]);
        PetitionTranslation::factory()->create(['petition_id' => $spammed->id, 'locale' => 'en', 'title' => 'Spammed Petition']);
        PetitionTranslation::factory()->create(['petition_id' => $real->id, 'locale' => 'en', 'title' => 'Real Petition']);
        Signature::factory()->count(3)->create(['petition_id' => $spammed->id, 'confirmed' => true, 'is_spam' => true, 'created_at' => now()->subDay()]);
        Signature::factory()->create(['petition_id' => $real->id, 'confirmed' => true, 'created_at' => now()->subDay()]);

        $html = $this->get('/en/petitions?sort=trending')->assertOk()->getContent();

        $this->assertLessThan(strpos($html, 'Spammed Petition'), strpos($html, 'Real Petition'));
    }

    public function test_verifying_an_email_counts_only_non_spam_pending_signatures(): void
    {
        $user = User::factory()->create(['verified' => false, 'verification_token' => User::hashVerificationToken('merge-token'), 'verification_token_sent_at' => now()]);
        $clean = Petition::factory()->create(['signature_count' => 0]);
        $spam = Petition::factory()->create(['signature_count' => 0]);
        Signature::factory()->create(['petition_id' => $clean->id, 'user_id' => $user->id, 'confirmed' => false]);
        Signature::factory()->create(['petition_id' => $spam->id, 'user_id' => $user->id, 'confirmed' => false, 'is_spam' => true]);

        $this->post('/en/verify/merge-token', [])->assertRedirect();

        $this->assertSame(1, $clean->fresh()->signature_count);
        $this->assertSame(0, $spam->fresh()->signature_count);
    }

    public function test_admin_legacy_password_is_upgraded_and_stamped_only_when_correct(): void
    {
        $legacy = md5(md5('legacy-secret').'salt').':salt';
        $admin = $this->admin(['password' => $legacy, 'password_changed_at' => null]);

        $this->post('/admin/login', ['login' => $admin->email, 'password' => 'wrong-secret'])->assertSessionHasErrors('login');
        $this->assertSame($legacy, $admin->fresh()->password);
        $this->assertNull($admin->fresh()->password_changed_at);

        $this->post('/admin/login', ['login' => $admin->email, 'password' => 'legacy-secret'])->assertRedirect(route('admin.dashboard'));
        $this->assertTrue(Hash::check('legacy-secret', $admin->fresh()->password));
        $this->assertNotNull($admin->fresh()->password_changed_at);
    }

    public function test_admin_password_changes_stamp_password_changed_at(): void
    {
        $viaCommand = $this->admin(['password_changed_at' => null]);
        $this->artisan('admin:reset-password', ['email' => $viaCommand->email, 'password' => 'new-secret-1'])->assertSuccessful();
        $this->assertNotNull($viaCommand->fresh()->password_changed_at);

        $viaForm = $this->admin(['password' => Hash::make('old-secret-1'), 'password_changed_at' => null]);
        $this->actingAs($viaForm, 'admin')->post(route('admin.system.user_info.update'), [
            'email' => $viaForm->email,
            'current_password' => 'old-secret-1',
            'new_password' => 'new-secret-2',
            'new_password_confirmation' => 'new-secret-2',
        ])->assertRedirect(route('admin.login'));
        $this->assertNotNull($viaForm->fresh()->password_changed_at);
    }

    public function test_bulk_delete_only_mentions_protected_accounts_when_some_were_excluded(): void
    {
        $sysAdmin = $this->admin();
        $userLevel = UserLevel::where('name', 'user')->value('id');
        $this->mock(WordPressUserDeletionService::class, fn ($m) => $m->shouldReceive('delete')->andThrow(new \RuntimeException('wp down')));

        $wpFailureOnly = $this->actingAs($sysAdmin, 'admin')
            ->postJson('/admin/users/bulk-delete', ['ids' => [User::factory()->create(['level_id' => $userLevel])->id]])
            ->assertOk()
            ->json('msg');
        $this->assertStringNotContainsString('protected accounts', $wpFailureOnly);
        $this->assertStringContainsString('1 failed WordPress cleanup', $wpFailureOnly);

        $withProtected = $this->postJson('/admin/users/bulk-delete', ['ids' => [
            User::factory()->create(['level_id' => $userLevel])->id,
            $this->admin()->id,
        ]])->assertOk()->json('msg');
        $this->assertStringContainsString('protected accounts', $withProtected);
    }

    public function test_check_permission_keeps_its_403_messages(): void
    {
        $handle = fn () => (new CheckPermission)->handle(Request::create('/admin'), fn () => response('ok'), 'users', 'view');

        try {
            $handle();
            $this->fail('Expected 403 without a user');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('', $e->getMessage());
        }

        Auth::guard('admin')->setUser(User::factory()->create(['level_id' => null]));

        try {
            $handle();
            $this->fail('Expected 403 without a role');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString('no role assigned', $e->getMessage());
        }

        Auth::guard('admin')->setUser($this->admin());
        $this->assertSame('ok', $handle()->getContent());
    }
}
