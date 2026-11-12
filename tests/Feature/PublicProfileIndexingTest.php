<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicProfileIndexingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([LanguageSeeder::class, UserLevelSeeder::class]);
    }

    public function test_all_public_profiles_allow_indexing_without_exposing_the_private_name(): void
    {
        $user = User::factory()->create([
            'verified' => true, 'name' => 'Private Legal Name', 'first_name' => 'Private',
            'last_name' => 'Legal Name', 'nickname' => 'public-nickname', 'identify_mode' => 'nick',
        ]);
        foreach (['en', 'fr', 'it'] as $locale) {
            $path = '/'.$locale.'/user/public-nickname/'.$user->id;
            $response = $this->get($path.'?tab=signed&utm_source=example')->assertOk();
            $response->assertSee('content="index, follow, max-snippet:-1', false);
            $response->assertSee('rel="canonical" href="'.canonical_url(ltrim($path, '/')).'"', false);
            $response->assertSee('public-nickname')->assertDontSee('Private Legal Name');
        }
    }

    public function test_unavailable_profiles_and_account_pages_stay_noindexed(): void
    {
        $user = User::factory()->create(['verified' => false]);
        $this->get('/en/user/unavailable/'.$user->id)->assertNotFound()->assertSee('content="noindex, follow"', false);
        $user->forceFill(['verified' => true, 'banned_until' => now()->addDay()])->save();
        $this->get('/en/user/unavailable/'.$user->id)->assertNotFound()->assertSee('content="noindex, follow"', false);
        $this->get('/en/login')->assertOk()->assertSee('content="noindex, follow"', false);
        $this->get('/admin/login')->assertOk()->assertSee('content="noindex, nofollow"', false);
    }
}
