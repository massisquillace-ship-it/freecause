<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petitions', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::create('cause_petition', function (Blueprint $table) {
            $table->foreignId('cause_id')->constrained()->cascadeOnDelete();
            $table->foreignId('petition_id')->constrained()->cascadeOnDelete();
            $table->unique(['cause_id', 'petition_id']);
        });
        Schema::table('signatures', function (Blueprint $table) {
            $table->boolean('org_optin')->nullable();
            $table->timestamp('org_optin_at')->nullable();
            $table->string('org_optin_text_version', 40)->nullable();
            $table->foreignId('org_optin_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->string('org_optin_locale', 10)->nullable();
            $table->string('org_optin_revoke_token', 64)->nullable()->unique();
            $table->timestamp('org_optin_revoked_at')->nullable();
            $table->index(['org_optin_organization_id', 'org_optin', 'confirmed', 'is_spam'], 'signatures_org_consent_index');
        });
    }

    public function down(): void
    {
        Schema::table('signatures', function (Blueprint $table) {
            $table->dropIndex('signatures_org_consent_index');
            $table->dropConstrainedForeignId('org_optin_organization_id');
            $table->dropUnique(['org_optin_revoke_token']);
            $table->dropColumn(['org_optin', 'org_optin_at', 'org_optin_text_version', 'org_optin_locale', 'org_optin_revoke_token', 'org_optin_revoked_at']);
        });
        Schema::dropIfExists('cause_petition');
        Schema::table('petitions', fn (Blueprint $table) => $table->dropConstrainedForeignId('organization_id'));
    }
};
