<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // A private collection is a working folder. A public one is a
            // shareable page, and the same table serves both.
            $table->boolean('is_public')->default(false);
            // Curated by dbelo and shown on the site as an official pack.
            $table->boolean('is_featured')->default(false);

            $table->unsignedInteger('sounds_count')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('collection_sound', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sound_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->primary(['collection_id', 'sound_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_sound');
        Schema::dropIfExists('collections');
    }
};
