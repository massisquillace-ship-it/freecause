<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PetitionEditPdfHintsTest extends TestCase
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

    private function petition(array $over = []): Petition
    {
        return Petition::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $this->category->id,
            'is_active' => true,
        ] + $over);
    }

    public function test_edit_form_warns_when_petition_is_held_for_review(): void
    {
        $petition = $this->petition(['status' => 'draft', 'held_by_admin' => true]);
        PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Held Petition', 'slug' => 'held-petition', 'description' => 'Original description.']);

        $this->actingAs($this->user)->get("/en/petition/held-petition/{$petition->id}/edit")
            ->assertOk()
            ->assertSee('Your petition is on hold for review by our team. It will be published once it is approved.')
            ->assertDontSee('petition.held_for_review');
    }

    public function test_edit_form_has_no_warning_when_not_held_for_review(): void
    {
        $petition = $this->petition(['status' => 'draft', 'held_by_admin' => false]);
        PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Normal Petition', 'slug' => 'normal-petition', 'description' => 'Original description.']);

        $this->actingAs($this->user)->get("/en/petition/normal-petition/{$petition->id}/edit")
            ->assertOk()
            ->assertDontSee('Your petition is on hold for review by our team. It will be published once it is approved.');
    }

    public function test_pdf_view_notes_the_capped_signature_list(): void
    {
        $petition = $this->petition(['status' => 'published', 'signature_count' => 1500]);
        $tr = PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Capped Petition', 'slug' => 'capped-petition', 'description' => 'Original description.']);

        $html = view('petition.pdf', [
            'petition' => $petition,
            'tr' => $tr,
            'locale' => 'en',
            'signatures' => collect(),
            'isCapped' => true,
        ])->render();

        $this->assertStringContainsString('This PDF lists the first 1000 signatures. Download the full list as TXT or CSV.', $html);
        $this->assertStringNotContainsString('pet.pdf_capped', $html);
    }

    public function test_pdf_view_has_no_cap_note_below_the_limit(): void
    {
        $petition = $this->petition(['status' => 'published', 'signature_count' => 500]);
        $tr = PetitionTranslation::create(['petition_id' => $petition->id, 'locale' => 'en', 'title' => 'Small Petition', 'slug' => 'small-petition', 'description' => 'Original description.']);

        $html = view('petition.pdf', [
            'petition' => $petition,
            'tr' => $tr,
            'locale' => 'en',
            'signatures' => collect(),
            'isCapped' => false,
        ])->render();

        $this->assertStringNotContainsString('This PDF lists the first 1000 signatures. Download the full list as TXT or CSV.', $html);
    }
}
