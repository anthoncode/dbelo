<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sound_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sound_file_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('license_id')->nullable()->constrained()->nullOnDelete();

            // Frozen copy of the license terms accepted at download time.
            $table->json('license_snapshot')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downloads');
    }
};
