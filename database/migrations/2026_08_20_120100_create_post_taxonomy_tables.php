<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Categories and tags for the blog — deliberately NOT the ones from the
     * catalogue.
     *
     * A sound category is "Doors". A blog category is "Tutorials". Sharing
     * the tables would put "Tutorials" in the catalogue filter as if it were
     * a kind of sound, and that confuses the visitor for no gain.
     *
     * The URLs never collide either: blog taxonomies live under /blog/…,
     * sound ones under /sounds?category=…
     */
    public function up(): void
    {
        Schema::create('post_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 80)->unique();
            $table->string('description', 300)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('post_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('slug', 60)->unique();
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('post_tags');
        Schema::dropIfExists('post_categories');
    }
};
