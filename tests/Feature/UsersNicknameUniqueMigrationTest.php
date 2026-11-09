<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\LanguageSeeder;
use Database\Seeders\UserLevelSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_existing_candidates_and_unicode_case_duplicates_are_preserved_safely(): void
    {
        $migration = $this->migration();
        $migration->down();
        $first = User::factory()->create(['nickname' => 'Écho']);
        $duplicate = User::factory()->create(['nickname' => 'écho']);
        $occupied = User::factory()->create(['nickname' => 'écho'.$duplicate->id]);
        $migration->up();
        $this->assertSame('Écho', $first->fresh()->nickname);
        $this->assertSame('écho'.$duplicate->id.'x1', $duplicate->fresh()->nickname);
        $this->assertSame('écho'.$duplicate->id, $occupied->fresh()->nickname);
    }

    public function test_duplicate_batches_do_not_skip_users_and_a_second_run_is_idempotent(): void
    {
        $migration = $this->migration();
        $migration->down();
        $first = User::factory()->create(['nickname' => 'Shared']);
        $rows = User::factory()->count(1005)->make(['nickname' => 'Shared'])->map(fn ($user) => $user->getAttributes());
        foreach ($rows->chunk(100) as $chunk) {
            DB::table('users')->insert($chunk->all());
        }
        $migration->up();
        $this->assertDatabaseCount('users', 1006);
        $this->assertSame(1006, DB::table('users')->distinct()->count('nickname'));
        $this->assertSame('Shared', $first->fresh()->nickname);
        $before = DB::table('users')->orderBy('id')->pluck('nickname')->all();
        $migration->up();
        $this->assertSame($before, DB::table('users')->orderBy('id')->pluck('nickname')->all());
    }

    public function test_a_retry_without_the_old_lookup_index_completes(): void
    {
        $migration = $this->migration();
        $migration->down();
        Schema::table('users', fn ($table) => $table->dropIndex(['nickname']));
        User::factory()->count(2)->create(['nickname' => 'Retry']);
        $migration->up();
        $this->assertTrue(Schema::hasIndex('users', ['nickname'], 'unique'));
        $this->assertDatabaseCount('users', 2);
    }

    public function test_an_unrelated_nickname_lookup_index_is_preserved(): void
    {
        $migration = $this->migration();
        $migration->down();
        Schema::table('users', fn ($table) => $table->index('nickname', 'users_nickname_secondary'));
        $migration->up();
        $this->assertTrue(Schema::hasIndex('users', 'users_nickname_secondary'));
        $this->assertTrue(Schema::hasIndex('users', ['nickname'], 'unique'));
    }

    public function test_down_restores_plain_index(): void
    {
        $this->migration()->down();

        $this->assertFalse(collect(Schema::getIndexes('users'))->contains(fn ($i) => $i['columns'] === ['nickname'] && $i['unique']));
    }
}
