<?php

namespace Tests\Feature;

use App\Jobs\RefreshHomepageMomentum;
use App\Models\Cause;
use App\Models\Organization;
use App\Models\Petition;
use App\Models\Signature;
use App\Models\User;
use App\Services\HomepageData;
use App\Services\HomepageMagazine;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\OrganizationCauseSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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
        config(['organizations.min_directory_count' => 20]);
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

        return $petition;
    }

    public function test_empty_homepage_has_petition_copy_search_and_how_it_works_without_placeholder_blocks(): void
    {
        $this->get('/en')->assertOk()->assertSee('Start a petition, gather signatures, deliver them.')
            ->assertSee('Anyone can launch a campaign on FreeCause, alone or with their organization.')
            ->assertSee('How it works')->assertSee('/en/search')->assertDontSee('data-home-organizations', false)
            ->assertDontSee('data-home-campaigns', false)->assertDontSee('data-home-victories', false)
            ->assertDontSee('data-home-causes', false)->assertDontSee('data-home-claim', false)
            ->assertDontSee('Change the world!')->assertDontSee('Recent User Activities');
    }

    public function test_one_gate_hides_organizations_promotion_but_not_campaigns_or_victories(): void
    {
        for ($i = 0; $i < 19; $i++) {
            $this->organization();
        }
        for ($i = 0; $i < 3; $i++) {
            $this->petition();
        }
        $victory = $this->petition(['outcome' => 'victory', 'outcome_at' => now(), 'outcome_summary' => 'A result reported by its organizer.']);
        $response = $this->get('/en')->assertOk()->assertSee('data-home-campaigns', false)->assertSee('data-home-victories', false)
            ->assertDontSee('data-home-organizations', false)->assertDontSee('Claim your organization')->assertDontSee('data-home-causes', false);
        $this->assertFalse($response->viewData('directoryReady'));
        $this->organization();
        $response = $this->get('/en')->assertOk()->assertSee('data-home-organizations', false)->assertSee('Claim your organization');
        $this->assertTrue($response->viewData('directoryReady'));
        $this->assertTrue($response->viewData('victories')->contains('id', $victory->id));
    }

    public function test_momentum_filters_private_and_small_campaigns_and_uses_cached_weekly_order(): void
    {
        $lower = $this->petition(['signature_count' => 9000, 'signatures_7d_cache' => 21]);
        $higher = $this->petition(['signature_count' => 100, 'signatures_7d_cache' => 100]);
        $middle = $this->petition(['signatures_7d_cache' => 50]);
        $this->petition(['signature_count' => 49, 'signatures_7d_cache' => 1000]);
        $this->petition(['status' => 'draft', 'signatures_7d_cache' => 2000]);
        $this->petition(['is_active' => false, 'signatures_7d_cache' => 2000]);
        DB::enableQueryLog();
        $response = $this->get('/en')->assertOk();
        $this->assertSame([$higher->id, $middle->id, $lower->id], $response->viewData('campaigns')->pluck('id')->all());
        $this->assertFalse(collect(DB::getQueryLog())->contains(fn ($q) => preg_match('/from\s+["`]?signatures["`]?\b/i', $q['query'])));
        DB::disableQueryLog();
    }

    public function test_sparse_campaigns_fall_back_only_to_eligible_campaigns_of_last_year(): void
    {
        $this->petition(['signatures_7d_cache' => 0, 'created_at' => now()->subYears(2)]);
        $one = $this->petition(['signatures_7d_cache' => 0, 'signature_count' => 1000]);
        $this->get('/en')->assertDontSee('data-home-campaigns', false);
        $two = $this->petition(['signatures_7d_cache' => 0, 'signature_count' => 2000]);
        $three = $this->petition(['signatures_7d_cache' => 0, 'signature_count' => 3000]);
        $response = $this->get('/en')->assertOk()->assertSee('Most supported campaigns of the last year');
        $this->assertSame([$three->id, $two->id, $one->id], $response->viewData('campaigns')->pluck('id')->all());
        $this->assertFalse($response->viewData('campaignsTrending'));
    }

    public function test_org_trending_and_cause_counts_exclude_private_states_and_claim_banner_requires_twenty_unclaimed(): void
    {
        $cause = Cause::first();
        $public = collect();
        for ($i = 0; $i < 20; $i++) {
            $org = $this->organization(['signatures_7d_cache' => $i < 3 ? 100 - $i : 0]);
            $org->causes()->attach($cause);
            $public->push($org);
        }
        $private = $this->organization(['status' => 'suspended', 'signatures_7d_cache' => 9000]);
        $private->causes()->attach($cause);
        $response = $this->get('/en')->assertOk()->assertSee('data-home-claim', false)->assertSee('data-home-causes', false);
        $this->assertTrue($response->viewData('organizationsTrending'));
        $this->assertSame($public->take(3)->pluck('id')->all(), $response->viewData('organizations')->pluck('id')->all());
        $this->assertSame(20, (int) $response->viewData('causes')->first()->organization_count);
        $public->last()->update(['status' => 'pending']);
        $this->get('/en')->assertDontSee('data-home-claim', false)->assertDontSee('data-home-organizations', false);
    }

    public function test_refresh_counts_only_recent_confirmed_nonspam_and_resets_stale_counters_without_changing_totals(): void
    {
        $org = $this->organization(['signatures_7d_cache' => 999]);
        $public = $this->petition(['organization_id' => $org->id, 'signatures_7d_cache' => 999]);
        $stale = $this->petition(['signatures_7d_cache' => 999]);
        $draft = $this->petition(['organization_id' => $org->id, 'status' => 'draft']);
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
        $this->assertSame($local->id, $data['campaigns']->first()->id);
        $this->assertSame($other->id, $data['campaigns']->last()->id);
        $request->attributes->set('country', 'not-a-country');
        $this->assertSame('Europa', app(HomepageData::class)->forRequest($request)['countryName']);
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

    public function test_proof_and_verified_trust_counts_have_their_own_thresholds(): void
    {
        $owner = User::factory()->create();
        for ($i = 0; $i < 49; $i++) {
            $this->organization($i < 9 ? ['status' => 'verified', 'owner_user_id' => $owner->id, 'verified_at' => now()] : []);
        }
        $this->get('/en')->assertDontSee('49 organizations · since 2010')->assertDontSee('9 verified organizations');
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
