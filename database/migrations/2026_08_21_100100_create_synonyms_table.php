<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Search synonyms, editable from the browser.
     *
     * They already existed in config/scout.php, which meant that fixing a
     * failed search — "vehicle" finding nothing while everything is tagged
     * "car" — required editing code and redeploying. That is exactly the
     * kind of thing the panel exists to remove.
     *
     * The config groups stay as built-in defaults; these override and extend
     * them, and both get pushed to Meilisearch together.
     */
    public function up(): void
    {
        Schema::create('synonyms', function (Blueprint $table) {
            $table->id();

            $table->string('term', 80)->unique();

            // The words that should find the same thing.
            $table->json('replacements');

            // Set when the group was created straight from a failed search,
            // so you can see which fixes came from real demand.
            $table->foreignId('search_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synonyms');
    }
};
