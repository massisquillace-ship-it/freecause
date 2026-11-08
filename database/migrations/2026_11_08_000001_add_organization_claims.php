<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable();
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('source', ['application', 'petition_import', 'admin'])->default('application');
            // Nullable unique key protects repeated/concurrent imports without backfilling existing rows.
            $table->char('catalog_key', 64)->nullable()->unique();
            $table->timestamp('representation_confirmed_at')->nullable()->change();
            $table->timestamp('terms_accepted_at')->nullable()->change();
            $table->string('terms_version', 40)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Never fabricate historical acceptance to restore NOT NULL constraints.
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('claimed_by_user_id');
            $table->dropUnique(['catalog_key']);
            $table->dropColumn(['claimed_at', 'source', 'catalog_key']);
        });
    }
};
