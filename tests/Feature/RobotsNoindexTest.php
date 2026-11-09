<?php

namespace Tests\Feature;

use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RobotsNoindexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
    }

    public function test_robots_allows_crawl_for_noindexed_paths(): void
    {
        $robots = file_get_contents(public_path('robots.txt'));

        foreach (['/widgets/', '/widget-render/', '/oauth/', '/*?status=', '/*?view=pages'] as $path) {
            $this->assertStringNotContainsString('Disallow: '.$path, $robots);
        }

        $this->assertStringContainsString('Disallow: /admin', $robots);
        $this->assertStringContainsString('Disallow: /api/', $robots);
    }

    public function test_noindex_meta_on_status_query(): void
    {
        $this->get('/en?status=200')
            ->assertOk()
            ->assertSee('name="robots"', false)
            ->assertSee('noindex, follow');
    }

    public function test_noindex_meta_on_view_pages_query(): void
    {
        $this->get('/en/donations?view=pages')
            ->assertOk()
            ->assertSee('name="robots"', false)
            ->assertSee('noindex, follow');
    }

    public function test_noindex_meta_on_user_path(): void
    {
        $user = User::factory()->create(['verified' => true]);

        $this->get('/en/user/profile/'.$user->id)
            ->assertOk()
            ->assertSee('name="robots"', false)
            ->assertSee('noindex, follow');
    }

    public function test_noindex_header_on_widget_routes(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Widget Petition',
            'slug' => 'widget-petition',
            'description' => 'Description',
        ]);

        $this->get("/widgets/pbadge/{$petition->id}/728x90")
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, follow');

        $this->get("/widget-render/pbadge/{$petition->id}/728x90")
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, follow');
    }
}
