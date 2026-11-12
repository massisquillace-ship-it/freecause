<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Page;
use App\Models\PageTranslation;
use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([LanguageSeeder::class, UserLevelSeeder::class]);
        $this->withoutVite();
        config(['app.canonical_url' => 'https://www.freecause.com', 'edge-cache.purge_enabled' => false]);
    }

    private function petition(string $locale = 'en', array $attributes = []): Petition
    {
        $p = Petition::factory()->create(array_merge(['status' => 'published', 'is_active' => true], $attributes));
        PetitionTranslation::create([
            'petition_id' => $p->id, 'locale' => $locale, 'title' => 'Save Rock &amp; Roll',
            'slug' => 'save-park-'.$p->id, 'description' => '<p>Keep our public park open &amp; accessible.</p>',
        ]);

        return $p;
    }

    public function test_petition_has_its_own_description_clean_title_and_tracking_free_canonical(): void
    {
        $p = $this->petition();
        $url = '/en/petition/save-park-'.$p->id.'/'.$p->id;
        $r = $this->get($url.'?utm_source=test&__cf_chl_tk=temporary')->assertOk();
        $r->assertSee('<meta name="description" content="Keep our public park open &amp; accessible.">', false)
            ->assertSee('rel="canonical" href="https://www.freecause.com'.$url.'"', false)
            ->assertSee('index, follow, max-snippet:-1', false)
            ->assertSee('hreflang="en"', false)->assertDontSee('hreflang="de"', false);
        $this->assertStringNotContainsString('&amp;amp;', $this->htmlHead($r->getContent()));
        $this->assertSame(1, substr_count($r->getContent(), 'rel="canonical"'));
        $this->assertStringNotContainsString('utm_source', $this->htmlHead($r->getContent()));
        $this->assertStringNotContainsString('__cf_chl_tk', $this->htmlHead($r->getContent()));
    }

    public function test_pagination_has_a_self_canonical_and_search_and_sorts_are_noindexed(): void
    {
        for ($i = 0; $i < 16; $i++) {
            $this->petition();
        }
        $r = $this->get('/en/petitions?page=2&utm_source=test')->assertOk();
        $r->assertSee('rel="canonical" href="https://www.freecause.com/en/petitions?page=2"', false)
            ->assertSee('index, follow, max-snippet:-1', false);
        $this->assertStringNotContainsString('hreflang=', $this->htmlHead($r->getContent()));
        foreach (['?q=park', '?sort=signed', '?sort=trending'] as $query) {
            $this->get('/en/petitions'.$query)->assertOk()->assertSee('content="noindex, follow"', false);
        }
    }

    public function test_goal_reached_canonicals_and_language_alternates_preserve_the_filter(): void
    {
        for ($i = 0; $i < 16; $i++) {
            $this->petition('en', ['signature_count' => 100, 'goal_signatures' => 50]);
        }
        $first = $this->get('/en/petitions?utm_source=legacy&goal=reached')->assertOk();
        $first->assertSee('rel="canonical" href="https://www.freecause.com/en/petitions?goal=reached"', false)
            ->assertSee('hreflang="it" href="https://www.freecause.com/it/petitions?goal=reached"', false)
            ->assertSee('index, follow, max-snippet:-1', false);
        $this->assertSame(1, substr_count($first->getContent(), 'rel="canonical"'));
        $second = $this->get('/en/petitions?page=2&goal=reached&utm_source=legacy')->assertOk();
        $second->assertSee('rel="canonical" href="https://www.freecause.com/en/petitions?goal=reached&amp;page=2"', false);
        $this->assertStringNotContainsString('hreflang=', $this->htmlHead($second->getContent()));
        $this->assertStringNotContainsString('utm_source', $this->htmlHead($second->getContent()));
        $this->get('/en/petitions?goal=reached&page=501')->assertOk()
            ->assertSee('rel="canonical" href="https://www.freecause.com/en/petitions?goal=reached&amp;page=501"', false);
        $this->get('/en/petitions?goal=unknown&page=2')->assertOk()
            ->assertSee('rel="canonical" href="https://www.freecause.com/en/petitions?page=2"', false);
    }

    public function test_auth_errors_drafts_and_empty_lists_are_noindexed_without_hiding_old_petitions(): void
    {
        $this->get('/en/login')->assertOk()->assertSee('content="noindex, follow"', false);
        $this->get('/admin/login')->assertOk()->assertSee('content="noindex, nofollow"', false);
        $this->get('/en/petition/not-found/999999')->assertNotFound()->assertSee('content="noindex, follow"', false);
        $this->get('/de/petitions')->assertOk()->assertSee('content="noindex, follow"', false);
        $owner = User::factory()->create(['verified' => true]);
        $draft = $this->petition('en', ['status' => 'draft', 'user_id' => $owner->id]);
        $this->actingAs($owner)->get('/en/petition/save-park-'.$draft->id.'/'.$draft->id)
            ->assertOk()->assertSee('content="noindex, follow"', false);
        $old = $this->petition('en', ['created_at' => '2013-01-01 00:00:00']);
        $this->get('/en/petition/save-park-'.$old->id.'/'.$old->id)
            ->assertOk()->assertSee('index, follow, max-snippet:-1', false);
    }

    public function test_categories_and_editorial_pages_advertise_only_real_translations(): void
    {
        $category = Category::factory()->create(['is_active' => true]);
        foreach (['en' => 'nature', 'de' => 'natur'] as $locale => $slug) {
            CategoryTranslation::create(['category_id' => $category->id, 'locale' => $locale, 'name' => ucfirst($slug), 'slug' => $slug]);
        }
        $this->petition('de', ['category_id' => $category->id]);
        $r = $this->get('/de/petitions/category-natur-'.$category->id)->assertOk();
        $r->assertSee('hreflang="de" href="https://www.freecause.com/de/petitions/category-natur-'.$category->id.'"', false)
            ->assertDontSee('hreflang="en"', false)
            ->assertDontSee('hreflang="fr"', false);
        $page = Page::create();
        foreach (['en' => 'about', 'de' => 'uber-uns'] as $locale => $slug) {
            PageTranslation::create(['page_id' => $page->id, 'locale' => $locale, 'slug' => $slug, 'title' => 'About FreeCause', 'content' => '<p>Our mission and real public campaigns.</p>', 'published' => true]);
        }
        $this->get('/de/uber-uns')->assertOk()
            ->assertSee('name="description" content="Our mission and real public campaigns."', false)
            ->assertSee('hreflang="en" href="https://www.freecause.com/en/about"', false)
            ->assertDontSee('hreflang="it"', false);
    }

    public function test_localized_home_pages_remain_indexable_with_translated_titles(): void
    {
        $this->petition('de');
        $this->get('/de?utm_source=test')->assertOk()
            ->assertSee('Petitionen | FreeCause')
            ->assertSee('index, follow, max-snippet:-1', false)
            ->assertSee('hreflang="en" href="https://www.freecause.com/en"', false);
    }

    private function htmlHead(string $html): string
    {
        return explode('</head>', $html, 2)[0];
    }
}
