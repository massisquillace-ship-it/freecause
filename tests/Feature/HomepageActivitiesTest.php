<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Petition;
use App\Models\Signature;
use App\Models\User;
use App\Services\HomepageActivities;
use App\Services\HomepageMagazine;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomepageActivitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed([LanguageSeeder::class, UserLevelSeeder::class]);
        $this->mock(HomepageMagazine::class)->shouldReceive('posts')->andReturn([]);
    }

    private function campaign(string $locale = 'en', array $attributes = []): Petition
    {
        $petition = Petition::factory()->create(array_merge(['status' => 'published', 'is_active' => true], $attributes));
        $petition->translations()->create(['locale' => $locale, 'title' => 'Activity campaign '.$petition->id, 'slug' => 'activity-campaign-'.$petition->id, 'description' => 'Campaign description.']);

        return $petition;
    }

    private function signature(Petition $petition, array $attributes = []): Signature
    {
        return Signature::factory()->create(array_merge(['petition_id' => $petition->id, 'confirmed' => true, 'is_spam' => false, 'created_at' => now()->subMinute()], $attributes));
    }

    public function test_confirmed_nonspam_public_activity_is_global_in_every_interface_language(): void
    {
        $public = $this->campaign();
        $valid = $this->signature($public);
        $this->signature($public, ['confirmed' => false]);
        $this->signature($public, ['is_spam' => true]);
        $this->signature($public, ['created_at' => now()->addDay()]);
        $foreign = $this->signature($this->campaign('it'), ['created_at' => now()]);
        $this->signature($this->campaign('en', ['status' => 'draft']));
        $this->signature($this->campaign('en', ['is_active' => false]));

        $rows = app(HomepageActivities::class)->forLocale('en');
        $this->assertSame([$foreign->id, $valid->id], $rows->pluck('id')->all());
        $this->assertSame($public->id, $rows->last()->petition_id);
        $this->assertSame(['it', 'en'], $rows->pluck('petition_locale')->all());
        foreach (['it', 'pl', 'es'] as $locale) {
            $this->assertSame($rows->pluck('id')->all(), app(HomepageActivities::class)->forLocale($locale)->pluck('id')->all());
        }
    }

    public function test_names_and_removals_are_rechecked_while_activity_ids_are_cached(): void
    {
        $user = User::factory()->create(['verified' => true, 'name' => 'Private Legal Name', 'first_name' => 'Private', 'nickname' => 'oceanfriend', 'identify_mode' => 'nick']);
        $signature = $this->signature($this->campaign(), ['user_id' => $user->id, 'name' => 'Old Frozen Name']);
        $service = app(HomepageActivities::class);
        $this->assertSame('oceanfriend', $service->forLocale('en')->first()->display_name);
        $user->update(['nickname' => 'new-public-nickname']);
        $this->assertSame('new-public-nickname', $service->forLocale('en')->first()->display_name);
        $user->update(['nickname' => '']);
        $this->assertSame(__('sig.anonymous'), $service->forLocale('en')->first()->display_name);
        $this->assertNull($service->forLocale('en')->first()->profile_user_id);
        $user->forceFill(['nickname' => 'public-again', 'banned_until' => now()->addDay()])->save();
        $this->assertSame(__('sig.anonymous'), $service->forLocale('en')->first()->display_name);
        $user->forceFill(['banned_until' => null, 'verified' => false])->save();
        $this->assertSame(__('sig.anonymous'), $service->forLocale('en')->first()->display_name);
        $user->delete();
        $row = $service->forLocale('en')->first();
        $this->assertSame(__('sig.anonymous'), $row->display_name);
        $this->assertNull($row->profile_user_id);
        DB::table('signatures')->where('id', $signature->id)->update(['is_spam' => true]);
        $this->assertCount(0, $service->forLocale('en'));
    }

    public function test_latest_ten_are_ordered_and_new_signatures_appear_after_the_short_cache_expires(): void
    {
        $this->freezeTime();
        $petition = $this->campaign();
        $ids = [];
        foreach (range(1, 12) as $i) {
            $ids[] = $this->signature($petition, ['created_at' => now()->subMinutes(13 - $i)])->id;
        }
        $service = app(HomepageActivities::class);
        $this->assertSame(array_slice(array_reverse($ids), 0, 10), $service->forLocale('en')->pluck('id')->all());
        $latest = $this->signature($petition, ['created_at' => now()]);
        $this->assertNotSame($latest->id, $service->forLocale('en')->first()->id);
        $this->travel(61)->seconds();
        $this->assertSame($latest->id, $service->forLocale('en')->first()->id);
        $petition->forceFill(['is_active' => false])->save();
        $this->assertCount(0, $service->forLocale('en'));
    }

    public function test_homepage_keeps_original_petition_links_and_localized_interface_without_leaking_names(): void
    {
        $user = User::factory()->create(['verified' => true, 'name' => 'Hidden Full Name', 'first_name' => 'Hidden', 'nickname' => 'public-nick', 'identify_mode' => 'nick']);
        $petitions = [];
        foreach (['en', 'it', 'es'] as $locale) {
            $petitions[$locale] = $this->campaign($locale);
            $this->signature($petitions[$locale], ['user_id' => $user->id, 'name' => 'Frozen Full Name']);
        }
        foreach (['en', 'it', 'es', 'pl'] as $locale) {
            $response = $this->get('/'.$locale)->assertOk()->assertSee('data-home-activities', false)
                ->assertSee('public-nick')->assertDontSee('Hidden Full Name')->assertDontSee('Frozen Full Name')
                ->assertSee('/'.$locale.'/user/public-nick/'.$user->id, false)
                ->assertSee('<time ', false)->assertSee(__('home.recent_has_signed', [], $locale));
            foreach ($petitions as $originalLocale => $petition) {
                $response->assertSee('/'.$originalLocale.'/petition/activity-campaign-'.$petition->id.'/'.$petition->id, false)
                    ->assertSee('hreflang="'.$originalLocale.'"', false);
            }
            $this->assertSame(3, substr_count($response->getContent(), 'data-activity-language'));
        }
    }

    public function test_multiple_translations_do_not_duplicate_signatures_and_unavailable_versions_are_excluded(): void
    {
        $petition = $this->campaign('es');
        $petition->translations()->create(['locale' => 'en', 'title' => 'English version', 'slug' => 'english-version', 'description' => 'Description.']);
        $signature = $this->signature($petition);
        $service = app(HomepageActivities::class);
        $rows = $service->forLocale('pl');
        $this->assertSame([$signature->id], $rows->pluck('id')->all());
        $this->assertSame('es', $rows->first()->petition_locale);
        Language::where('code', 'es')->update(['is_active' => false]);
        cache()->forget('active_languages');
        $this->assertSame('en', $service->forLocale('pl')->first()->petition_locale);
        $petition->translations()->where('locale', 'en')->update(['slug' => '']);
        $this->assertCount(0, $service->forLocale('pl'));
    }
}
