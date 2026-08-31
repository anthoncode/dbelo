<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pages and blog posts in one table, told apart by `type`.
     *
     * They share nine fields out of ten — title, slug, body, cover, SEO,
     * status. Two tables would mean two editors that drift apart, and the
     * whole point was that creating and editing use the same one.
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10)->default('post');   // post | page

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('post_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();

            $table->string('title');
            $table->string('slug', 160);
            $table->string('excerpt', 300)->nullable();

            // Markdown is the source of truth. The HTML is rendered on read
            // and cached — storing both would let them disagree.
            $table->longText('body')->nullable();

            $table->string('status', 20)->default('draft');   // draft | published

            // A date in the future is a scheduled post. Filtering on
            // published_at <= now() means it goes live on its own, with no
            // cron job to keep alive.
            $table->timestamp('published_at')->nullable();

            $table->string('meta_title')->nullable();
            $table->string('meta_description', 300)->nullable();
            $table->boolean('noindex')->default(false);

            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedSmallInteger('reading_minutes')->default(1);

            // Pages only: where it sits in the footer.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamp('autosaved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Unique per type, not globally: /about and /blog/about are
            // different URLs and may legitimately coexist.
            $table->unique(['type', 'slug']);
            $table->index(['type', 'status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
