<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The mailing list.
     *
     * One table for both sources — people who registered and people who only
     * left an address in the footer — with a nullable user_id. Two tables
     * would mean two unsubscribe mechanisms, and the day one of them fails
     * is the day someone reports the site for spam.
     *
     * The consent columns are filled even though registration subscribes
     * everyone automatically. They cost nothing today and they are the only
     * thing that makes it possible to prove, or to migrate to strict opt-in,
     * once the country of operation is decided.
     */
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('email');
            $table->string('name')->nullable();

            // In the unsubscribe link. Random rather than the id, so nobody
            // can walk the list by incrementing a number in a URL.
            $table->string('token', 64)->unique();

            $table->string('status', 20)->default('subscribed');   // subscribed | unsubscribed | bounced
            $table->string('source', 20)->default('registration'); // registration | footer | admin | import

            // Separate switches: someone who wants the weekly sounds but not
            // the offers can keep half instead of leaving entirely.
            $table->boolean('wants_digest')->default(true);
            $table->boolean('wants_promos')->default(true);

            $table->string('consent_text')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();

            $table->timestamps();

            $table->unique('email');
            $table->index(['status', 'wants_digest']);
            $table->index(['status', 'wants_promos']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};
