<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('slug', 80)->unique();
            $table->string('name', 120);
            $table->string('legal_name', 200)->nullable();
            $table->string('type', 20);
            $table->char('country', 2);
            $table->string('territory', 160)->nullable();
            $table->string('tax_code', 16)->nullable();
            $table->text('verification_reference');
            $table->string('website', 2048)->nullable();
            $table->string('donation_url', 2048)->nullable();
            $table->timestamp('donation_url_approved_at')->nullable();
            $table->foreignId('donation_url_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email_public', 254)->nullable();
            $table->json('social_links')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('cover_path')->nullable();
            $table->string('cover_focal_point', 6)->default('center');
            $table->json('tagline')->nullable();
            $table->json('description')->nullable();
            $table->string('status', 12)->default('pending')->index();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('verification_note')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('suspension_reason')->nullable();
            $table->boolean('founding_partner')->default(false);
            $table->timestamp('representation_confirmed_at');
            $table->timestamp('terms_accepted_at');
            $table->string('terms_version', 40);
            $table->timestamps();
            $table->index(['status', 'country', 'name']);
        });
        Schema::create('causes', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->json('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
        });
        Schema::create('cause_organization', function (Blueprint $table) {
            $table->foreignId('cause_id')->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->primary(['cause_id', 'organization_id']);
        });
        Schema::create('organization_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // Historical numeric actors survive account deletion without retaining contact data.
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('event', 40);
            $table->json('details')->nullable();
            $table->timestamp('created_at');
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_events');
        Schema::dropIfExists('cause_organization');
        Schema::dropIfExists('causes');
        Schema::dropIfExists('organizations');
    }
};
