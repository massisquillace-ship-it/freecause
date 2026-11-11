<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['owner', 'admin', 'editor']);
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at');
            $table->timestamps();
            $table->unsignedTinyInteger('owner_slot')->nullable()->storedAs("CASE WHEN role = 'owner' THEN 1 ELSE NULL END");
            $table->unique(['organization_id', 'user_id']);
            $table->unique(['organization_id', 'owner_slot']);
        });
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email', 254);
            $table->enum('role', ['admin', 'editor']);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->string('active_email', 254)->nullable()->storedAs('CASE WHEN accepted_at IS NULL AND revoked_at IS NULL THEN email ELSE NULL END');
            $table->unique(['organization_id', 'active_email'], 'organization_invitations_active_unique');
            $table->index(['organization_id', 'created_at']);
        });
        DB::table('organizations')->whereNotNull('owner_user_id')->orderBy('id')->chunkById(500, function ($rows) {
            $now = now();
            DB::table('organization_members')->insert($rows->map(fn ($org) => [
                'organization_id' => $org->id, 'user_id' => $org->owner_user_id, 'role' => 'owner',
                'joined_at' => $org->created_at ?? $now, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_invitations');
        Schema::dropIfExists('organization_members');
    }
};
