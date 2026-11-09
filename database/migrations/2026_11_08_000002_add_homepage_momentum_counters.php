<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['petitions', 'organizations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedInteger('signatures_7d_cache')->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['organizations', 'petitions'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('signatures_7d_cache'));
        }
    }
};
