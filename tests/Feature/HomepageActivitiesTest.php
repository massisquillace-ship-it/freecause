<?php

namespace Tests\Feature;

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

    public function test_only_confirmed_nonspam_public_petitions_in_the_current_language_are_shown(): void
    {
        $public = $this->campaign();
        $valid = $this->signature($public);
        $this->signature($public, ['confirmed' => false]);
        $this->signature($public, ['is_spam' => true]);
        $this->signature($public, ['created_at' => now()->addDay()]);
        $this->signature($this->campaign('it'));
        $this->signature($this->campaign('en', ['status' => 'draft']));
        $this->signature($this->campaign('en', ['is_active' => false]));

        $rows = app(HomepageActivities::class)->forLocale('en');
        $this->assertSame([$valid->id], $rows->pluck('id')->all());
        $this->assertSame($public->id, $rows->first()->petition_id);
        $this->assertCount(1, app(HomepageActivities::class)->forLocale('it'));
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

    public function test_homepage_renders_localized_activity_links_without_leaking_nickname_users_names(): void
    {
        $user = User::factory()->create(['verified' => true, 'name' => 'Hidden Full Name', 'first_name' => 'Hidden', 'nickname' => 'public-nick', 'identify_mode' => 'nick']);
        foreach (['en', 'it', 'es'] as $locale) {
            $petition = $this->campaign($locale);
            $this->signature($petition, ['user_id' => $user->id, 'name' => 'Frozen Full Name']);
            $this->get('/'.$locale)->assertOk()->assertSee('data-home-activities', false)
                ->assertSee('public-nick')->assertDontSee('Hidden Full Name')->assertDontSee('Frozen Full Name')
                ->assertSee('/'.$locale.'/petition/activity-campaign-'.$petition->id.'/'.$petition->id, false)
                ->assertSee('/'.$locale.'/user/public-nick/'.$user->id, false)
                ->assertSee('<time ', false);
        }
    }
}
