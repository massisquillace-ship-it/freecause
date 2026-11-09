<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Petition;
use App\Models\User;
use App\Models\UserLevel;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminPetitionPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);

        RateLimiter::for('admin-login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }

    protected ?User $petitionsAdmin = null;

    protected function petitionsEditor(): User
    {
        if ($this->petitionsAdmin && $this->petitionsAdmin->exists) {
            return $this->petitionsAdmin;
        }

        $level = UserLevel::create([
            'name' => 'editor_petitions',
            'visible_name' => 'Editor',
            'is_system' => false,
        ]);

        Permission::create(['level_id' => $level->id, 'module' => 'petitions', 'action' => 'edit']);
        Permission::create(['level_id' => $level->id, 'module' => 'petitions', 'action' => 'view']);

        return $this->petitionsAdmin = User::factory()->create([
            'level_id' => $level->id,
            'verified' => true,
        ]);
    }

    public function test_create_petition_builds_draft_with_translation_and_dedupes_slug(): void
    {
        $admin = $this->petitionsEditor();

        $response = $this->actingAs($admin, 'admin')->post('/admin/petitions/create', [
            'title' => 'Test Petition',
            'locale' => 'en',
            'goal_signatures' => 1000,
        ]);

        $id = Petition::query()->max('id');
        $response->assertRedirect(route('admin.petitions', ['select' => $id, 'locale' => 'en']));
        $response->assertSessionHas('success', 'petition #'.$id.' created');

        $petition = DB::table('petitions')->where('id', $id)->first();
        $this->assertSame('draft', $petition->status);
        $this->assertSame(1000, (int) $petition->goal_signatures);
        $this->assertSame($admin->id, (int) $petition->user_id);

        $translation = DB::table('petition_translations')
            ->where('petition_id', $id)
            ->where('locale', 'en')
            ->first();

        $this->assertNotNull($translation);
        $this->assertSame('Test Petition', $translation->title);
        $this->assertSame('test-petition', $translation->slug);

        $this->actingAs($admin, 'admin')
            ->post('/admin/petitions/create', ['title' => 'Test Petition', 'locale' => 'en'])
            ->assertRedirect();

        $slugs = DB::table('petition_translations')->where('locale', 'en')->pluck('slug');
        $this->assertTrue($slugs->contains('test-petition-1'));
        $this->assertSame(2, $slugs->count());
        $this->assertSame(2, $slugs->unique()->count());
    }

    public function test_translate_missing_seeds_drafts_from_source_locale(): void
    {
        $admin = $this->petitionsEditor();

        $petitionId = $this->createPetitionViaDb('Source Petition', 'en', 'source-petition');

        $expectedLanguages = DB::table('languages')->where('is_active', 1)->count() - 1;

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/petitions/translate-missing', ['id' => $petitionId, 'locale' => 'en'])
            ->assertOk()
            ->assertJson(['ok' => true, 'created' => $expectedLanguages, 'missing' => 0]);

        $rows = DB::table('petition_translations')->where('petition_id', $petitionId)->get();
        $this->assertCount($expectedLanguages + 1, $rows);
        $this->assertTrue($rows->every(fn ($row) => $row->slug === 'source-petition'));
        $this->assertTrue($rows->contains(fn ($row) => $row->locale === 'fr' && $row->title === 'Source Petition'));

        $this->actingAs($admin, 'admin')
            ->postJson('/admin/petitions/translate-missing', ['id' => $petitionId])
            ->assertOk()
            ->assertJson(['ok' => true, 'created' => 0, 'missing' => 0]);
    }

    public function test_translate_missing_without_source_translation_fails_cleanly(): void
    {
        $admin = $this->petitionsEditor();

        $petitionId = DB::table('petitions')->insertGetId([
            'user_id' => $admin->id,
            'goal_signatures' => 1000,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($admin, 'admin')
            ->postJson('/admin/petitions/translate-missing', ['id' => $petitionId])
            ->assertNotFound()
            ->assertJson(['ok' => false, 'error' => 'no source translation']);

        $this->assertSame(
            0,
            DB::table('petition_translations')->where('petition_id', $petitionId)->count()
        );
    }

    public function test_save_still_updates_the_petition_after_cache_touches(): void
    {
        $admin = $this->petitionsEditor();

        $petitionId = $this->createPetitionViaDb('Old Title', 'en', 'old-title');

        $this->actingAs($admin, 'admin')
            ->post('/admin/petitions/save', [
                'id' => $petitionId,
                'locale' => 'en',
                'status' => 'published',
                'title' => 'New Title',
                'slug' => 'new-title',
                'goal_signatures' => 5000,
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.petitions', ['select' => $petitionId, 'locale' => 'en']))
            ->assertSessionHas('success', 'saved');

        $this->assertSame('published', DB::table('petitions')->where('id', $petitionId)->value('status'));
        $this->assertSame('New Title', DB::table('petition_translations')
            ->where('petition_id', $petitionId)->where('locale', 'en')->value('title'));
    }

    public function test_save_accepts_a_six_mb_cover(): void
    {
        Storage::fake('public');
        $admin = $this->petitionsEditor();
        $petitionId = $this->createPetitionViaDb('Cover Title', 'en', 'cover-title');

        $this->actingAs($admin, 'admin')
            ->post('/admin/petitions/save', [
                'id' => $petitionId,
                'locale' => 'en',
                'status' => 'published',
                'title' => 'Cover Title',
                'slug' => 'cover-title',
                'cover_image' => UploadedFile::fake()->image('c.jpg', 1000, 800)->size(6144),
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(DB::table('petitions')->where('id', $petitionId)->value('cover_image'));
    }

    public function test_save_rejects_a_cover_over_the_pixel_cap_without_storing_it(): void
    {
        Storage::fake('public');
        $admin = $this->petitionsEditor();
        $petitionId = $this->createPetitionViaDb('Cover Title', 'en', 'cover-title');
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NN', 7000, 6000)."\x08\x02\x00\x00\x00".pack('N', 0);

        $this->actingAs($admin, 'admin')
            ->post('/admin/petitions/save', [
                'id' => $petitionId,
                'locale' => 'en',
                'status' => 'published',
                'title' => 'Cover Title',
                'slug' => 'cover-title',
                'cover_image' => UploadedFile::fake()->createWithContent('huge.png', $png),
            ])
            ->assertSessionHasErrors(['cover_image' => __('create.err_image_pixels')]);

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNull(DB::table('petitions')->where('id', $petitionId)->value('cover_image'));
    }

    public function test_save_normalises_youtube_links_to_the_id_and_rejects_other_values(): void
    {
        $admin = $this->petitionsEditor();
        $petitionId = $this->createPetitionViaDb('Video Title', 'en', 'video-title');
        $payload = [
            'id' => $petitionId,
            'locale' => 'en',
            'status' => 'published',
            'title' => 'Video Title',
            'slug' => 'video-title',
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ?si=abc',
        ];

        $this->actingAs($admin, 'admin')->post('/admin/petitions/save', $payload)->assertSessionHasNoErrors();
        $this->assertSame('dQw4w9WgXcQ', DB::table('petitions')->where('id', $petitionId)->value('youtube_url'));

        $this->actingAs($admin, 'admin')
            ->post('/admin/petitions/save', ['youtube_url' => 'https://example.com/video'] + $payload)
            ->assertSessionHasErrors('youtube_url');
        $this->assertSame('dQw4w9WgXcQ', DB::table('petitions')->where('id', $petitionId)->value('youtube_url'));

        $this->actingAs($admin, 'admin')->post('/admin/petitions/save', ['youtube_url' => ''] + $payload)->assertSessionHasNoErrors();
        $this->assertNull(DB::table('petitions')->where('id', $petitionId)->value('youtube_url'));
    }

    public function test_save_keeps_an_unchanged_legacy_youtube_value_and_validates_a_changed_one(): void
    {
        $admin = $this->petitionsEditor();
        $petitionId = $this->createPetitionViaDb('Legacy Title', 'en', 'legacy-title');
        DB::table('petitions')->where('id', $petitionId)->update(['youtube_url' => 'abc']);
        $payload = [
            'id' => $petitionId,
            'locale' => 'en',
            'status' => 'published',
            'slug' => 'legacy-title',
        ];

        $this->actingAs($admin, 'admin')
            ->post('/admin/petitions/save', ['title' => 'Renamed Title', 'youtube_url' => 'abc'] + $payload)
            ->assertSessionHasNoErrors();
        $this->assertSame('abc', DB::table('petitions')->where('id', $petitionId)->value('youtube_url'));
        $this->assertSame('Renamed Title', DB::table('petition_translations')->where('petition_id', $petitionId)->value('title'));

        $this->actingAs($admin, 'admin')
            ->post('/admin/petitions/save', ['title' => 'Renamed Title', 'youtube_url' => 'junk'] + $payload)
            ->assertSessionHasErrors('youtube_url');
        $this->assertSame('abc', DB::table('petitions')->where('id', $petitionId)->value('youtube_url'));
    }

    public function test_legacy_goal_is_rendered_selected_and_survives_an_unrelated_save(): void
    {
        $admin = $this->petitionsEditor();
        $petitionId = $this->createPetitionViaDb('Goal Title', 'en', 'goal-title');
        DB::table('petitions')->where('id', $petitionId)->update(['goal_signatures' => 250]);
        $payload = [
            'id' => $petitionId,
            'locale' => 'en',
            'status' => 'published',
            'title' => 'Goal Title',
            'slug' => 'goal-title',
        ];

        $this->actingAs($admin, 'admin')
            ->get('/admin/petitions?select='.$petitionId.'&locale=en')
            ->assertOk()
            ->assertSee('<option value="250" selected>', false);

        $this->actingAs($admin, 'admin')
            ->post('/admin/petitions/save', ['goal_signatures' => 250] + $payload)
            ->assertSessionHasNoErrors();
        $this->assertSame(250, (int) DB::table('petitions')->where('id', $petitionId)->value('goal_signatures'));

        $this->actingAs($admin, 'admin')
            ->post('/admin/petitions/save', ['goal_signatures' => 251] + $payload)
            ->assertSessionHasErrors('goal_signatures');
        $this->assertSame(250, (int) DB::table('petitions')->where('id', $petitionId)->value('goal_signatures'));
    }

    public function test_save_with_blank_goal_keeps_the_stored_goal_and_bumps_updated_at(): void
    {
        $admin = $this->petitionsEditor();
        $petitionId = $this->createPetitionViaDb('Blank Goal', 'en', 'blank-goal');
        DB::table('petitions')->where('id', $petitionId)->update(['goal_signatures' => 250, 'updated_at' => '2020-01-01 00:00:00']);

        $this->actingAs($admin, 'admin')
            ->post('/admin/petitions/save', [
                'id' => $petitionId,
                'locale' => 'en',
                'status' => 'published',
                'title' => 'Blank Goal',
                'slug' => 'blank-goal',
            ])
            ->assertSessionHasNoErrors();

        $row = DB::table('petitions')->where('id', $petitionId)->first();
        $this->assertSame(250, (int) $row->goal_signatures);
        $this->assertNotSame('2020-01-01 00:00:00', (string) $row->updated_at);
    }

    public function test_save_rejects_a_non_http_community_url(): void
    {
        $admin = $this->petitionsEditor();
        $petitionId = $this->createPetitionViaDb('Community', 'en', 'community');

        $this->actingAs($admin, 'admin')
            ->post('/admin/petitions/save', [
                'id' => $petitionId,
                'locale' => 'en',
                'status' => 'published',
                'title' => 'Community',
                'slug' => 'community',
                'community_url' => 'ftp://example.com/group',
            ])
            ->assertSessionHasErrors('community_url');
    }

    private function createPetitionViaDb(string $title, string $locale, string $slug): int
    {
        $id = DB::table('petitions')->insertGetId([
            'user_id' => $this->petitionsEditor()->id,
            'goal_signatures' => 1000,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('petition_translations')->insert([
            'petition_id' => $id,
            'locale' => $locale,
            'title' => $title,
            'slug' => $slug,
            'description' => 'source body',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $id;
    }
}
