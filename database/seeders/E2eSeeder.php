<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Petition;
use App\Models\User;
use App\Models\UserLevel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class E2eSeeder extends Seeder
{
    public function run(): void
    {
        abort_unless(app()->environment('e2e', 'local', 'testing'), 1);

        $this->call(DatabaseSeeder::class);

        User::factory()->create([
            'name' => 'e2e-admin',
            'email' => 'admin@e2e.test',
            'password' => Hash::make('e2e-password'),
            'level_id' => UserLevel::where('name', 'admin')->value('id'),
            'verified' => true,
            'locale' => 'en_US',
        ]);

        $moderator = UserLevel::create(['name' => 'moderator', 'visible_name' => 'Moderator', 'is_system' => false]);
        DB::table('permissions')->insert([
            'level_id' => $moderator->id, 'module' => 'petitions', 'action' => 'view', 'created_at' => now(), 'updated_at' => now(),
        ]);
        User::factory()->create([
            'name' => 'e2e-moderator',
            'email' => 'mod@e2e.test',
            'password' => Hash::make('e2e-password'),
            'level_id' => $moderator->id,
            'verified' => true,
            'locale' => 'en_US',
        ]);

        $owner = User::factory()->create([
            'first_name' => 'Olive',
            'last_name' => 'Owner',
            'name' => 'Olive Owner',
            'nickname' => null,
            'city' => null,
            'identify_mode' => 'full',
            'email' => 'owner@e2e.test',
            'verified' => true,
            'locale' => 'en_US',
        ]);

        foreach (['protect-the-city-park' => 'Protect the city park', 'clean-the-river' => 'Clean the river'] as $slug => $title) {
            $petition = Petition::factory()->create([
                'user_id' => $owner->id,
                'category_id' => Category::query()->orderBy('id')->value('id'),
                'status' => 'published',
                'is_active' => true,
                'is_featured' => $slug === 'protect-the-city-park',
                'goal_signatures' => 1000,
                'signature_count' => 0,
                'target' => 'City Council',
                'tags' => 'parks community',
                'city' => null,
                'community' => null,
                'community_url' => null,
                'youtube_url' => null,
                'cover_image' => null,
                'created_at' => '2026-01-15 12:00:00',
                'updated_at' => '2026-01-15 12:00:00',
            ]);

            foreach (['en', 'de'] as $locale) {
                $petition->translations()->create([
                    'locale' => $locale,
                    'title' => $title,
                    'slug' => $slug,
                    'description' => str_repeat('Keep this place open for every family in the city. ', 12),
                    'is_featured' => $slug === 'protect-the-city-park',
                ]);
            }
        }
        $this->call(OrganizationE2eSeeder::class);
    }
}
