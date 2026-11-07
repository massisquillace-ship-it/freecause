<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(PageContentSeeder::class);
        $this->call(UserLevelSeeder::class);
        $this->call(LanguageSeeder::class);
        $this->call(SettingsSeeder::class);
        $this->call(StaticPageSeeder::class);
        $this->call(CategorySeeder::class);
        $this->call(OrganizationCauseSeeder::class);
        $this->call(PermissionSeeder::class);
    }
}
