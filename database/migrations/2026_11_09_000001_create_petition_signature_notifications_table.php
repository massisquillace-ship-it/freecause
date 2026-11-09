<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petition_signature_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('petition_id')->constrained()->cascadeOnDelete();
            $table->timestamp('cutoff_at');
            $table->unsignedInteger('total');
            $table->json('signers');
            $table->string('status')->default('pending');
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['petition_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petition_signature_notifications');
    }
};
