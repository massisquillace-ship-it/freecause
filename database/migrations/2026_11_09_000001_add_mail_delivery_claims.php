<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('welcome_email_claimed_at')->nullable()->index();
            $table->uuid('welcome_email_claim_token')->nullable()->index();
        });

        Schema::table('organization_notifications', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable()->index();
            $table->uuid('claim_token')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('organization_notifications', function (Blueprint $table) {
            $table->dropColumn(['claimed_at', 'claim_token']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['welcome_email_claimed_at', 'welcome_email_claim_token']);
        });
    }
};
