<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\OrganizationCauseSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OrganizationSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class, OrganizationCauseSeeder::class]);
        Cache::flush();
    }

    private function verifiedOrg(): Organization
    {
        $owner = User::factory()->create(['verified' => true, 'locale' => 'en', 'gender' => 'none']);

        return Organization::create([
            'owner_user_id' => $owner->id, 'slug' => 'seo-org', 'name' => 'Alpha Organization', 'type' => 'association', 'country' => 'IT',
            'status' => 'verified', 'verified_at' => now(), 'representation_confirmed_at' => now(), 'terms_accepted_at' => now(), 'terms_version' => 'test-only', 'verification_reference' => 'ref',
        ]);
    }

    private function hreflangs(string $html): array
    {
        preg_match_all('/<link rel="alternate" hreflang="([^"]+)"/', $html, $m);

        return $m[1];
    }

    public function test_locale_without_organization_copy_points_canonical_to_english_and_limits_hreflang(): void
    {
        $this->verifiedOrg();
        $html = $this->get('/de/organizations')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="https://www.freecause.com/en/organizations"', $html);
        $this->assertEqualsCanonicalizing(['en', 'fr', 'it', 'x-default'], $this->hreflangs($html));
    }

    public function test_translated_locale_stays_self_canonical_and_indexable(): void
    {
        $html = $this->get('/it/organizations')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="https://www.freecause.com/it/organizations"', $html);
        $this->assertStringContainsString('content="index, follow', $html);
    }

    public function test_empty_directory_is_noindex_and_left_out_of_sitemap(): void
    {
        $this->get('/en/organizations/explore')->assertOk()->assertSee('noindex, follow', false);
        $this->get('/sitemap-pages.xml')->assertOk()->assertDontSee('/organizations/explore');
    }

    public function test_directory_with_public_organization_is_indexable_and_in_sitemap(): void
    {
        $this->verifiedOrg();

        $this->get('/en/organizations/explore')->assertOk()->assertSee('index, follow', false);
        $xml = $this->get('/sitemap-pages.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/en/organizations/explore', $xml);
        $this->assertStringContainsString('/it/organizations</loc>', $xml);
        $this->assertStringNotContainsString('/de/organizations', $xml);
    }
}
