<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The media library.
     *
     * Every image uploaded from the editor lands here once and is reused
     * from here. Without this table you upload the same logo three times
     * and have no way to find what you already have.
     */
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Named by role in config/dbelo.php, like the audio disks, so
            // moving images to R2 later is a line in .env.
            $table->string('disk', 30)->default('public');
            $table->string('path');

            // The name as it was on disk when uploaded: the only thing you
            // remember six months later when looking for an image.
            $table->string('name');

            // Alt text. Not decoration — it is what a screen reader says and
            // what Google reads, and it is the field everyone forgets.
            $table->string('alt')->nullable();

            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();

            // Smaller widths generated on upload: {"480": "path", "960": "..."}
            $table->json('variants')->nullable();

            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
