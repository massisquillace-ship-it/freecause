<?php

namespace Tests\Feature;

use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationLaunchAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
        config(['organizations.public_launch' => false, 'organizations.applications_open' => true]);
    }

    public function test_open_applications_require_login_even_before_public_indexing(): void
    {
        foreach (['en', 'fr', 'it'] as $locale) {
            $this->get('/'.$locale.'/organizations/apply')->assertRedirect('/'.$locale.'/login');
            $this->assertSame(url('/'.$locale.'/organizations/apply'), session('url.intended'));
        }
    }

    public function test_open_application_ctas_exist_on_landing_and_empty_claim_search(): void
    {
        foreach (['en', 'fr', 'it'] as $locale) {
            $link = 'href="'.url('/'.$locale.'/organizations/apply').'"';
            $this->get('/'.$locale.'/organizations')->assertOk()->assertSee($link, false);
            $this->get('/'.$locale.'/organizations/claim-search?q=NoSuchOrganization')->assertOk()->assertSee($link, false);
        }
    }

    public function test_both_closed_flags_keep_guest_application_unavailable(): void
    {
        config(['organizations.applications_open' => false]);
        $this->get('/en/organizations/apply')->assertNotFound();
        $this->get('/en/organizations')->assertOk()->assertDontSee('Not listed? Create your page');
        $this->get('/en/organizations/claim-search?q=NoSuchOrganization')->assertOk()->assertDontSee('Not listed? Create your page');
    }
}
