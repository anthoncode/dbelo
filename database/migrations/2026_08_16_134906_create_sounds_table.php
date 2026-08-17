<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sounds', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('license_id')->nullable()->constrained()->nullOnDelete();

            // Content
            $table->string('type', 10)->default('sfx');   // sfx | music
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Audio properties
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedTinyInteger('bit_depth')->nullable();
            $table->unsignedTinyInteger('channels')->default(2);
            $table->json('waveform')->nullable();
            $table->boolean('is_loopable')->default(false);

            // Business
            $table->boolean('is_premium')->default(false);
            $table->boolean('is_featured')->default(false);

            // Status & moderation
            $table->string('status', 20)->default('draft');
            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamp('processed_at')->nullable();

            // Provenance & rights
            $table->string('source', 20)->default('original');
            $table->string('source_url')->nullable();
            $table->string('original_author')->nullable();
            $table->text('rights_notes')->nullable();

            // Counters
            $table->unsignedInteger('downloads_count')->default(0);
            $table->unsignedInteger('plays_count')->default(0);
            $table->unsignedInteger('favorites_count')->default(0);

            // SEO
            $table->string('meta_title')->nullable();
            $table->string('meta_description', 300)->nullable();

            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at']);
            $table->index(['type', 'status']);
            $table->index('duration_ms');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sounds');
    }
};
