<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Settings a person can change without a deployment.
     *
     * The shape was specified in the panel plan long before anything needed
     * it; the backup schedule is simply its first customer. Building it here
     * rather than inventing a one-off store for one setting means the seven
     * Settings screens still to come find it already built — and there is
     * never a second settings store to migrate away from.
     *
     * DEFAULTS LIVE IN config/dbelo.php, NOT HERE. This table holds only
     * what somebody actually changed, so a fresh install works with an empty
     * table and a new default takes effect without a data migration.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();

            // Which screen it belongs to. Not used for lookup — the key is
            // unique on its own — but it is what lets a Settings screen ask
            // for its own fields without knowing all of them.
            $table->string('group', 40)->default('general');

            /*
             * Encrypted at rest, decided per row.
             *
             * API keys, SMTP passwords and OAuth secrets go in here
             * eventually, and a column holding both a site name and a
             * payment secret in plaintext is one query away from being the
             * worst table in the database.
             */
            $table->boolean('is_encrypted')->default(false);

            $table->timestamps();

            $table->index('group');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
