<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * sounds.user_id used to cascade on delete: removing one contributor
     * would silently wipe every sound they ever uploaded, along with the
     * downloads and licences attached to them.
     *
     * Restricting the delete turns that silent disaster into a loud error.
     * The application never deletes users anyway — it anonymises them —
     * so this constraint should never fire. That is the point: it is a
     * seatbelt, not a feature.
     */
    public function up(): void
    {
        Schema::table('sounds', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sounds', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};
