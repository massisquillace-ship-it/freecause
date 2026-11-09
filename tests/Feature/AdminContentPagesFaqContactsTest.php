<?php

namespace Tests\Feature;

use App\Models\PageTranslation;
use App\Models\User;
use App\Models\UserLevel;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminContentPagesFaqContactsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
    }

    public function test_faq_page_loads_in_english_with_seed_content(): void
    {
        $this->get('/en/faqs')
            ->assertOk()
            ->assertSee('Faq')
            ->assertSee('Are online petitions useful?', false)
            ->assertSee('For promoters of petitions', false);
    }

    public function test_faq_page_falls_back_to_english_for_other_locales(): void
    {
        $this->get('/it/faqs')
            ->assertOk()
            ->assertSee('Are online petitions useful?', false);
    }

    public function test_faq_page_renders_the_accordion_dropdowns(): void
    {
        $this->get('/en/faqs')
            ->assertOk()
            ->assertSee('aria-label="'.__('ui.breadcrumb').'"', false)
            ->assertSee('accordion-button collapsed', false)
            ->assertSee('data-bs-toggle="collapse"', false);
    }

    public function test_contacts_page_loads_in_english(): void
    {
        $this->get('/en/contacts')
            ->assertOk()
            ->assertSee(__('contacts.title', [], 'en'));
    }

    public function test_contacts_page_renders_the_contact_form(): void
    {
        $this->get('/en/contacts')
            ->assertOk()
            ->assertSee('id="contactForm"', false)
            ->assertSee('action="'.route('contacts.submit', 'en').'"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="text"', false)
            ->assertSee('data-sending="', false);
    }

    public function test_admin_pages_list_shows_the_seeded_slugs(): void
    {
        $admin = User::factory()->create([
            'level_id' => UserLevel::where('name', 'admin')->value('id'),
            'verified' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->get('/admin/pages')
            ->assertOk()
            ->assertSee('faqs')
            ->assertSee('contacts');
    }

    public function test_seed_migration_is_idempotent(): void
    {
        $before = PageTranslation::query()->where('locale', 'en')->whereIn('slug', ['faqs', 'contacts'])->count();

        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_05_130000_seed_faq_contacts_pages.php']);

        $this->assertSame($before, PageTranslation::query()->where('locale', 'en')->whereIn('slug', ['faqs', 'contacts'])->count());
    }
}
