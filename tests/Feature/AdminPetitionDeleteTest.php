<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Models\Signature;
use App\Models\User;
use App\Models\UserLevel;
use App\Support\CacheVersion;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class AdminPetitionDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
        config(['edge-cache.purge_enabled' => false]);
        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create([
            'level_id' => UserLevel::where('name', 'admin')->value('id'),
            'verified' => true,
        ]);
    }

    public function test_delete_removes_all_petition_children_but_keeps_other_petitions_and_users(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $owner = User::factory()->create();
        $petition = Petition::factory()->create(['user_id' => $owner->id]);
        $kept = Petition::factory()->create(['user_id' => $owner->id]);
        foreach (['en', 'de'] as $locale) {
            PetitionTranslation::create([
                'petition_id' => $petition->id, 'locale' => $locale,
                'title' => 'Delete me', 'slug' => 'delete-me', 'description' => 'Test content',
            ]);
        }
        Signature::factory()->create(['petition_id' => $petition->id]);
        $keptSignature = Signature::factory()->create(['petition_id' => $kept->id]);
        DB::table('petition_outcome_events')->insert([
            ['petition_id' => $petition->id, 'actor_user_id' => $owner->id, 'before' => '{}', 'after' => '{}', 'created_at' => now()],
            ['petition_id' => $kept->id, 'actor_user_id' => $owner->id, 'before' => '{}', 'after' => '{}', 'created_at' => now()],
        ]);
        DB::table('reports')->insert([
            ['reportable_type' => Petition::class, 'reportable_id' => $petition->id, 'reason' => 'spam', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()],
            ['reportable_type' => Petition::class, 'reportable_id' => $kept->id, 'reason' => 'spam', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('petition_views')->insert(['petition_id' => $petition->id, 'viewed_on' => '2026-10-31', 'views' => 1]);
        DB::table('legacy_shortlinks')->insert(['petition_id' => $petition->id, 'short_link' => 'DEL123']);
        Cache::put('legacy_shortlink:DEL123', (object) ['petition_id' => $petition->id], 3600);
        $before = CacheVersion::get('sitemap');
        $this->get(route('admin.petitions'))->assertOk()->assertSee('id="bulk-delete"', false);

        $this->postJson(route('admin.petitions.bulkDelete'), ['ids' => [$petition->id], 'confirmed' => true])
            ->assertOk()->assertJson(['ok' => true, 'deleted' => 1, 'restorable' => false]);

        $this->assertDatabaseMissing('petitions', ['id' => $petition->id]);
        foreach (['petition_translations', 'signatures', 'petition_views', 'legacy_shortlinks', 'petition_outcome_events'] as $table) {
            $this->assertDatabaseMissing($table, ['petition_id' => $petition->id]);
        }
        $this->assertDatabaseMissing('reports', [
            'reportable_type' => Petition::class,
            'reportable_id' => $petition->id,
        ]);
        $this->assertDatabaseHas('petitions', ['id' => $kept->id]);
        $this->assertDatabaseHas('signatures', ['id' => $keptSignature->id]);
        $this->assertDatabaseHas('petition_outcome_events', ['petition_id' => $kept->id]);
        $this->assertDatabaseHas('reports', [
            'reportable_type' => Petition::class,
            'reportable_id' => $kept->id,
        ]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertNull(Cache::get('legacy_shortlink:DEL123'));
        $this->assertNotSame($before, CacheVersion::get('sitemap'));
    }

    public function test_delete_removes_cover_image_files_and_variants_but_keeps_other_petitions_files(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin(), 'admin');
        $files = [
            'petitions/cover.jpg', 'petitions/cover_800w.webp', 'petitions/cover_400w.webp',
            'petitions/bare.png', 'petitions/keep.jpg', 'petitions/keep_800w.webp',
        ];
        foreach ($files as $file) {
            Storage::disk('public')->put($file, 'x');
        }
        $withCover = Petition::factory()->create(['cover_image' => 'petitions/cover.jpg']);
        $withBare = Petition::factory()->create(['cover_image' => null, 'image_url' => 'bare.png']);
        $remote = Petition::factory()->create(['cover_image' => 'https://example.com/a.jpg']);
        $kept = Petition::factory()->create(['cover_image' => 'petitions/keep.jpg']);

        $this->postJson(route('admin.petitions.bulkDelete'), ['ids' => [$withCover->id, $withBare->id, $remote->id], 'confirmed' => true])
            ->assertOk()->assertJson(['deleted' => 3]);

        foreach (array_slice($files, 0, 4) as $file) {
            Storage::disk('public')->assertMissing($file);
        }
        Storage::disk('public')->assertExists('petitions/keep.jpg');
        Storage::disk('public')->assertExists('petitions/keep_800w.webp');
        $this->assertDatabaseHas('petitions', ['id' => $kept->id]);
    }

    public function test_delete_keeps_files_still_referenced_by_surviving_petitions(): void
    {
        Storage::fake('public');
        $this->actingAs($this->admin(), 'admin');
        foreach (['petitions/shared.jpg', 'petitions/shared_800w.webp', 'petitions/legacy.png'] as $file) {
            Storage::disk('public')->put($file, 'x');
        }
        $gone = Petition::factory()->create(['cover_image' => 'petitions/shared.jpg']);
        $goneLegacy = Petition::factory()->create(['cover_image' => null, 'image_url' => 'legacy.png']);
        Petition::factory()->create(['cover_image' => 'petitions/shared.jpg']);
        Petition::factory()->create(['cover_image' => null, 'image_url' => 'legacy.png']);

        $this->postJson(route('admin.petitions.bulkDelete'), ['ids' => [$gone->id, $goneLegacy->id], 'confirmed' => true])
            ->assertOk();

        foreach (['petitions/shared.jpg', 'petitions/shared_800w.webp', 'petitions/legacy.png'] as $file) {
            Storage::disk('public')->assertExists($file);
        }
    }

    public function test_delete_purges_image_urls_at_the_edge(): void
    {
        config()->set('edge-cache.purge_enabled', true);
        config()->set('edge-cache.api_token', 'test-placeholder');
        config()->set('edge-cache.zone_id', 'test-zone');
        config()->set('edge-cache.base_url', 'https://www.freycastle.test');
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true])]);
        Storage::fake('public');
        $this->actingAs($this->admin(), 'admin');
        $petition = Petition::factory()->create(['cover_image' => 'petitions/edge.jpg']);

        $this->postJson(route('admin.petitions.bulkDelete'), ['ids' => [$petition->id], 'confirmed' => true])
            ->assertOk();

        Http::assertSent(fn ($request) => in_array('https://www.freycastle.test/storage/petitions/edge.jpg', $request['files'], true)
            && in_array('https://www.freycastle.test/storage/petitions/edge_800w.webp', $request['files'], true));
    }

    public function test_delete_succeeds_when_file_deletion_throws(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $petition = Petition::factory()->create(['cover_image' => 'petitions/boom.jpg']);
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->andThrow(new \RuntimeException('disk down'));
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);

        $this->postJson(route('admin.petitions.bulkDelete'), ['ids' => [$petition->id], 'confirmed' => true])
            ->assertOk()->assertJson(['deleted' => 1]);

        $this->assertDatabaseMissing('petitions', ['id' => $petition->id]);
    }

    public function test_delete_requires_explicit_confirmation_and_valid_selection(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $petition = Petition::factory()->create();
        $this->postJson(route('admin.petitions.bulkDelete'), ['ids' => [$petition->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->postJson(route('admin.petitions.bulkDelete'), ['ids' => [], 'confirmed' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->postJson(route('admin.petitions.bulkDelete'), ['ids' => [$petition->id, 999999999], 'confirmed' => true])
            ->assertUnprocessable();
        $this->assertDatabaseHas('petitions', ['id' => $petition->id]);
    }

    public function test_delete_rejects_more_than_50_petitions(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $petitions = Petition::factory()->count(51)->create();
        $this->assertCount(51, $petitions);

        $this->postJson(route('admin.petitions.bulkDelete'), [
            'ids' => $petitions->pluck('id')->all(),
            'confirmed' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('ids');
    }

    public function test_delete_removes_45_petitions(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $petitions = Petition::factory()->count(45)->create();

        $this->postJson(route('admin.petitions.bulkDelete'), [
            'ids' => $petitions->pluck('id')->all(),
            'confirmed' => true,
        ])->assertOk()->assertJson([
            'ok' => true,
            'deleted' => 45,
            'restorable' => false,
        ]);

        $this->assertSame(0, Petition::whereKey($petitions->modelKeys())->count());
    }

    public function test_edit_permission_does_not_grant_delete_and_delete_does_not_require_edit(): void
    {
        $level = UserLevel::create(['name' => 'petition_editor', 'visible_name' => 'Editor', 'is_system' => false]);
        foreach (['view', 'edit'] as $action) {
            Permission::create(['level_id' => $level->id, 'module' => 'petitions', 'action' => $action]);
        }
        $editor = User::factory()->create(['level_id' => $level->id, 'verified' => true]);
        $petition = Petition::factory()->create();
        $this->actingAs($editor, 'admin')->postJson(route('admin.petitions.bulkDelete'), ['ids' => [$petition->id], 'confirmed' => true])
            ->assertForbidden();
        $this->assertDatabaseHas('petitions', ['id' => $petition->id]);

        // A separate permission level avoids carrying cached editor permissions.
        $deleterLevel = UserLevel::create(['name' => 'petition_deleter', 'visible_name' => 'Deleter', 'is_system' => false]);
        foreach (['view', 'delete'] as $action) {
            Permission::create(['level_id' => $deleterLevel->id, 'module' => 'petitions', 'action' => $action]);
        }
        $deleter = User::factory()->create(['level_id' => $deleterLevel->id, 'verified' => true]);
        $this->actingAs($deleter, 'admin')->postJson(route('admin.petitions.bulkDelete'), ['ids' => [$petition->id], 'confirmed' => true])
            ->assertOk();
    }

    public function test_guest_cannot_delete_petitions(): void
    {
        $petition = Petition::factory()->create();
        $this->postJson(route('admin.petitions.bulkDelete'), ['ids' => [$petition->id], 'confirmed' => true])
            ->assertRedirect(route('admin.login'));
        $this->assertDatabaseHas('petitions', ['id' => $petition->id]);
    }
}
