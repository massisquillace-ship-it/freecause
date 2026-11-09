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

        $taken = DB::table('users')->whereNotNull('nickname')->pluck('nickname')
            ->map(fn ($n) => mb_strtolower($n))->flip()->all();

        $seen = [];
        DB::table('users')->whereNotNull('nickname')->orderBy('id')->select('id', 'nickname')
            ->each(function ($user) use (&$seen, &$taken) {
                $key = mb_strtolower($user->nickname);
                if (! isset($seen[$key])) {
                    $seen[$key] = true;

                    return;
                }

                $suffix = (string) $user->id;
                $candidate = mb_substr($user->nickname, 0, 32 - strlen($suffix)).$suffix;
                for ($n = 1; isset($taken[mb_strtolower($candidate)]); $n++) {
                    $suffix = $user->id.'x'.$n;
                    $candidate = mb_substr($user->nickname, 0, 32 - strlen($suffix)).$suffix;
                }

                $taken[mb_strtolower($candidate)] = true;
                DB::table('users')->where('id', $user->id)->update(['nickname' => $candidate]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['nickname']);
            $table->unique('nickname');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['nickname']);
            $table->index('nickname');
        });
    }
};
