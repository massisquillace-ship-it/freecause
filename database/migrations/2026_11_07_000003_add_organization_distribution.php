<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('petitions', function (Blueprint $table) {
            $table->string('outcome', 16)->default('open');
            $table->date('outcome_at')->nullable();
            $table->string('outcome_summary', 500)->nullable();
            $table->timestamp('outcome_updated_at')->nullable();
            $table->foreignId('outcome_updated_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::create('petition_outcome_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('petition_id')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->json('before');
            $table->json('after');
            $table->timestamp('created_at');
        });
        Schema::create('organization_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 32);
            $table->boolean('to_admin')->default(false);
            $table->json('payload');
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_notifications');
        Schema::dropIfExists('petition_outcome_events');
        Schema::table('petitions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outcome_updated_by');
            $table->dropColumn(['outcome', 'outcome_at', 'outcome_summary', 'outcome_updated_at']);
        });
    }
};
