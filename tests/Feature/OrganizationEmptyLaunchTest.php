<?php

namespace Tests\Feature;

use Database\Seeders\LanguageSeeder;
use Database\Seeders\OrganizationCauseSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OrganizationEmptyLaunchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class, OrganizationCauseSeeder::class]);
        config(['organizations.public_launch' => true, 'organizations.applications_open' => true, 'organizations.min_directory_count' => 20]);
        Cache::flush();
    }

    public function test_zero_organizations_keep_search_filters_and_apply_ctas_in_every_launch_locale(): void
    {
        foreach (['en', 'fr', 'it'] as $locale) {
            $link = 'href="'.url('/'.$locale.'/organizations/apply').'"';
            foreach (['organizations', 'organizations/explore', 'organizations/claim-search?q=Absent'] as $path) {
                $this->get('/'.$locale.'/'.$path)->assertOk()->assertSee($link, false);
            }
            $this->get('/'.$locale.'/organizations/explore')->assertOk()
                ->assertSee('name="q"', false)->assertSee('name="cause"', false)
                ->assertSee('name="country"', false)->assertSee('name="sort"', false);
            $html = $this->get('/'.$locale)->assertOk()->assertSee($link, false)->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $this->assertSame(1, $xpath->query('//*[@data-home-organizations]//a[contains(@href,"/organizations/apply")]')->length);
            app()->setLocale($locale);
            $this->assertSame(__('home.victory_badge'), $xpath->query('//*[@id="home-victories-title"]')->item(0)->textContent);
            $this->assertSame(0, $xpath->query('//a[normalize-space(.)="'.__('home.moderation').'"]')->length);
        }
    }

    public function test_closed_applications_hide_empty_state_apply_links(): void
    {
        config(['organizations.public_launch' => false, 'organizations.applications_open' => false]);
        foreach (['en', 'en/organizations/explore'] as $path) {
            $this->get('/'.$path)->assertOk()->assertDontSee('href="'.url('/en/organizations/apply').'"', false);
        }
    }
}
