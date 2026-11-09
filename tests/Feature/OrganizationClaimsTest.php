<?php

namespace Tests\Feature;

use App\Jobs\SendOrganizationNotification;
use App\Mail\OrganizationStatusMail;
use App\Models\Cause;
use App\Models\Organization;
use App\Models\OrganizationNotification;
use App\Models\Petition;
use App\Models\User;
use App\Models\UserLevel;
use App\Services\OrganizationLifecycle;
use App\Support\Settings;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\OrganizationCauseSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrganizationClaimsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class, OrganizationCauseSeeder::class]);
        config(['organizations.public_launch' => false, 'organizations.min_directory_count' => 20, 'organizations.applications_open' => true, 'organizations.notifications_enabled' => false]);
        Cache::flush();
        Mail::fake();
    }

    private function owner(): User
    {
        return User::factory()->create(['verified' => true, 'locale' => 'en', 'gender' => 'none']);
    }

    private function admin(): User
    {
        return User::factory()->create(['level_id' => UserLevel::where('name', 'admin')->value('id'), 'verified' => true]);
    }

    private function org(array $overrides = []): Organization
    {
        return Organization::create(array_replace([
            'name' => 'Catalog '.bin2hex(random_bytes(4)), 'slug' => 'catalog-'.bin2hex(random_bytes(4)),
            'country' => 'IT', 'type' => 'other', 'status' => 'unclaimed', 'source' => 'admin', 'verification_reference' => '',
        ], $overrides));
    }

    private function claimData(): array
    {
        return ['type' => 'association', 'verification_reference' => 'PRIVATE CLAIM EVIDENCE', 'representation_confirmed' => 1, 'terms_accepted' => 1];
    }

    private function navigationCount(string $html): int
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);

        return (new \DOMXPath($dom))->query('//a[normalize-space(.)="For organizations" or normalize-space(.)="Organizations"]')->length;
    }

    public function test_shared_header_cookie_anchor_and_contextual_mobile_cta(): void
    {
        $html = $this->get('/en/organizations/terms')->assertOk()->getContent();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//a[@data-fc-cookie-settings][@href]')->length);
        $this->assertSame(0, $xpath->query('//nav[@data-bottom-nav]//a[contains(@href,"/organizations/claim-search")]')->length);
        $this->assertSame(1, $xpath->query('//nav[@data-bottom-nav]//a[contains(@href,"/create-petition")]')->length);
        $html = $this->get('/en')->assertOk()->getContent();
        @$dom->loadHTML($html);
        $this->assertSame(1, (new \DOMXPath($dom))->query('//nav[@data-bottom-nav]//a[contains(@href,"/create-petition")]')->length);
        $view = $this->view('partials.navbar', ['navbarContent' => ['nav_explore' => 'https://www.freecause.com/en/petitions']]);
        $view->assertDontSee('>https://www.freecause.com/en/petitions<', false)->assertSee(__('home.petitions_label'));
    }

    public function test_launch_flag_controls_robots_and_sitemaps_without_stale_cache(): void
    {
        $org = $this->org();
        foreach (['organizations', 'organizations/explore', 'organizations/terms', 'org/'.$org->slug] as $path) {
            $this->get('/en/'.$path)->assertOk()->assertSee('content="noindex, follow"', false);
        }
        $this->get('/sitemap-pages.xml')->assertOk()->assertDontSee('/organizations');
        $this->get('/sitemap-organizations.xml')->assertOk()->assertDontSee('/org/'.$org->slug);
        config(['organizations.public_launch' => true, 'organizations.min_directory_count' => 1]);
        foreach (['organizations', 'organizations/explore', 'organizations/terms', 'org/'.$org->slug] as $path) {
            $this->get('/en/'.$path)->assertOk()->assertSee('content="index, follow', false);
        }
        $this->get('/sitemap-pages.xml')->assertOk()->assertSee('/en/organizations/terms')->assertSee('/en/organizations/explore');
        $this->get('/sitemap-organizations.xml')->assertOk()->assertSee('/org/'.$org->slug);
    }

    public function test_navigation_remains_visible_even_when_directory_uses_a_configured_threshold(): void
    {
        config(['organizations.public_launch' => true]);
        for ($i = 0; $i < 19; $i++) {
            $this->org();
        }
        $html = $this->get('/en/organizations/explore')->assertOk()->assertSee('Find your organization')->assertDontSee('No organizations match these filters.')->getContent();
        $this->assertSame(4, $this->navigationCount($html));
        foreach (['cause', 'country', 'sort'] as $field) {
            $this->assertStringNotContainsString('name="'.$field.'"', $html);
        }
        $this->assertStringNotContainsString('Create Your Account', $html);
        $this->org();
        $html = $this->get('/en/organizations/explore')->assertOk()->assertSee('name="cause"', false)->getContent();
        $this->assertSame(4, $this->navigationCount($html));
        $this->get('/en/organizations/explore?q=DoesNotExist')->assertOk()->assertSee('No organizations match these filters.')->assertSee('Reset filters');
    }

    public function test_claim_search_is_literal_substring_private_safe_and_capped(): void
    {
        config(['organizations.public_launch' => true]);
        $literal = $this->org(['name' => 'Alpha 100%_ Team']);
        $this->org(['name' => 'Alpha 100 Other']);
        $hidden = $this->org(['name' => 'Alpha PRIVATE', 'status' => 'pending', 'owner_user_id' => $this->owner()->id]);
        $this->get('/en/organizations/claim-search?q='.urlencode('100%_'))->assertOk()->assertSee($literal->name)->assertDontSee('Alpha 100 Other');
        $this->get('/en/organizations/claim-search?q=pha')->assertOk()->assertSee($literal->name)->assertDontSee($hidden->name)->assertSee('Claim this page');
        $this->get('/en/organizations/claim-search?q='.urlencode("' OR 1=1 --"))->assertOk()->assertDontSee($literal->name)->assertSee('Not listed? Create your page');
        for ($i = 0; $i < 25; $i++) {
            $this->org(['name' => 'Capped result '.$i]);
        }
        $html = $this->get('/en/organizations/claim-search?q=Capped')->assertOk()->getContent();
        $this->assertSame(20, preg_match_all('/<h2\b/', $html));
        $this->get('/en/organizations/claim-search?q='.str_repeat('x', 121))->assertSessionHasErrors('q');
    }

    public function test_closed_apply_is_404_for_guests_but_open_launch_requires_login(): void
    {
        $this->get('/en/organizations/apply')->assertNotFound();
        $this->post('/en/organizations/apply', [])->assertNotFound();
        $this->get('/en/organizations')->assertOk()->assertDontSee('Not listed? Create your page')->assertDontSee('Applications are not open yet.');
        config(['organizations.public_launch' => true]);
        $this->get('/en/organizations/apply')->assertRedirect('/en/login');
        $this->get('/en/organizations')->assertOk()->assertSee('Not listed? Create your page');
    }

    public function test_unclaimed_profile_exposes_only_the_allowed_public_fields_and_neutral_badge(): void
    {
        $org = $this->org(['name' => 'Public Catalog Organization', 'description' => ['en' => 'PRIVATE DESCRIPTION'], 'tagline' => ['en' => 'PRIVATE TAGLINE'], 'website' => 'https://private-marker.example', 'email_public' => 'private-marker@example.com', 'tax_code' => 'PRIVATE-TAX', 'verification_reference' => 'PRIVATE REFERENCE', 'donation_url' => 'https://private-donation.example', 'logo_path' => 'PRIVATE-LOGO.png', 'donation_url_approved_at' => now()]);
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true, 'organization_id' => $org->id, 'signature_count' => 71, 'outcome' => 'victory']);
        $org->causes()->attach(Cause::first()->id);
        $response = $this->get('/en/org/'.$org->slug)->assertOk()->assertSee('Unclaimed')->assertSee('71')->assertSee('Is this your organization? Claim it')->assertSee('Remove%20unclaimed%20profile');
        foreach (['PRIVATE', 'private-marker', 'private-donation', 'data-organization-share', 'data-organization-donation', 'organization-share.js'] as $private) {
            $response->assertDontSee($private);
        }
        $this->assertFalse($org->hasApprovedDonation());
        $this->get('/en/org/'.$org->slug.'/badge.svg')->assertOk()->assertDontSee('Public Catalog Organization');
        $this->assertSame($petition->user_id, $petition->fresh()->user_id);
    }

    public function test_claim_requires_login_and_rejects_missing_declarations(): void
    {
        $org = $this->org();
        $url = '/en/org/'.$org->slug.'/claim';
        $this->get($url)->assertRedirect('/en/login');
        $this->post($url, $this->claimData())->assertRedirect('/en/login');
        $this->actingAs($this->owner())->post($url, [])->assertSessionHasErrors(['type', 'verification_reference', 'representation_confirmed', 'terms_accepted']);
        $this->assertSame('unclaimed', $org->fresh()->status);
    }

    public function test_single_claim_records_acceptance_without_changing_campaign_author_or_consent(): void
    {
        $org = $this->org();
        $author = $this->owner();
        $claimant = $this->owner();
        $petition = Petition::factory()->create(['user_id' => $author->id, 'organization_id' => $org->id]);
        $url = '/en/org/'.$org->slug.'/claim';
        $this->actingAs($claimant)->post($url, $this->claimData() + ['status' => 'verified', 'owner_user_id' => $author->id])->assertRedirect('/en/org/'.$org->slug.'/manage');
        $org->refresh();
        $this->assertSame('pending', $org->status);
        $this->assertSame($claimant->id, $org->owner_user_id);
        $this->assertSame($claimant->id, $org->claimed_by_user_id);
        $this->assertNotNull($org->claimed_at);
        $this->assertSame(config('organizations.terms_version'), $org->terms_version);
        $this->assertNotNull($org->terms_accepted_at);
        $this->assertSame($author->id, $petition->fresh()->user_id);
        $this->assertDatabaseCount('organization_notifications', 2);
        $this->get('/en/org/'.$org->slug)->assertNotFound();
        $this->actingAs($author)->get($url)->assertOk()->assertSee('A claim is under review.')->assertDontSee('PRIVATE CLAIM EVIDENCE');
        config(['app.debug' => false]);
        $conflict = $this->post($url, $this->claimData())->assertStatus(409)->assertSee('A claim is under review.')->assertDontSee('PRIVATE CLAIM EVIDENCE');
        $this->assertStringContainsString('no-store', $conflict->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $conflict->headers->get('Cache-Control'));
        $this->assertSame($claimant->id, $org->fresh()->owner_user_id);
        $this->assertDatabaseCount('organization_notifications', 2);
        Mail::assertNothingSent();
    }

    public function test_pending_claim_cannot_rewrite_curated_identity_or_causes(): void
    {
        $org = $this->org();
        $cause = Cause::orderBy('id')->first();
        $org->causes()->attach($cause->id);
        $owner = $this->owner();
        $this->actingAs($owner)->post('/en/org/'.$org->slug.'/claim', $this->claimData())->assertRedirect();
        $original = $org->fresh()->name;
        $this->post('/en/org/'.$org->slug.'/manage/profile', [
            'name' => 'Proposed Fake Identity', 'verification_reference' => 'More evidence', 'cover_focal_point' => 'center',
            'causes' => [Cause::where('id', '!=', $cause->id)->first()->id],
        ])->assertSessionHasErrors(['name', 'causes']);
        $this->assertSame($original, $org->fresh()->name);
        $this->assertSame([$cause->id], $org->causes()->pluck('causes.id')->all());
    }

    public function test_claim_can_be_verified_or_rejected_back_to_unclaimed_and_reclaimed(): void
    {
        $org = $this->org();
        $claimant = $this->owner();
        $url = '/en/org/'.$org->slug.'/claim';
        $this->actingAs($claimant)->post($url, $this->claimData())->assertRedirect();
        $org->refresh()->update(['description' => ['en' => 'FORMER CLAIMANT PRIVATE'], 'website' => 'https://old-private.example']);
        $admin = $this->admin();
        $this->actingAs($admin, 'admin');
        app(OrganizationLifecycle::class)->review($org, $admin, 'reject', 'Insufficient evidence');
        $org->refresh();
        $this->assertSame('unclaimed', $org->status);
        foreach (['owner_user_id', 'claimed_by_user_id', 'claimed_at', 'terms_accepted_at', 'description', 'website'] as $field) {
            $this->assertNull($org->$field);
        }
        $this->actingAs($claimant, 'web')->get('/en/org/'.$org->slug.'/manage')->assertForbidden();
        $replacement = $this->owner();
        $this->actingAs($replacement, 'web')->get($url)->assertOk()->assertDontSee('FORMER CLAIMANT PRIVATE')->assertDontSee('PRIVATE CLAIM EVIDENCE');
        $this->post($url, $this->claimData())->assertRedirect();
        $this->actingAs($admin, 'admin');
        app(OrganizationLifecycle::class)->review($org->fresh(), $admin, 'verify', 'Representation checked');
        $this->assertSame('verified', $org->fresh()->status);
        $this->assertSame($replacement->id, $org->fresh()->owner_user_id);
        $this->get('/en/org/'.$org->slug)->assertOk()->assertSee('Verified organization');
    }

    public function test_claim_rejection_email_uses_claimant_and_stale_rejection_is_cancelled(): void
    {
        Settings::set('smtp_enabled', true, 'global');
        $admin = $this->admin();
        $claimant = $this->owner();
        foreach ([false, true] as $reclaimed) {
            Mail::fake();
            $org = $this->org();
            $this->actingAs($claimant, 'web')->post('/en/org/'.$org->slug.'/claim', $this->claimData())->assertRedirect();
            $this->actingAs($admin, 'admin');
            app(OrganizationLifecycle::class)->review($org->fresh(), $admin, 'reject', 'Evidence needs clarification');
            $notice = OrganizationNotification::where('organization_id', $org->id)->where('event', 'reject')->firstOrFail();
            if ($reclaimed) {
                $this->actingAs($this->owner(), 'web')->post('/en/org/'.$org->slug.'/claim', $this->claimData())->assertRedirect();
            }
            config(['organizations.notifications_enabled' => true]);
            (new SendOrganizationNotification($notice->id))->handle();
            config(['organizations.notifications_enabled' => false]);
            if ($reclaimed) {
                Mail::assertNothingSent();
                $this->assertNotNull($notice->fresh()->cancelled_at);
            } else {
                Mail::assertSent(OrganizationStatusMail::class, function ($mail) use ($claimant, $org) {
                    return $mail->hasTo($claimant->email) && str_contains($mail->render(), '/en/org/'.$org->slug.'/claim');
                });
                $this->assertNotNull($notice->fresh()->sent_at);
            }
        }
    }

    public function test_admin_creates_only_explicit_public_unattributed_petitions_and_no_fake_acceptance(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        $data = ['name' => 'Staff Prepared Catalog', 'country' => 'IT', 'causes' => [Cause::first()->slug], 'petition_ids' => (string) $petition->id];
        $this->post('/admin/organizations/create-unclaimed', $data)->assertRedirect('/admin/login');
        $this->actingAs($this->admin(), 'admin')->get('/admin/organizations/create-unclaimed')->assertOk();
        $this->post('/admin/organizations/create-unclaimed', $data)->assertRedirect();
        $org = Organization::firstOrFail();
        $this->assertSame('unclaimed', $org->status);
        $this->assertSame('admin', $org->source);
        $this->assertNull($org->terms_accepted_at);
        $this->assertNull($org->owner_user_id);
        $this->assertSame($org->id, $petition->fresh()->organization_id);
        $draft = Petition::factory()->create(['status' => 'draft']);
        $this->post('/admin/organizations/create-unclaimed', array_replace($data, ['name' => 'Private Petition', 'petition_ids' => (string) $draft->id]))->assertSessionHasErrors('petition_ids');
        $this->post('/admin/organizations/create-unclaimed', array_replace($data, ['name' => 'Steal Petition']))->assertSessionHasErrors('petition_ids');
        $this->assertDatabaseCount('organizations', 1);
    }

    public function test_csv_import_is_idempotent_dry_run_safe_and_reports_bad_rows(): void
    {
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        $cause = Cause::first()->slug;
        $csv = tempnam(sys_get_temp_dir(), 'org-claims-');
        try {
            file_put_contents($csv, "name,country,causes,petition_ids\nSample Catalog,IT,{$cause},{$petition->id}\n  sample   catalog  ,IT,{$cause},{$petition->id}\n");
            $this->artisan('organizations:import-unclaimed', ['csv' => $csv, '--dry-run' => true])->expectsOutputToContain('Created: 1; skipped: 1; invalid: 0.')->assertSuccessful();
            $this->assertDatabaseCount('organizations', 0);
            $this->assertNull($petition->fresh()->organization_id);
            $this->artisan('organizations:import-unclaimed', ['csv' => $csv])->assertSuccessful();
            $org = Organization::firstOrFail();
            $this->assertSame('petition_import', $org->source);
            $this->artisan('organizations:import-unclaimed', ['csv' => $csv])->expectsOutputToContain('Created: 0; skipped: 2; invalid: 0.')->assertSuccessful();
            $this->assertDatabaseCount('organizations', 1);
            file_put_contents($csv, "name,country,causes,petition_ids\nWrong,ZZ,{$cause},\nwrong,field,count\nGood Catalog,FR,{$cause},\n");
            $this->artisan('organizations:import-unclaimed', ['csv' => $csv])->expectsOutputToContain('Created: 1; skipped: 0; invalid: 2.')->assertFailed();
            $this->assertDatabaseCount('organizations', 2);
        } finally {
            unlink($csv);
        }
    }

    public function test_migration_adds_claim_fields_without_rewriting_existing_records(): void
    {
        $migration = require database_path('migrations/2026_11_08_000001_add_organization_claims.php');
        $migration->down();
        $org = Organization::create(['name' => 'Existing Record', 'slug' => 'existing-record', 'type' => 'association', 'country' => 'FR', 'verification_reference' => 'Original evidence', 'representation_confirmed_at' => now(), 'terms_accepted_at' => now(), 'terms_version' => 'old-version']);
        $before = (array) DB::table('organizations')->where('id', $org->id)->first();
        $migration->up();
        $after = (array) DB::table('organizations')->where('id', $org->id)->first();
        foreach ($before as $key => $value) {
            $this->assertSame($value, $after[$key], $key);
        }
        $this->assertNull($after['claimed_at']);
        $this->assertNull($after['claimed_by_user_id']);
        $this->assertSame('application', $after['source']);
    }
}
