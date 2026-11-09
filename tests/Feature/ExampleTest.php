<?php

namespace Tests\Feature;

use App\Models\Petition;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_a_successful_response(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }

    public function test_empty_homepage_has_no_placeholder_campaign_block(): void
    {
        $this->seed(LanguageSeeder::class);

        $response = $this->get('/en');

        $response->assertOk()->assertDontSee('data-home-campaigns', false)
            ->assertDontSee('featured-petition-tab-pane', false)
            ->assertSee('Start a petition, gather signatures, deliver them.');
    }

    public function test_homepage_eligible_campaign_links_to_its_detail_page(): void
    {
        $this->seed([LanguageSeeder::class, UserLevelSeeder::class]);

        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
            'is_featured' => true,
            'signature_count' => 100,
        ]);
        $translation = $petition->translations()->create([
            'locale' => 'en',
            'title' => 'Featured Test Petition',
            'slug' => 'featured-test-petition',
            'description' => 'A featured petition used for homepage regression coverage.',
            'is_featured' => true,
        ]);

        for ($i = 0; $i < 2; $i++) {
            $other = Petition::factory()->create(['status' => 'published', 'is_active' => true, 'signature_count' => 100]);
            $other->translations()->create(['locale' => 'en', 'title' => 'Other campaign '.$i, 'slug' => 'other-campaign-'.$i, 'description' => 'A public campaign.']);
        }

        $response = $this->get('/en');
        $expectedUrl = lroute('petition.show', [
            'slug' => $translation->slug,
            'id' => $petition->id,
        ]);

        $response
            ->assertOk()
            ->assertSee('Featured Test Petition')
            ->assertSee('href="'.$expectedUrl.'"', false);
    }
}
