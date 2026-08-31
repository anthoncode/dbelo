<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // dbelo never deletes a user row. Deleting one would take the
            // download history with it, and that history is the proof that
            // a licence was granted. Instead the personal data is wiped and
            // the row survives as an empty shell — this column is the mark.
            $table->timestamp('anonymised_at')->nullable()->after('suspension_reason');

            $table->index('anonymised_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['anonymised_at']);
            $table->dropColumn('anonymised_at');
        });
    }
};
