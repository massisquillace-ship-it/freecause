<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Permission;
use App\Models\Petition;
use App\Models\User;
use App\Models\UserLevel;
use App\Support\ReviewKeywords;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PetitionReviewKeywordsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
        $this->user = User::factory()->create(['verified' => true]);
        $this->category = Category::factory()->create(['is_active' => true]);
        CategoryTranslation::create([
            'category_id' => $this->category->id,
            'locale' => 'en',
            'name' => 'Test Category',
            'slug' => 'test-category',
        ]);
    }

    private function payload(array $over = []): array
    {
        return array_merge([
            'title' => 'Test Petition Title Here',
            'description' => '<p>This is a test petition with enough content to pass validation and be accepted.</p>',
            'goal_signatures' => 100,
            'category_id' => $this->category->id,
        ], $over);
    }

    public function test_clean_petition_is_published_immediately(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload())->assertRedirect();

        $this->assertDatabaseHas('petitions', ['status' => 'published']);
    }

    public function test_petition_with_banned_keyword_stays_unpublished(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload([
            'title' => 'Stop casino betting promotions',
        ]))->assertRedirect();

        $petition = Petition::latest('id')->firstOrFail();

        $this->assertSame('draft', $petition->status);
    }

    public function test_banned_keyword_in_description_stays_unpublished(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload([
            'description' => '<p>Please read about the online casino bonus offers available right now.</p>',
        ]))->assertRedirect();

        $this->assertDatabaseHas('petitions', ['status' => 'draft']);
    }

    public function test_non_english_keyword_stays_unpublished(): void
    {
        $this->actingAs($this->user)->post('/ro/create-petition', $this->payload([
            'title' => 'Pariuri online pe scara',
        ]))->assertRedirect();

        $this->assertDatabaseHas('petitions', ['status' => 'draft']);
    }

    public function test_owner_sees_review_notice_and_visitors_do_not(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload([
            'title' => 'Stop online poker advertising',
        ]))->assertRedirect();

        $petition = Petition::latest('id')->firstOrFail();
        $slug = $petition->translations()->where('locale', 'en')->value('slug');

        $this->actingAs($this->user)
            ->get("/en/petition/{$slug}/{$petition->id}")
            ->assertOk()
            ->assertSee(__('petition.review_required'))
            ->assertDontSee('name="email"', false);

        $other = User::factory()->create(['verified' => true]);

        $this->actingAs($other)
            ->get("/en/petition/{$slug}/{$petition->id}")
            ->assertNotFound();
    }

    public function test_review_notice_is_shown_on_the_thanks_page(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload([
            'title' => 'Ban free spins offers',
        ]))->assertRedirect();

        $petition = Petition::latest('id')->firstOrFail();
        $slug = $petition->translations()->where('locale', 'en')->value('slug');

        $this->actingAs($this->user)
            ->get("/en/petition/{$slug}/{$petition->id}/thanks")
            ->assertOk()
            ->assertSee(__('petition.review_required'));
    }

    public function test_matcher_flags_banned_terms_and_leetspeak(): void
    {
        $this->assertNotEmpty(ReviewKeywords::matches('We want to stop betting ads'));
        $this->assertNotEmpty(ReviewKeywords::matches('b3tting is not fair'));
        $this->assertNotEmpty(ReviewKeywords::matches('Cazino online in Romania'));
        $this->assertNotEmpty(ReviewKeywords::matches('free spins'));
        $this->assertEmpty(ReviewKeywords::matches('A petition about unpaid sex education in schools'));
        $this->assertEmpty(ReviewKeywords::matches('Support for local schools and libraries'));
    }

    public function test_owner_can_edit_an_unpublished_petition_and_publishes_it_when_keywords_are_removed(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload([
            'title' => 'Stop casino advertising',
        ]))->assertRedirect();

        $petition = Petition::latest('id')->firstOrFail();
        $slug = $petition->translations()->where('locale', 'en')->value('slug');

        $this->actingAs($this->user)->post("/en/petition/{$slug}/{$petition->id}/edit", $this->payload([
            'title' => 'Stop junk advertising',
        ]))->assertRedirect();

        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'status' => 'published']);
    }

    public function test_editing_a_published_petition_with_a_keyword_holds_it_for_review(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload([
            'title' => 'Clean petition title',
        ]))->assertRedirect();

        $petition = Petition::latest('id')->firstOrFail();
        $slug = $petition->translations()->where('locale', 'en')->value('slug');

        $this->actingAs($this->user)->post("/en/petition/{$slug}/{$petition->id}/edit", $this->payload([
            'title' => 'Free spins and betting bonus',
        ]))->assertRedirect();

        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'status' => 'draft']);
        $this->assertDatabaseHas('spam_logs', ['type' => 'petition.review']);
    }

    public function test_editing_a_draft_with_a_keyword_shows_review_notice_without_logging_again(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload([
            'title' => 'Stop casino advertising',
        ]))->assertRedirect();

        $petition = Petition::latest('id')->firstOrFail();
        $slug = $petition->translations()->where('locale', 'en')->value('slug');
        DB::table('spam_logs')->delete();

        $response = $this->actingAs($this->user)->post("/en/petition/{$slug}/{$petition->id}/edit", $this->payload([
            'title' => 'Free spins and betting bonus',
        ]));

        $response->assertRedirect()
            ->assertSessionHas('toast', [
                'message' => __('petition.review_required'),
                'type' => 'warning',
            ]);
        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'status' => 'draft']);
        $this->assertDatabaseMissing('spam_logs', ['type' => 'petition.review']);
    }

    public function test_hard_block_word_with_review_word_is_rejected_on_create(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload([
            'title' => 'Cheap viagra and betting offers',
        ]))->assertSessionHasErrors(['title' => __('create.err_suspicious')]);

        $this->assertDatabaseCount('petitions', 0);
    }

    public function test_hard_block_word_only_in_target_is_rejected_on_create(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload([
            'target' => 'Buy viagra cheap pills',
        ]))->assertSessionHasErrors(['title' => __('create.err_suspicious')]);

        $this->assertDatabaseCount('petitions', 0);
    }

    public function test_review_keyword_only_in_tags_stays_unpublished_and_is_logged(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload([
            'tags' => 'casino, betting',
        ]))->assertRedirect();

        $this->assertDatabaseHas('petitions', ['status' => 'draft']);
        $this->assertDatabaseHas('spam_logs', ['type' => 'petition.review']);
    }

    public function test_hard_block_word_only_in_target_is_rejected_on_update(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload())->assertRedirect();

        $petition = Petition::latest('id')->firstOrFail();
        $slug = $petition->translations()->where('locale', 'en')->value('slug');

        $this->actingAs($this->user)->post("/en/petition/{$slug}/{$petition->id}/edit", $this->payload([
            'target' => 'Buy viagra cheap pills',
        ]))->assertSessionHasErrors(['title' => __('create.err_suspicious')]);

        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'target' => null]);
    }

    public function test_hard_block_word_with_review_word_is_rejected_on_update(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload([
            'title' => 'Clean petition title',
        ]))->assertRedirect();

        $petition = Petition::latest('id')->firstOrFail();
        $slug = $petition->translations()->where('locale', 'en')->value('slug');

        $this->actingAs($this->user)->post("/en/petition/{$slug}/{$petition->id}/edit", $this->payload([
            'title' => 'Cheap viagra and betting offers',
        ]))->assertSessionHasErrors(['title' => __('create.err_suspicious')]);

        $this->assertDatabaseHas('petition_translations', ['petition_id' => $petition->id, 'title' => 'Clean petition title']);
        $this->assertDatabaseHas('petitions', ['id' => $petition->id, 'status' => 'published']);
    }

    public function test_admin_detail_sheet_shows_the_matched_keywords(): void
    {
        $this->actingAs($this->user)->post('/en/create-petition', $this->payload([
            'title' => 'Stop betting advertising',
        ]))->assertRedirect();

        $petition = Petition::latest('id')->firstOrFail();

        $level = UserLevel::create([
            'name' => 'editor_petitions',
            'visible_name' => 'Editor',
            'is_system' => false,
        ]);

        Permission::create(['level_id' => $level->id, 'module' => 'petitions', 'action' => 'edit']);
        Permission::create(['level_id' => $level->id, 'module' => 'petitions', 'action' => 'view']);

        $admin = User::factory()->create(['level_id' => $level->id, 'verified' => true]);

        $this->actingAs($admin, 'admin')
            ->get("/admin/petitions?select={$petition->id}&locale=en")
            ->assertOk()
            ->assertSee('Held for review')
            ->assertSee('betting');
    }
}
