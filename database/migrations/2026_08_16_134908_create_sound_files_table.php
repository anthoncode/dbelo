<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sound_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sound_id')->constrained()->cascadeOnDelete();

            $table->string('purpose', 20);              // original | preview | download
            $table->string('format', 10);               // wav | mp3 | ogg
            $table->string('disk', 30)->default('sounds_private');
            $table->string('path');

            $table->unsignedInteger('bitrate')->nullable();
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);

            $table->timestamps();

            $table->unique(['sound_id', 'purpose', 'format']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sound_files');
    }
};
