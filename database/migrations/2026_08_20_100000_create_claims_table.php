<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Copyright complaints.
     *
     * The Terms (§9) already promise a specific procedure: someone writes
     * with the URL, proof of their rights and their contact details, and
     * dbelo reviews it and takes the sound offline while investigating if
     * the claim is credible. This table is that procedure, written down.
     */
    public function up(): void
    {
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            // Public reference. The claimant gets this on screen, and it is
            // what they quote when they follow up — an auto-increment id
            // would tell them how many claims dbelo has received.
            $table->uuid('uuid')->unique();

            $table->foreignId('sound_id')->constrained()->cascadeOnDelete();

            // Who uploaded it, frozen at claim time. The uploader can be
            // anonymised later; the claim still has to say who was
            // responsible under the Contributor Agreement §3.
            $table->foreignId('contributor_id')->nullable()
                ->constrained('users')->nullOnDelete();

            // The claimant is not a user account. Almost nobody who files a
            // copyright claim has ever registered on the site.
            $table->string('claimant_name');
            $table->string('claimant_email');
            $table->string('claimant_organisation')->nullable();
            $table->string('claimant_role', 20)->default('owner');   // owner | agent
            $table->text('claimant_address')->nullable();

            $table->string('right_claimed', 20)->default('copyright'); // copyright | trademark | voice | privacy | other
            $table->text('description');
            $table->string('evidence_url')->nullable();

            // Ticked on the form: a statement of good faith. Without it the
            // form is an anonymous takedown button.
            $table->boolean('sworn')->default(false);

            $table->string('status', 20)->default('new');   // new | reviewing | accepted | rejected | withdrawn
            $table->string('source', 10)->default('form');  // form | email

            // Exposure at the moment the claim arrived. Licences already
            // granted are never revoked, so taking the sound down does not
            // change this number — which is exactly why it is worth storing.
            $table->unsignedInteger('downloads_at_claim')->default(0);

            $table->timestamp('sound_taken_down_at')->nullable();
            $table->timestamp('contributor_notified_at')->nullable();

            $table->foreignId('resolved_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();

            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['sound_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claims');
    }
};
