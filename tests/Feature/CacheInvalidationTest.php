<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Support\CacheVersion;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
    }

    public function test_bump_changes_version(): void
    {
        $before = CacheVersion::get('test-group');

        $this->assertSame($before, CacheVersion::get('test-group'));

        CacheVersion::bump('test-group');

        $after = CacheVersion::get('test-group');

        $this->assertNotSame($before, $after);
    }

    public function test_petition_save_bumps_home_list_and_sitemap_versions(): void
    {
        $homeBefore = CacheVersion::get('home');
        $listBefore = CacheVersion::get('list');
        $sitemapBefore = CacheVersion::get('sitemap');

        Petition::factory()->create();

        $this->assertNotSame($homeBefore, CacheVersion::get('home'));
        $this->assertNotSame($listBefore, CacheVersion::get('list'));
        $this->assertNotSame($sitemapBefore, CacheVersion::get('sitemap'));
    }

    public function test_category_save_bumps_cache_versions(): void
    {
        $homeBefore = CacheVersion::get('home');
        $listBefore = CacheVersion::get('list');
        $sitemapBefore = CacheVersion::get('sitemap');

        Category::factory()->create();

        $this->assertNotSame($homeBefore, CacheVersion::get('home'));
        $this->assertNotSame($listBefore, CacheVersion::get('list'));
        $this->assertNotSame($sitemapBefore, CacheVersion::get('sitemap'));
    }

    public function test_petition_translation_save_bumps_home_and_list(): void
    {
        $petition = Petition::factory()->create();

        $homeBefore = CacheVersion::get('home');
        $listBefore = CacheVersion::get('list');

        PetitionTranslation::factory()->create(['petition_id' => $petition->id]);

        $this->assertNotSame($homeBefore, CacheVersion::get('home'));
        $this->assertNotSame($listBefore, CacheVersion::get('list'));
    }

    public function test_category_translation_save_bumps_home_and_sitemap(): void
    {
        $category = Category::factory()->create();

        $homeBefore = CacheVersion::get('home');
        $sitemapBefore = CacheVersion::get('sitemap');

        CategoryTranslation::create([
            'category_id' => $category->id,
            'locale' => 'en',
            'name' => 'Original Category Name',
            'slug' => 'original-category-name',
        ]);

        $this->assertNotSame($homeBefore, CacheVersion::get('home'));
        $this->assertNotSame($sitemapBefore, CacheVersion::get('sitemap'));
    }

    public function test_category_translation_delete_bumps_home_and_sitemap(): void
    {
        $category = Category::factory()->create();

        $translation = CategoryTranslation::create([
            'category_id' => $category->id,
            'locale' => 'en',
            'name' => 'Original Category Name',
            'slug' => 'original-category-name',
        ]);

        $homeBefore = CacheVersion::get('home');
        $sitemapBefore = CacheVersion::get('sitemap');

        $translation->delete();

        $this->assertNotSame($homeBefore, CacheVersion::get('home'));
        $this->assertNotSame($sitemapBefore, CacheVersion::get('sitemap'));
    }

    public function test_homepage_renders_new_petition_title_immediately_after_save(): void
    {
        $petition = Petition::factory()->create([
            'status' => 'published',
            'is_active' => true,
            'is_featured' => true,
            'signature_count' => 100,
        ]);

        $translation = PetitionTranslation::factory()->create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Original Homepage Petition Title',
            'slug' => 'original-homepage-petition-title',
            'is_featured' => true,
        ]);

        for ($i = 0; $i < 2; $i++) {
            $other = Petition::factory()->create(['status' => 'published', 'is_active' => true, 'signature_count' => 100]);
            PetitionTranslation::factory()->create(['petition_id' => $other->id, 'locale' => 'en']);
        }

        $this->get('/en')
            ->assertOk()
            ->assertSee('Original Homepage Petition Title');

        $translation->update(['title' => 'Renamed Homepage Petition Title']);

        $this->get('/en')
            ->assertOk()
            ->assertSee('Renamed Homepage Petition Title')
            ->assertDontSee('Original Homepage Petition Title');
    }
}
