<?php

namespace Tests\Feature;

use App\Jobs\RefreshHomepageMomentum;
use App\Models\Category;
use App\Models\Cause;
use App\Models\Organization;
use App\Models\Petition;
use App\Models\Signature;
use App\Models\User;
use App\Services\HomepageData;
use App\Services\HomepageMagazine;
use Database\Seeders\CategorySeeder;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\OrganizationCauseSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomepageOrganizationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class, OrganizationCauseSeeder::class]);
        $this->mock(HomepageMagazine::class)->shouldReceive('posts')->andReturn([]);
        config(['organizations.min_directory_count' => 1]);
    }

    private function organization(array $data = []): Organization
    {
        return Organization::create(array_merge([
            'name' => 'Test organization '.bin2hex(random_bytes(4)), 'slug' => 'test-'.bin2hex(random_bytes(5)),
            'country' => 'IT', 'type' => 'association', 'status' => 'unclaimed', 'source' => 'admin', 'verification_reference' => '',
        ], $data));
    }

    private function petition(array $data = [], string $locale = 'en'): Petition
    {
        $petition = Petition::factory()->create(array_merge([
            'status' => 'published', 'is_active' => 1, 'signature_count' => 100, 'goal_signatures' => 1000, 'signatures_7d_cache' => 25, 'country' => 'IT',
        ], $data));
        $petition->translations()->create(['locale' => $locale, 'title' => 'Public campaign '.$petition->id, 'slug' => 'public-campaign-'.$petition->id, 'description' => 'A public campaign description.']);

        Signature::factory()->create(['petition_id' => $petition->id, 'confirmed' => true, 'is_spam' => false]);
        (new RefreshHomepageMomentum)->handle();

        return $petition;
    }

    public function test_empty_homepage_has_petition_copy_search_and_how_it_works_without_placeholder_blocks(): void
    {
        $this->get('/en')->assertOk()->assertSee('Start a petition, gather signatures, deliver them.')
            ->assertSee('Anyone can launch a campaign on FreeCause, alone or with their organization.')
            ->assertSee('How it works')->assertSee('/en/search')->assertSee('data-home-organizations', false)
            ->assertSee('data-home-campaigns', false)->assertSee('data-home-victories', false)
            ->assertSee('No confirmed signatures yet.')->assertSee('No petitions have reached their signature goal yet in this language.')
            ->assertDontSee('data-home-causes', false)->assertDontSee('data-home-claim', false)
            ->assertDontSee('Change the world!')->assertSee('Recent User Activities')->assertSee('no recent activity yet');
    }

    public function test_one_public_organization_and_one_single_signature_campaign_are_visible(): void
    {
        $org = $this->organization();
        $petition = $this->petition(['signature_count' => 1]);
        $response = $this->get('/en')->assertOk()->assertSee($org->name)->assertSee('data-home-campaigns', false)
            ->assertSee('Public campaign '.$petition->id)->assertSee('No petitions have reached their signature goal yet in this language.');
        $this->assertTrue($response->viewData('directoryReady'));
        $this->assertCount(1, $response->viewData('organizations'));
        $this->assertCount(1, $response->viewData('campaigns'));
    }

    public function test_homepage_uses_uploaded_and_legacy_covers_with_a_default_for_missing_images(): void
    {
        $uploaded = $this->petition(['cover_image' => 'https://images.example.com/uploaded.jpg']);
        $legacy = $this->petition(['image_url' => 'https://images.example.com/legacy.jpg', 'signature_count' => 10, 'goal_signatures' => 10]);
        $missing = $this->petition(['cover_image' => 'missing-cover.jpg']);

        $this->get('/en')->assertOk()
            ->assertSee($uploaded->coverUrl(), false)
            ->assertSee($legacy->coverUrl(), false)
            ->assertSee($missing->coverUrl(), false)
            ->assertSee('data-home-petition-cover', false)
            ->assertSee('data-home-petition-introduction', false)
            ->assertSee('/en/how-to-write-a-petition', false)
            ->assertSee('/en/create-petition', false)
            ->assertSee('Download signatures in PDF or CSV format');
    }

    public function test_directory_count_query_runs_once_across_requests(): void
    {
        config(['organizations.min_directory_count' => 1]);
        $this->organization();

        DB::enableQueryLog();
        $this->app->instance('request', Request::create('/en'));
        Organization::directoryReady();
        $this->app->instance('request', Request::create('/it'));
        Organization::directoryReady();
        $counts = collect(DB::getQueryLog())->filter(
            fn ($q) => str_contains($q['query'], 'count(*)') && str_contains($q['query'], 'organizations')
        );
        DB::disableQueryLog();

        $this->assertCount(1, $counts);
    }

    public function test_approving_an_organization_refreshes_the_directory_count(): void
    {
        config(['organizations.min_directory_count' => 2]);
        $this->organization(['status' => 'unclaimed']);
        $pending = $this->organization(['status' => 'pending']);

        $this->assertFalse(Organization::directoryReady());

        $pending->update(['status' => 'verified', 'owner_user_id' => User::factory()->create()->id]);

        $this->assertTrue(Organization::directoryReady());
    }

    public function test_recent_signature_order_includes_small_old_petitions_and_excludes_invalid_signatures(): void
    {
        $older = $this->petition(['signature_count' => 9000]);
        $latest = $this->petition(['signature_count' => 1, 'created_at' => now()->subYears(10)]);
        Signature::where('petition_id', $older->id)->update(['created_at' => now()->subDays(20)]);
        Signature::where('petition_id', $latest->id)->update(['created_at' => now()->subDays(10)]);
        foreach ([['confirmed' => false], ['is_spam' => true], ['created_at' => now()->addDay()]] as $invalid) {
            Signature::factory()->create(array_merge(['petition_id' => $older->id, 'confirmed' => true, 'is_spam' => false], $invalid));
        }
        $this->petition(['status' => 'draft']);
        $this->petition(['is_active' => false]);
        (new RefreshHomepageMomentum)->handle();
        DB::enableQueryLog();
        $response = $this->get('/en')->assertOk()->assertSee('Recently signed petitions');
        $this->assertSame([$latest->id, $older->id], $response->viewData('campaigns')->pluck('id')->all());
        $signatureQueries = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/from\s+["`]?signatures["`]?\b/i', $q['query']));
        $this->assertTrue($signatureQueries->contains(fn ($q) => str_contains($q['query'], 'limit 2000')));
        $this->assertFalse($signatureQueries->contains(fn ($q) => str_contains(strtolower($q['query']), 'group by')));
        DB::disableQueryLog();
    }

    public function test_recent_signature_cache_keeps_six_campaigns_per_locale_independently(): void
    {
        $petitions = collect();
        for ($i = 0; $i < 8; $i++) {
            $petitions->push($this->petition());
        }
        $first = $petitions->first();
        $first->translations()->create(['locale' => 'es', 'title' => 'Primera campaña', 'slug' => 'primera-campana', 'description' => 'Una campaña pública.']);
        (new RefreshHomepageMomentum)->handle();
        $lists = Cache::get('homepage:recently-signed');
        $this->assertSame($petitions->reverse()->take(6)->pluck('id')->values()->all(), $lists['en']);
        $this->assertSame([$first->id], $lists['es']);
        $this->get('/es')->assertOk()->assertSee('Primera campaña');
    }

    public function test_one_public_organization_and_one_unclaimed_page_enable_cards_causes_and_claim_banner(): void
    {
        $cause = Cause::first();
        $public = $this->organization();
        $public->causes()->attach($cause);
        $private = $this->organization(['status' => 'suspended']);
        $private->causes()->attach($cause);
        $response = $this->get('/en')->assertOk()->assertSee('data-home-claim', false);
        $this->assertSame([$public->id], $response->viewData('organizations')->pluck('id')->all());
        $public->update(['status' => 'pending']);
        $this->get('/en')->assertDontSee('data-home-claim', false)->assertSee('Organizations will appear here as they become public.');
    }

    public function test_homepage_goals_include_personal_and_organization_petitions_only_in_the_current_locale(): void
    {
        $personal = $this->petition(['country' => 'US', 'signature_count' => 1000, 'goal_signatures' => 1000, 'created_at' => now()->subYears(13)]);
        $org = $this->organization(['status' => 'verified', 'owner_user_id' => User::factory()->create()->id]);
        $organized = $this->petition(['country' => 'BR', 'organization_id' => $org->id, 'signature_count' => 1200, 'goal_signatures' => 1000]);
        $this->petition(['signature_count' => 999, 'goal_signatures' => 1000, 'outcome' => 'victory', 'outcome_at' => now()]);
        $this->petition(['signature_count' => 100, 'goal_signatures' => 0]);
        $this->petition(['signature_count' => 1000, 'status' => 'draft']);
        $this->petition(['signature_count' => 1000, 'is_active' => false]);
        $spanish = $this->petition(['signature_count' => 1000], 'es');
        $response = $this->get('/en')->assertOk()->assertSee('Signature goal reached');
        $this->assertSame([$organized->id, $personal->id], $response->viewData('victories')->pluck('id')->all());
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $this->assertSame(1, (new \DOMXPath($dom))->query('//section[@data-home-victories]//*[@data-organization-verified]')->length);
        $this->get('/es')->assertOk()->assertViewHas('victories', fn ($rows) => $rows->pluck('id')->all() === [$spanish->id]);
    }

    public function test_goal_reached_listing_is_complete_paginated_language_filtered_and_cache_isolated(): void
    {
        $winner = $this->petition(['signature_count' => 1000]);
        $open = $this->petition(['signature_count' => 10]);
        $this->get('/en/petitions')->assertOk()->assertSee('Public campaign '.$open->id);
        $filtered = $this->get('/en/petitions?goal=reached')->assertOk()
            ->assertSee('Public campaign '.$winner->id)->assertDontSee('Public campaign '.$open->id)
            ->assertSee('name="goal" value="reached"', false);
        $this->assertSame([$winner->id], $filtered->viewData('petitions')->pluck('id')->all());
        $this->get('/en/petitions?goal=reached&page=501')->assertOk()->assertViewHas('petitions', fn ($rows) => $rows->currentPage() === 501);
        $this->get('/en/petitions?goal=reached&sort=signed')->assertOk()->assertViewHas('petitions', fn ($rows) => $rows->pluck('id')->all() === [$winner->id]);
        $this->get('/en/petitions')->assertOk()->assertSee('Public campaign '.$open->id);
    }

    public function test_homepage_shows_all_active_petition_categories_even_without_organizations(): void
    {
        $this->seed(CategorySeeder::class);
        $response = $this->get('/it')->assertOk()->assertSee('data-home-categories', false);
        $categories = $response->viewData('categories');
        $this->assertSame(Category::where('is_active', true)->count(), $categories->count());
        foreach ($categories as $category) {
            $response->assertSee($category->tr_name)->assertSee('/it/petitions/category-'.$category->tr_slug.'-'.$category->id);
        }
        $this->assertFalse($response->viewData('directoryReady'));
        $this->assertDatabaseCount('organizations', 0);
    }

    public function test_refresh_counts_only_recent_confirmed_nonspam_and_resets_stale_counters_without_changing_totals(): void
    {
        $org = $this->organization(['signatures_7d_cache' => 999]);
        $public = $this->petition(['organization_id' => $org->id, 'signatures_7d_cache' => 999]);
        $stale = $this->petition(['signatures_7d_cache' => 999]);
        $draft = $this->petition(['organization_id' => $org->id, 'status' => 'draft']);
        Signature::query()->delete();
        foreach ([
            [$public->id, true, false, now()->subDay()], [$public->id, true, false, now()->subDays(6)],
            [$public->id, false, false, now()], [$public->id, true, true, now()],
            [$public->id, true, false, now()->subDays(8)], [$public->id, true, false, now()->addDay()],
            [$draft->id, true, false, now()],
        ] as [$id, $confirmed, $spam, $at]) {
            Signature::factory()->create(['petition_id' => $id, 'confirmed' => $confirmed, 'is_spam' => $spam, 'created_at' => $at]);
        }
        (new RefreshHomepageMomentum)->handle();
        $this->assertSame(2, (int) $public->fresh()->signatures_7d_cache);
        $this->assertSame(0, (int) $stale->fresh()->signatures_7d_cache);
        $this->assertSame(2, (int) $org->fresh()->signatures_7d_cache);
        $this->assertSame(100, (int) $public->fresh()->signature_count);
        (new RefreshHomepageMomentum)->handle();
        $this->assertSame(2, (int) $org->fresh()->signatures_7d_cache);
    }

    public function test_country_context_is_localized_and_prioritized_without_a_geoip_request(): void
    {
        app()->setLocale('it');
        $other = $this->petition(['country' => 'FR', 'signatures_7d_cache' => 100], 'it');
        $local = $this->petition(['country' => 'IT', 'signatures_7d_cache' => 30], 'it');
        $this->petition(['country' => 'IT', 'signatures_7d_cache' => 20], 'it');
        $request = Request::create('/it');
        $request->attributes->set('country', 'IT');
        $data = app(HomepageData::class)->forRequest($request);
        $this->assertSame('Italia', $data['countryName']);
        $this->assertTrue($data['campaigns']->contains('id', $local->id));
        $this->assertTrue($data['campaigns']->contains('id', $other->id));
        $request->attributes->set('country', 'not-a-country');
        $this->assertSame('mondo', app(HomepageData::class)->forRequest($request)['countryName']);
    }

    public function test_homepage_respects_nickname_choice_and_invalidates_it_after_changes(): void
    {
        $author = User::factory()->create(['name' => 'Private Full Name', 'first_name' => 'Private', 'last_name' => 'Name', 'identify_mode' => 'nick', 'nickname' => 'public-nick']);
        for ($i = 0; $i < 3; $i++) {
            $this->petition(['user_id' => $author->id]);
        }
        $this->get('/en')->assertOk()->assertSee('public-nick')->assertDontSee('Private Full Name')->assertDontSee('Started by Private');
        $author->update(['nickname' => 'updated-nick']);
        $this->get('/en')->assertSee('updated-nick')->assertDontSee('public-nick');
        $author->update(['nickname' => null]);
        $this->get('/en')->assertSee('Campaign creator')->assertDontSee('Private Full Name')->assertDontSee('Started by Private');
    }

    public function test_trending_organizations_use_country_context_and_global_fallback_is_labeled_honestly(): void
    {
        config(['organizations.min_directory_count' => 1]);
        $local = collect();
        for ($i = 0; $i < 3; $i++) {
            $local->push($this->organization(['country' => 'IT', 'signatures_7d_cache' => 30 - $i]));
        }
        $foreign = $this->organization(['country' => 'FR', 'signatures_7d_cache' => 999]);
        app()->setLocale('en');
        $request = Request::create('/en');
        $request->attributes->set('country', 'IT');
        $data = app(HomepageData::class)->forRequest($request);
        $this->assertTrue($data['organizationsTrending']);
        $this->assertSame($local->pluck('id')->all(), $data['organizations']->pluck('id')->all());
        $request->attributes->set('country', 'DE');
        $data = app(HomepageData::class)->forRequest($request);
        $this->assertFalse($data['organizationsTrending']);
        $this->assertTrue($data['organizations']->contains('id', $foreign->id));
    }

    public function test_deleted_author_is_not_retained_in_cached_homepage_campaigns(): void
    {
        $author = User::factory()->create(['identify_mode' => 'nick', 'nickname' => 'deleted-public-author']);
        for ($i = 0; $i < 3; $i++) {
            $this->petition(['user_id' => $author->id]);
        }
        $this->get('/en')->assertSee('deleted-public-author');
        // The account-deletion flow uses a bulk delete, bypassing model observers.
        User::whereKey($author->id)->delete();
        $this->get('/en')->assertOk()->assertDontSee('deleted-public-author')->assertSee('Campaign creator');
        $this->assertDatabaseCount('petitions', 3);
    }

    public function test_proof_and_verified_trust_counts_show_actual_small_counts(): void
    {
        $owner = User::factory()->create();
        for ($i = 0; $i < 49; $i++) {
            $this->organization($i < 9 ? ['status' => 'verified', 'owner_user_id' => $owner->id, 'verified_at' => now()] : []);
        }
        $this->get('/en')->assertSee('49 organizations · since 2010')->assertSee('9 verified organizations');
        $this->organization(['status' => 'verified', 'owner_user_id' => $owner->id, 'verified_at' => now()]);
        $this->get('/en')->assertSee('50 organizations · since 2010')->assertSee('10 verified organizations');
    }

    public function test_mixed_search_defaults_to_organizations_and_uses_existing_petition_search(): void
    {
        $org = $this->organization(['name' => 'Unique public campaign organization']);
        $this->organization(['name' => 'Unique public campaign private', 'status' => 'pending']);
        $petition = $this->petition();
        $this->get('/en/search?q=public%20campaign')->assertOk()->assertSee($org->name)->assertDontSee('public campaign private')
            ->assertViewHas('tab', 'organizations')->assertViewHas('petitions', fn ($rows) => $rows->contains('id', $petition->id));
        $this->get('/en/search?q=public%20campaign&tab=petitions')->assertSee('Public campaign '.$petition->id)->assertViewHas('tab', 'petitions');
        $this->get('/en/search?q='.str_repeat('a', 121))->assertSessionHasErrors('q');
    }
}
