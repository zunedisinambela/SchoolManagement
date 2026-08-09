<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the archive password out of `.env` and into the settings row, so it can
 * be set from the panel by the person who actually has to open the archive.
 *
 * Stored encrypted (App\Models\BackupSchedule casts it), which means it is
 * unreadable without APP_KEY -- a `text` column because the ciphertext is far
 * longer than the password itself.
 *
 * Nullable on purpose: an existing install keeps working on BACKUP_ARCHIVE_PASSWORD
 * until someone sets one here. Archives already on disk stay locked with the
 * password they were created with, whichever source that was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_schedules', function (Blueprint $table) {
            $table->text('archive_password')->nullable()->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('backup_schedules', function (Blueprint $table) {
            $table->dropColumn('archive_password');
        });
    }
};
