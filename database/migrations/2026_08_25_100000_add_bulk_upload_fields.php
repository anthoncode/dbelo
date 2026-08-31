<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns the bulk uploader needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sounds', function (Blueprint $table) {
            /*
            | Intent recorded at upload time, honoured whenever processing
            | finishes.
            |
            | Publishing a sound the moment the admin presses the button is
            | wrong: ffmpeg may still be running, so the page would go live
            | with no preview and no waveform. Waiting for all fifty to
            | finish before pressing anything is worse. This records the
            | decision on the row, and ProcessSoundUpload reads it at the end
            | to choose between "published" and "pending" — so the answer is
            | the same whether the job takes two seconds or two minutes.
            */
            $table->boolean('publish_when_ready')->default(false)->after('is_featured');
        });

        Schema::table('sound_files', function (Blueprint $table) {
            /*
            | SHA-256 of the master, so the same audio cannot enter the
            | catalogue twice under two names.
            |
            | Filename and size are not enough: the same recording exported
            | twice differs in both, and two unrelated files can share
            | either. The hash of the bytes is the only comparison that
            | means what it says.
            */
            $table->string('checksum', 64)->nullable()->after('size_bytes');

            $table->index(['purpose', 'checksum']);
        });
    }

    public function down(): void
    {
        Schema::table('sounds', function (Blueprint $table) {
            $table->dropColumn('publish_when_ready');
        });

        Schema::table('sound_files', function (Blueprint $table) {
            $table->dropIndex(['purpose', 'checksum']);
            $table->dropColumn('checksum');
        });
    }
};
