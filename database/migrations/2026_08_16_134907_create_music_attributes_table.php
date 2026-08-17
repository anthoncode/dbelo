<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('music_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sound_id')->unique()
                ->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('bpm')->nullable();
            $table->string('musical_key', 10)->nullable();
            $table->string('genre', 60)->nullable();
            $table->string('mood', 60)->nullable();
            $table->boolean('has_vocals')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('music_attributes');
    }
};
