<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two kinds of email in one table, told apart by `type` — the same shape
     * as pages and posts.
     *
     *   digest  a single row that writes itself from the catalogue every
     *           week. No body: the content is assembled at send time.
     *   promo   a one-off you write, sent to a segment.
     */
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10)->default('promo');   // promo | digest

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Internal name, never seen by the reader.
            $table->string('title');

            $table->string('subject');
            // The grey line after the subject in every inbox. Left empty, the
            // client shows the first words of the body, which is usually
            // "View this email in your browser".
            $table->string('preheader', 160)->nullable();

            $table->longText('body')->nullable();           // markdown; null for the digest

            $table->string('segment', 20)->default('all');  // all | free | paying | contributors
            $table->string('status', 20)->default('draft'); // draft | scheduled | sending | sent

            // Digest only.
            $table->json('blocks')->nullable();
            $table->unsignedTinyInteger('schedule_day')->nullable();   // 1 = Monday
            $table->time('schedule_time')->nullable();
            $table->boolean('is_active')->default(false);

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->timestamps();

            $table->index(['type', 'status']);
        });

        /**
         * One row per recipient per campaign.
         *
         * The unique index is the whole point: a queue retry, a double click
         * on Send, or a worker restarting mid-run cannot post the same email
         * twice. Sending an offer to the same person three times is how a
         * list gets marked as spam.
         */
        Schema::create('campaign_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscriber_id')->constrained()->cascadeOnDelete();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error')->nullable();

            $table->unique(['campaign_id', 'subscriber_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_sends');
        Schema::dropIfExists('campaigns');
    }
};
