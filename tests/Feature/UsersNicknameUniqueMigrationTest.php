<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsersNicknameUniqueMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserLevelSeeder::class, LanguageSeeder::class]);
    }

    private function migration()
    {
        return require database_path('migrations/2026_11_10_000001_make_users_nickname_unique.php');
    }

    public function test_second_insert_with_same_nickname_fails_at_the_database(): void
    {
        User::factory()->create(['nickname' => 'Taken']);

        $this->expectException(QueryException::class);

        User::factory()->create(['nickname' => 'Taken']);
    }

    public function test_many_users_without_nickname_are_allowed(): void
    {
        User::factory()->count(2)->create(['nickname' => null]);

        $this->assertSame(2, User::whereNull('nickname')->count());
    }

    public function test_up_renames_duplicates_and_nulls_empty_strings(): void
    {
        $migration = $this->migration();
        $migration->down();

        $first = User::factory()->create(['nickname' => 'Dupe']);
        $second = User::factory()->create(['nickname' => 'dupe']);
        $third = User::factory()->create(['nickname' => 'Dupe']);
        $empty = User::factory()->create(['nickname' => '']);
        $emptyToo = User::factory()->create(['nickname' => '']);

        $migration->up();

        $this->assertSame('Dupe', $first->fresh()->nickname);
        $this->assertSame('dupe'.$second->id, $second->fresh()->nickname);
        $this->assertSame('Dupe'.$third->id, $third->fresh()->nickname);
        $this->assertNull($empty->fresh()->nickname);
        $this->assertNull($emptyToo->fresh()->nickname);
    }

    public function test_up_keeps_renamed_nickname_within_32_chars(): void
    {
        $migration = $this->migration();
        $migration->down();

        $long = str_repeat('a', 32);
        User::factory()->create(['nickname' => $long]);
        $second = User::factory()->create(['nickname' => $long]);

        $migration->up();

        $this->assertLessThanOrEqual(32, strlen($second->fresh()->nickname));
        $this->assertNotSame($long, $second->fresh()->nickname);
    }

    public function test_down_restores_plain_index(): void
    {
        $this->migration()->down();

        $this->assertFalse(collect(Schema::getIndexes('users'))->contains(fn ($i) => $i['columns'] === ['nickname'] && $i['unique']));
    }
}
