<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('nickname', '')->update(['nickname' => null]);

        if (! Schema::hasIndex('users', ['nickname'], 'unique')) {
            $this->renameDuplicates();
            // Keep the lookup index available until the unique index has been built.
            Schema::table('users', fn (Blueprint $table) => $table->unique('nickname'));
        }

        foreach (Schema::getIndexes('users') as $index) {
            if ($index['name'] === 'users_nickname_index' && $index['columns'] === ['nickname'] && ! $index['unique']) {
                Schema::table('users', fn (Blueprint $table) => $table->dropIndex($index['name']));
            }
        }
    }

    private function renameDuplicates(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        if ($sqlite) {
            // Match the previous PHP case folding in the SQLite test environment.
            DB::connection()->getPdo()->sqliteCreateFunction('fc_nickname_key', fn ($value) => mb_strtolower($value), 1);
        }
        $key = $sqlite ? 'fc_nickname_key(grouped_users.nickname)' : 'grouped_users.nickname';
        $duplicates = DB::table('users as grouped_users')->whereNotNull('grouped_users.nickname')
            ->selectRaw($key.' AS nickname_key, MIN(grouped_users.id) AS keep_id')
            ->groupByRaw($key)->havingRaw('COUNT(*) > 1');
        $rows = DB::table('users')->joinSub($duplicates, 'duplicates', function ($join) use ($sqlite) {
            if ($sqlite) {
                $join->whereRaw('fc_nickname_key(users.nickname) = duplicates.nickname_key');
            } else {
                $join->on('users.nickname', '=', 'duplicates.nickname_key');
            }
        })->whereColumn('users.id', '<>', 'duplicates.keep_id')->select('users.id', 'users.nickname');

        // Materialize only duplicate IDs once. Re-grouping the full users table for
        // every page or loading all nicknames into PHP is prohibitive on production.
        $work = 'fc_nickname_duplicates_'.bin2hex(random_bytes(6));
        Schema::create($work, function (Blueprint $table) {
            $table->temporary();
            $table->unsignedBigInteger('id')->primary();
            $table->text('nickname');
        });
        try {
            DB::table($work)->insertUsing(['id', 'nickname'], $rows);
            if (DB::table($work)->exists() && ! Schema::hasIndex('users', ['nickname'])) {
                Schema::table('users', fn (Blueprint $table) => $table->index('nickname'));
            }
            DB::table($work)->orderBy('id')->chunkById(1000, function ($users) use ($sqlite) {
                // Bound the transaction and avoid a disk flush for every alias.
                DB::transaction(function () use ($users, $sqlite) {
                    foreach ($users as $user) {
                        $suffix = (string) $user->id;
                        $candidate = mb_substr($user->nickname, 0, 32 - strlen($suffix)).$suffix;
                        for ($n = 1; $this->nicknameExists($candidate, $sqlite); $n++) {
                            $suffix = $user->id.'x'.$n;
                            $candidate = mb_substr($user->nickname, 0, 32 - strlen($suffix)).$suffix;
                        }
                        DB::table('users')->where('id', $user->id)->update(['nickname' => $candidate]);
                    }
                }, 3);
            });
        } finally {
            Schema::dropIfExists($work);
        }
    }

    private function nicknameExists(string $candidate, bool $sqlite): bool
    {
        return DB::table('users')->when($sqlite,
            fn ($query) => $query->whereRaw('fc_nickname_key(nickname) = ?', [mb_strtolower($candidate)]),
            fn ($query) => $query->where('nickname', $candidate)
        )->exists();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['nickname']);
            $table->index('nickname');
        });
    }
};
