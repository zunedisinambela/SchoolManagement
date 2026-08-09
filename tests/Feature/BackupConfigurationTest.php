<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Tests\TestCase;

/**
 * Menjaga konfigurasi spatie/laravel-backup.
 *
 * Semua yang diuji di sini adalah hal yang gagal dalam diam: backup tetap
 * "berhasil" sementara isinya salah, tujuannya salah, atau tidak ada yang
 * memberi tahu saat berhenti jalan. Tidak ada satu pun yang ketahuan dari
 * output `backup:run`.
 *
 * Sengaja memeriksa config, bukan menjalankan `backup:run`. Database tes
 * adalah SQLite `:memory:` yang tidak punya file untuk di-dump, jadi tes yang
 * benar-benar menjalankan backup hanya akan menguji lingkungan tesnya sendiri.
 */
class BackupConfigurationTest extends TestCase
{
    /**
     * Default paket menulis arsip ke disk `local` alias storage/app/private —
     * bercampur dengan file privat aplikasi.
     */
    public function test_backups_are_written_to_the_dedicated_disk(): void
    {
        $this->assertSame(['backups'], config('backup.backup.destination.disks'));
        $this->assertSame(
            storage_path('app/backups'),
            config('filesystems.disks.backups.root'),
        );
    }

    /**
     * Isi .env adalah APP_KEY dan seluruh kredensial. Arsip backup justru file
     * yang paling mungkin disalin keluar server, jadi keduanya tidak boleh
     * bertemu — termasuk kalau suatu saat `include` diperluas ke base_path().
     */
    public function test_the_env_file_is_never_included_in_a_backup(): void
    {
        $this->assertContains(base_path('.env'), config('backup.backup.source.files.exclude'));
        $this->assertNotContains(base_path(), config('backup.backup.source.files.include'));
    }

    /**
     * `backup.name` adalah nama folder di disk tujuan, dan `monitor_backups`
     * memeriksa folder bernama sama. Kalau keduanya lepas, `backup:monitor`
     * memeriksa folder kosong dan selamanya melapor "unhealthy" — atau lebih
     * buruk, memeriksa folder lama yang masih berisi arsip usang dan
     * menyatakan sehat padahal backup sudah lama berhenti.
     */
    public function test_the_monitor_watches_the_folder_that_backups_are_written_to(): void
    {
        $this->assertSame(
            config('backup.backup.name'),
            config('backup.monitor_backups.0.name'),
        );
        $this->assertSame(
            config('backup.backup.destination.disks'),
            config('backup.monitor_backups.0.disks'),
        );
    }

    /**
     * Notifikasi sukses harian membuat orang berhenti membaca notifikasi
     * backup, dan yang ikut terlewat adalah notifikasi gagalnya.
     */
    public function test_only_failures_are_notified(): void
    {
        $notifications = config('backup.notifications.notifications');

        $this->assertSame(['mail'], $notifications[BackupHasFailedNotification::class]);
        $this->assertSame(['mail'], $notifications[UnhealthyBackupWasFoundNotification::class]);
        $this->assertSame(['mail'], $notifications[CleanupHasFailedNotification::class]);

        $this->assertSame([], $notifications[BackupWasSuccessfulNotification::class]);
        $this->assertSame([], $notifications[HealthyBackupWasFoundNotification::class]);
        $this->assertSame([], $notifications[CleanupWasSuccessfulNotification::class]);
    }

    /**
     * Enkripsi tanpa password menghasilkan arsip polos tanpa error apa pun.
     */
    public function test_archives_are_encrypted(): void
    {
        $this->assertNotSame('none', config('backup.backup.encryption'));
        $this->assertTrue(config('backup.backup.verify_backup'));
    }

    /**
     * `relative_path` null menyimpan path absolut mesin pembuatnya, sehingga
     * arsip tidak bisa dibongkar di server dengan struktur direktori berbeda.
     */
    public function test_archived_paths_are_relative_to_the_project_root(): void
    {
        $this->assertSame(base_path(), config('backup.backup.source.files.relative_path'));
    }

    /**
     * Perawatan yang tidak bisa disetel user: retensi dan pemantauan berjalan
     * harian apa pun jadwal backupnya, karena arsip yang sudah ada tetap menua.
     *
     * Jadwal `backup:run` sendiri tidak diuji di sini — nilainya datang dari
     * database dan bisa diubah dari panel, jadi tesnya ada di
     * BackupScheduleTest yang menyiapkan barisnya lebih dulu.
     */
    public function test_the_maintenance_commands_are_scheduled(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->mapWithKeys(fn ($event) => [
                str($event->command)->after('artisan\' ')->trim("'")->value() => $event->expression,
            ]);

        $this->assertSame('0 1 * * *', $events->get('backup:clean'));
        $this->assertSame('0 7 * * *', $events->get('backup:monitor'));
    }
}
