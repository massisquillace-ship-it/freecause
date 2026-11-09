<?php

namespace Tests\Feature;

use App\Models\Petition;
use App\Models\PetitionTranslation;
use App\Models\Signature;
use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    protected User $profileUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);

        $this->profileUser = User::factory()->create([
            'verified' => true,
            'first_name' => 'Scraper',
            'last_name' => 'Target',
            'nickname' => 'scraper-target',
        ]);
    }

    private function addPublishedPetition(User $author): Petition
    {
        $petition = Petition::factory()->create([
            'user_id' => $author->id,
            'status' => 'published',
            'is_active' => true,
            'signature_count' => 0,
        ]);

        PetitionTranslation::create([
            'petition_id' => $petition->id,
            'locale' => 'en',
            'title' => 'Petition by '.$author->display_name,
            'slug' => 'petition-by-'.$author->id,
            'description' => '<p>Help the scraper target.</p>',
        ]);

        return $petition;
    }

    public function test_profile_page_displays_user_data(): void
    {
        $petition = $this->addPublishedPetition($this->profileUser);
        Signature::factory()->create([
            'petition_id' => $petition->id,
            'user_id' => $this->profileUser->id,
            'confirmed' => true,
            'is_spam' => false,
        ]);

        $response = $this->get('/en/user/scraper-target/'.$this->profileUser->id);

        $response->assertStatus(200);
        $response->assertSee($this->profileUser->display_name);
        $response->assertSee('Petition by '.$this->profileUser->display_name);
    }

    public function test_profile_data_is_served_from_cache_until_it_expires(): void
    {
        $petition = $this->addPublishedPetition($this->profileUser);
        $cacheKey = 'user:profile:data:'.$this->profileUser->id.':en';

        $this->get('/en/user/scraper-target/'.$this->profileUser->id)->assertStatus(200);

        $this->assertTrue(Cache::has($cacheKey));
        $this->assertSame(['display_name', 'created_at'], array_keys((array) Cache::get($cacheKey)['user']));
        $this->assertSame(0, Cache::get($cacheKey)['signatureCount']);
        $this->assertSame(1, Cache::get($cacheKey)['petitionCount']);

        Signature::factory()->create([
            'petition_id' => $petition->id,
            'user_id' => $this->profileUser->id,
            'confirmed' => true,
            'is_spam' => false,
        ]);

        $this->get('/en/user/scraper-target/'.$this->profileUser->id)->assertStatus(200);

        $this->assertSame(0, Cache::get($cacheKey)['signatureCount']);

        Cache::forget($cacheKey);

        $this->get('/en/user/scraper-target/'.$this->profileUser->id)->assertStatus(200);

        $this->assertSame(1, Cache::get($cacheKey)['signatureCount']);
        $this->assertSame(1, Cache::get($cacheKey)['petitionCount']);
    }

    public function test_profile_cache_invalidated_on_user_save(): void
    {
        $this->addPublishedPetition($this->profileUser);
        $cacheKey = 'user:profile:data:'.$this->profileUser->id.':en';

        $this->get('/en/user/scraper-target/'.$this->profileUser->id)->assertStatus(200);
        $this->assertTrue(Cache::has($cacheKey));

        $this->profileUser->first_name = 'Updated';
        $this->profileUser->save();

        $this->assertFalse(Cache::has($cacheKey));
    }

    public function test_profile_page_does_not_lookup_petition_translations(): void
    {
        $this->addPublishedPetition($this->profileUser);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get('/en/user/scraper-target/'.$this->profileUser->id)->assertStatus(200);

        $lookups = collect(DB::getQueryLog())
            ->filter(fn (array $q) => preg_match('/select .?locale.?, .?slug.? from .petition_translations/i', $q['query']))
            ->count();

        $this->assertSame(0, $lookups);
    }

    public function test_banned_user_profile_returns_404(): void
    {
        $this->profileUser->forceFill(['banned_until' => now()->addDay()])->save();

        $this->get('/en/user/scraper-target/'.$this->profileUser->id)->assertNotFound();
    }

    public function test_bulk_ban_after_cached_view_returns_404(): void
    {
        $url = '/en/user/scraper-target/'.$this->profileUser->id;
        $this->get($url)->assertOk();

        User::whereKey($this->profileUser->id)->update(['banned_until' => now()->addDay()]);

        $this->get($url)->assertNotFound();
    }

    public function test_bulk_unverify_after_cached_view_returns_404(): void
    {
        $url = '/en/user/scraper-target/'.$this->profileUser->id;
        $this->get($url)->assertOk();

        User::whereKey($this->profileUser->id)->update(['verified' => false]);

        $this->get($url)->assertNotFound();
    }

    public function test_unverified_user_profile_returns_404(): void
    {
        $this->profileUser->forceFill(['verified' => false])->save();

        $this->get('/en/user/scraper-target/'.$this->profileUser->id)->assertNotFound();
    }

    public function test_signed_petitions_are_not_exposed(): void
    {
        $other = User::factory()->create(['verified' => true]);
        $petition = $this->addPublishedPetition($other);
        PetitionTranslation::where('petition_id', $petition->id)->update(['title' => 'Secretly Signed Distinct Title']);
        Signature::factory()->create([
            'petition_id' => $petition->id,
            'user_id' => $this->profileUser->id,
            'confirmed' => true,
            'is_spam' => false,
        ]);

        $url = '/en/user/scraper-target/'.$this->profileUser->id;

        $this->get($url)->assertOk()->assertDontSee('Secretly Signed Distinct Title');
        $this->get($url.'?tab=signed')->assertOk()->assertDontSee('Secretly Signed Distinct Title');
    }
}
