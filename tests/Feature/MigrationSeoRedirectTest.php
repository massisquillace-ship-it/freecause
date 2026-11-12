<?php

namespace Tests\Feature;

use App\Models\Petition;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationSeoRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([LanguageSeeder::class, UserLevelSeeder::class]);
    }

    private function petition(string $locale = 'en', array $attributes = []): Petition
    {
        $petition = Petition::factory()->create(array_merge(['status' => 'published', 'is_active' => true], $attributes));
        $petition->translations()->create(['locale' => $locale, 'title' => 'Help Liz', 'slug' => 'help-liz-'.$petition->id, 'description' => 'A public campaign.']);

        return $petition;
    }

    public function test_german_contact_aliases_redirect_directly_to_the_existing_contact_page(): void
    {
        foreach (['de', 'de_CH', 'de_AT', 'de_DE'] as $locale) {
            $this->get('/'.$locale.'/kontakte?utm_source=legacy')
                ->assertStatus(301)->assertHeader('Location', 'http://localhost/de/contacts?utm_source=legacy');
        }
        $this->get('/de/contacts')->assertOk()->assertHeaderMissing('Location');
        $this->get('/de/kontakte/unrelated')->assertNotFound();
    }

    public function test_signature_aliases_use_the_current_slug_in_one_hop_and_preserve_pagination(): void
    {
        $petition = $this->petition();
        foreach (['/signatures', '/en/signatures'] as $prefix) {
            $this->get($prefix.'/old-slug/'.$petition->id.'?page=2&utm_source=legacy')
                ->assertStatus(301)->assertHeader('Location', 'http://localhost/en/petition/help-liz-'.$petition->id.'/'.$petition->id.'/signatures?page=2&utm_source=legacy');
        }
        $this->get('/en/signatures/old-slug/'.$petition->id.'/page-3')
            ->assertStatus(301)->assertHeader('Location', 'http://localhost/en/petition/help-liz-'.$petition->id.'/'.$petition->id.'/signatures?page=3');
        $this->get('/en/petition/help-liz-'.$petition->id.'/'.$petition->id.'/signatures?page=2')
            ->assertOk()->assertHeaderMissing('Location')->assertSee('noindex, follow');
    }

    public function test_signature_aliases_follow_the_existing_translation_fallback(): void
    {
        $petition = $this->petition();
        $this->get('/de/signatures/obsolete/'.$petition->id)
            ->assertStatus(301)->assertHeader('Location', 'http://localhost/en/petition/help-liz-'.$petition->id.'/'.$petition->id.'/signatures');
    }

    public function test_missing_removed_draft_inactive_and_malformed_signature_urls_stay_404(): void
    {
        foreach ([['status' => 'draft'], ['is_active' => false]] as $attributes) {
            $petition = $this->petition('en', $attributes);
            $this->get('/en/signatures/help-liz/'.$petition->id)->assertNotFound()->assertHeaderMissing('Location');
        }
        $removed = $this->petition();
        $id = $removed->id;
        $removed->delete();
        foreach (['/signatures/help-liz/'.$id, '/en/signatures/help-liz/999999999', '/en/signatures/help-liz/not-an-id', '/en/signatures/help-liz'] as $url) {
            $this->get($url)->assertNotFound()->assertHeaderMissing('Location');
        }
    }
}
