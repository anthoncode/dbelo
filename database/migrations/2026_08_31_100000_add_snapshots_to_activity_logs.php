<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two snapshots, and one index.
     *
     * The most valuable rows in an audit trail are about things that no
     * longer exist — the account you suspended and then deleted, the sound
     * you took down. But `user_id` is nullOnDelete and the morph is just a
     * type and an id, so the moment the subject or the actor goes away those
     * rows degrade to "somebody did something to App\Models\Sound #431".
     * That is a row you cannot act on and cannot explain to anyone.
     *
     * Copying the two names in at write time costs a few bytes per row and
     * keeps the trail readable forever. It is deliberately a SNAPSHOT: if
     * the user later changes their name, the log still shows the name that
     * was in use when the thing happened, which is what an audit wants.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('actor_name')->nullable()->after('user_id');
            $table->string('subject_label')->nullable()->after('subject_id');

            // The listing sorts by this and the pruner deletes by it. The
            // existing indexes are both composite and lead with another
            // column, so neither can serve a plain date range.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropColumn(['actor_name', 'subject_label']);
        });
    }
};
