<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // Who did it. Nullable so the record survives the actor being
            // deleted — the whole point of an audit trail.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('action', 60);              // user.suspended, user.impersonated…
            $table->nullableMorphs('subject');         // what it was done to
            $table->string('description')->nullable();
            $table->json('meta')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
