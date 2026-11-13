<?php

namespace Tests\Feature;

use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Models\Signature;
use App\Models\User;
use App\Support\CacheKeys;
use App\Support\CacheVersion;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecentActivityPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_warmed_homepage_uses_public_identity_choices_without_caching_names(): void
    {
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
        $petition = Petition::factory()->create(['status' => 'published', 'is_active' => true]);
        PetitionTranslation::factory()->create(['petition_id' => $petition->id, 'locale' => 'en']);
        foreach (['nick' => 'publicnick', 'name' => 'Given', 'full' => 'Private Fullname'] as $mode => $expected) {
            $user = User::factory()->create(['verified' => true, 'name' => 'Private Fullname', 'first_name' => 'Given', 'nickname' => 'publicnick'.($mode === 'nick' ? '' : $mode), 'identify_mode' => $mode]);
            Signature::factory()->create(['petition_id' => $petition->id, 'user_id' => $user->id, 'name' => 'Old snapshot', 'confirmed' => true, 'is_spam' => false]);
            cache()->forget(CacheKeys::homeRecent('en'));
            cache()->forget('homepage:activity-ids:v2:global');
            $this->artisan('cache:warm', ['--locale' => 'en'])->assertSuccessful();
            $this->assertNull(cache()->get(CacheKeys::homeRecent('en')));
            $response = $this->get('/en')->assertOk()->assertSee($expected)->assertDontSee('Old snapshot');
            if ($mode !== 'full') {
                $response->assertDontSee('Private Fullname');
            }
            $this->assertTrue(collect(cache()->get('homepage:activity-ids:v2:global'))->every(fn ($id) => is_int($id)));
        }
        Signature::factory()->create(['petition_id' => $petition->id, 'user_id' => null, 'name' => 'Guest public name', 'confirmed' => true, 'is_spam' => false]);
        cache()->forget(CacheKeys::homeRecent('en'));
        cache()->forget('homepage:activity-ids:v2:global');
        $this->artisan('cache:warm', ['--locale' => 'en'])->assertSuccessful();
        $this->assertNull(cache()->get(CacheKeys::homeRecent('en')));
        $this->get('/en')->assertOk()->assertSee('Guest public name')->assertSee('publicnick');
    }

    public function test_identity_changes_invalidate_public_home_cache(): void
    {
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
        $user = User::factory()->create(['identify_mode' => 'full', 'nickname' => 'publicnick']);
        $before = CacheVersion::get('home');
        $user->update(['identify_mode' => 'nick']);
        $this->assertNotSame($before, CacheVersion::get('home'));
        $before = CacheVersion::get('home');
        $user->update(['city' => 'Rome']);
        $this->assertSame($before, CacheVersion::get('home'));
    }
}
