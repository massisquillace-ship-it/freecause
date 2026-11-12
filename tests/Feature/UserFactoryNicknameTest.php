<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Faker\Generator;
use Faker\Provider\Base;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserFactoryNicknameTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_retries_duplicate_nicknames_before_inserting_users(): void
    {
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
        $faker = clone app(Generator::class);
        $faker->unique(true);
        $faker->addProvider(new class($faker) extends Base
        {
            private int $names = 0;

            public function userName(): string
            {
                return 'fixture_name_'.intdiv($this->names++, 2);
            }

            public function boolean($chanceOfGettingTrue = 50): bool
            {
                return true;
            }
        });
        $this->app->instance(Generator::class, $faker);

        $users = User::factory()->count(3)->create();

        $this->assertSame(['fixture_name_0', 'fixture_name_1', 'fixture_name_2'], $users->pluck('nickname')->all());
        $this->assertSame(3, User::whereNotNull('nickname')->distinct()->count('nickname'));
    }
}
