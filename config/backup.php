<?php

use App\Support\Backup\MaximumAgeMatchingSchedule;
use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy;
use Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes;
use Spatie\DbDumper\Compressors\GzipCompressor;

return [

    'backup' => [
        /*
         * Dipakai sebagai nama folder di disk tujuan dan sebagai nama yang
         * dipantau di `monitor_backups`. Sengaja tidak ikut APP_NAME: APP_NAME
         * boleh diganti kapan saja untuk judul panel, dan kalau itu terjadi
         * laravel-backup akan mulai menulis ke folder baru sambil memantau
         * folder lama yang tidak pernah terisi lagi — backup terlihat "tidak
         * sehat" padahal yang berubah cuma judul aplikasi.
         */
        'name' => env('BACKUP_NAME', 'school-management'),

        'source' => [
            'files' => [
                /*
                 * Yang di-backup hanya file yang TIDAK bisa dibuat ulang.
                 *
                 * Kode ada di git, `vendor/` hasil composer install, asset
                 * panel hasil `php artisan filament:assets`, `public/build`
                 * hasil `npm run build`. Semua itu tidak perlu diarsipkan.
                 * Yang benar-benar hilang kalau server mati cuma database
                 * dan file yang diunggah pengguna.
                 *
                 * `storage/app/public` masih kosong sampai ada modul yang
                 * mengunggah berkas (foto siswa, dokumen). Didaftarkan dari
                 * sekarang supaya tidak terlupa saat modul pertama masuk.
                 */
                'include' => [
                    storage_path('app/public'),
                ],

                /*
                 * These directories and files will be excluded from the backup.
                 *
                 * Directories used by the backup process will automatically be excluded.
                 *
                 * `.env` sengaja ada di daftar ini. Isinya APP_KEY beserta
                 * seluruh kredensial, dan arsip backup adalah file yang paling
                 * mungkin disalin keluar server. Kalau `include` di atas suatu
                 * saat diperluas ke base_path(), baris ini yang menahan .env
                 * ikut terbawa — jangan dihapus.
                 */
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    base_path('.env'),
                    storage_path('framework'),
                ],

                /*
                 * Determines if symlinks should be followed.
                 */
                'follow_links' => false,

                /*
                 * Determines if it should avoid unreadable folders.
                 */
                'ignore_unreadable_directories' => false,

                /*
                 * This path is used to make directories in resulting zip-file relative
                 * Set to `null` to include complete absolute path
                 * Example: base_path()
                 *
                 * `null` menyimpan path absolut mesin pembuat, sehingga isi
                 * arsip jadi `home/asus/opt/web/SchoolManagement/storage/...`
                 * dan tidak bisa di-extract langsung di server lain yang
                 * struktur direktorinya berbeda. Relatif ke base_path()
                 * membuat arsip bisa dibongkar tepat di root proyek mana pun.
                 */
                'relative_path' => base_path(),
            ],

            /*
             * The names of the connections to the databases that should be backed up
             * MySQL, PostgreSQL, SQLite and Mongo databases are supported.
             *
             * The content of the database dump may be customized for each connection
             * by adding a 'dump' key to the connection settings in config/database.php.
             * E.g.
             * 'mysql' => [
             *       ...
             *      'dump' => [
             *           'exclude_tables' => [
             *                'table_to_exclude_from_backup',
             *                'another_table_to_exclude'
             *            ]
             *       ],
             * ],
             *
             * If you are using only InnoDB tables on a MySQL server, you can
             * also supply the useSingleTransaction option to avoid table locking.
             *
             * E.g.
             * 'mysql' => [
             *       ...
             *      'dump' => [
             *           'useSingleTransaction' => true,
             *       ],
             * ],
             *
             * For a complete list of available customization options, see https://github.com/spatie/db-dumper
             */
            'databases' => [
                env('DB_CONNECTION', 'sqlite'),
            ],
        ],

        /*
         * The database dump can be compressed to decrease disk space usage.
         *
         * Out of the box Laravel-backup supplies
         * Spatie\DbDumper\Compressors\GzipCompressor::class.
         *
         * You can also create custom compressor. More info on that here:
         * https://github.com/spatie/db-dumper#using-compression
         *
         * If you do not want any compressor at all, set it to null.
         *
         * Dump SQLite berupa teks SQL polos, jadi gzip memangkasnya drastis.
         */
        'database_dump_compressor' => GzipCompressor::class,

        /*
         * If specified, the database dumped file name will contain a timestamp (e.g.: 'Y-m-d-H-i-s').
         */
        'database_dump_file_timestamp_format' => null,

        /*
         * The base of the dump filename, either 'database' or 'connection'
         *
         * If 'database' (default), the dumped filename will contain the database name.
         * If 'connection', the dumped filename will contain the connection name.
         */
        'database_dump_filename_base' => 'database',

        /*
         * The file extension used for the database dump files.
         *
         * If not specified, the file extension will be .archive for MongoDB and .sql for all other databases
         * The file extension should be specified without a leading .
         */
        'database_dump_file_extension' => '',

        'destination' => [
            /*
             * The compression algorithm to be used for creating the zip archive.
             *
             * If backing up only database, you may choose gzip compression for db dump and no compression at zip.
             *
             * Some common algorithms are listed below:
             * ZipArchive::CM_STORE (no compression at all; set 0 as compression level)
             * ZipArchive::CM_DEFAULT
             * ZipArchive::CM_DEFLATE
             * ZipArchive::CM_BZIP2
             * ZipArchive::CM_XZ
             *
             * For more check https://www.php.net/manual/zip.constants.php and confirm it's supported by your system.
             */
            'compression_method' => ZipArchive::CM_DEFAULT,

            /*
             * The compression level corresponding to the used algorithm; an integer between 0 and 9.
             *
             * Check supported levels for the chosen algorithm, usually 1 means the fastest and weakest compression,
             * while 9 the slowest and strongest one.
             *
             * Setting of 0 for some algorithms may switch to the strongest compression.
             */
            'compression_level' => 9,

            /*
             * The filename prefix used for the backup zip file.
             */
            'filename_prefix' => '',

            /*
             * The disk names on which the backups will be stored.
             *
             * Disk `backups` didefinisikan di config/filesystems.php. Jangan
             * dikembalikan ke `local` — `local` adalah storage/app/private,
             * tempat file privat aplikasi, dan arsip backup tidak ada
             * urusannya di sana.
             */
            'disks' => [
                'backups',
            ],

            /*
             * Determines whether to allow backups to continue when some targets fail instead of failing completely.
             */
            'continue_on_failure' => false,
        ],

        /*
         * The directory where the temporary files will be stored.
         */
        'temporary_directory' => storage_path('app/backup-temp'),

        /*
         * The password to be used for archive encryption.
         * Set to `null` to disable encryption.
         *
         * PERINGATAN: password hilang = seluruh arsip tidak bisa dibuka.
         * Tidak ada jalur pemulihan. Simpan salinannya di luar server
         * (password manager), jangan hanya di .env yang ikut mati bersama
         * mesinnya. Mengganti password tidak membuka arsip lama — arsip
         * lama tetap terkunci dengan password saat dibuat.
         */
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),

        /*
         * The encryption algorithm to be used for archive encryption.
         * Set to 'none' to disable encryption.
         *
         * Supported: 'none', 'default', 'aes128', 'aes192', 'aes256'
         *
         * When set to 'default', we'll use AES-256 if available on your system.
         */
        'encryption' => 'default',

        /*
         * After creating the zip, verify it can be opened and contains files.
         * Recommended for critical backups but adds a small overhead.
         *
         * Dinyalakan. Arsipnya kecil jadi overhead-nya tidak terasa, dan
         * backup yang rusak baru ketahuan saat dibutuhkan adalah kasus
         * terburuk yang justru ingin dihindari oleh backup.
         */
        'verify_backup' => true,

        /*
         * The number of attempts, in case the backup command encounters an exception
         */
        'tries' => 1,

        /*
         * The number of seconds to wait before attempting a new backup if the previous try failed
         * Set to `0` for none
         */
        'retry_delay' => 0,
    ],

    /*
     * You can get notified when specific events occur. Out of the box you can use 'mail' and 'slack'.
     * For Slack you need to install laravel/slack-notification-channel.
     *
     * You can also use your own notification classes, just make sure the class is named after one of
     * the `Spatie\Backup\Notifications\Notifications` classes.
     */
    'notifications' => [
        /*
         * Hanya kabar buruk yang dikirim. Notifikasi sukses harian membuat
         * orang berhenti membaca notifikasi backup sama sekali — dan yang
         * ikut terlewat nanti adalah notifikasi gagalnya.
         *
         * Array kosong berarti tidak ada channel, bukan channel default.
         */
        'notifications' => [
            BackupHasFailedNotification::class => ['mail'],
            UnhealthyBackupWasFoundNotification::class => ['mail'],
            CleanupHasFailedNotification::class => ['mail'],
            BackupWasSuccessfulNotification::class => [],
            HealthyBackupWasFoundNotification::class => [],
            CleanupWasSuccessfulNotification::class => [],
        ],

        /*
         * Here you can specify the notifiable to which the notifications should be sent. The default
         * notifiable will use the variables specified in this config file.
         */
        'notifiable' => Notifiable::class,

        'mail' => [
            /*
             * Selama MAIL_MAILER=log, "email" ini hanya ditulis ke
             * storage/logs/laravel.log. Setel SMTP sebelum produksi, kalau
             * tidak kegagalan backup tidak sampai ke siapa pun.
             */
            'to' => env('BACKUP_NOTIFICATION_EMAIL', 'admin@admin.com'),

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
                'name' => env('MAIL_FROM_NAME', 'Example'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',

            /*
             * If this is set to null the default channel of the webhook will be used.
             */
            'channel' => null,

            'username' => null,

            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',

            /*
             * If this is an empty string, the name field on the webhook will be used.
             */
            'username' => '',

            /*
             * If this is an empty string, the avatar on the webhook will be used.
             */
            'avatar_url' => '',
        ],

        /*
         * A generic webhook channel that POSTs JSON to a URL.
         * Useful for Mattermost, Microsoft Teams, or custom integrations.
         */
        'webhook' => [
            'url' => '',
        ],
    ],

    /*
     * The log channel used for backup activity messages.
     *
     * Set to a channel name defined in config/logging.php to use that channel.
     * Set to false to disable backup logging entirely.
     * Set to null to use the default log channel.
     */
    'log_channel' => null,

    /*
     * Here you can specify which backups should be monitored.
     * If a backup does not meet the specified requirements the
     * UnHealthyBackupWasFound event will be fired.
     */
    'monitor_backups' => [
        [
            /*
             * Harus sama persis dengan `backup.name` di atas — nama itulah
             * yang dipakai sebagai nama folder di disk. Kalau keduanya beda,
             * `backup:monitor` memeriksa folder yang tidak pernah diisi dan
             * selalu melapor "unhealthy".
             */
            'name' => env('BACKUP_NAME', 'school-management'),
            'disks' => ['backups'],
            'health_checks' => [
                /*
                 * Bukan MaximumAgeInDays bawaan paket. Ambang umurnya
                 * diturunkan dari jadwal yang disetel user di /admin/backups,
                 * karena angka mati "1 hari" langsung salah begitu jadwalnya
                 * mingguan — monitor akan melapor "unhealthy" tiap hari mulai
                 * hari kedua. Lihat App\Support\Backup\MaximumAgeMatchingSchedule.
                 */
                MaximumAgeMatchingSchedule::class,
                MaximumStorageInMegabytes::class => 5000,
            ],
        ],

        /*
        [
            'name' => 'name of the second app',
            'disks' => ['local', 's3'],
            'health_checks' => [
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays::class => 1,
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes::class => 5000,
            ],
        ],
        */
    ],

    'cleanup' => [
        /*
         * The strategy that will be used to cleanup old backups. The default strategy
         * will keep all backups for a certain amount of days. After that period only
         * a daily backup will be kept. After that period only weekly backups will
         * be kept and so on.
         *
         * No matter how you configure it the default strategy will never
         * delete the newest backup.
         */
        'strategy' => DefaultStrategy::class,

        'default_strategy' => [
            /*
             * The number of days for which backups must be kept.
             */
            'keep_all_backups_for_days' => 7,

            /*
             * After the "keep_all_backups_for_days" period is over, the most recent backup
             * of that day will be kept. Older backups within the same day will be removed.
             * If you create backups only once a day, no backups will be removed yet.
             */
            'keep_daily_backups_for_days' => 16,

            /*
             * After the "keep_daily_backups_for_days" period is over, the most recent backup
             * of that week will be kept. Older backups within the same week will be removed.
             * If you create backups only once a week, no backups will be removed yet.
             */
            'keep_weekly_backups_for_weeks' => 8,

            /*
             * After the "keep_weekly_backups_for_weeks" period is over, the most recent backup
             * of that month will be kept. Older backups within the same month will be removed.
             */
            'keep_monthly_backups_for_months' => 4,

            /*
             * After the "keep_monthly_backups_for_months" period is over, the most recent backup
             * of that year will be kept. Older backups within the same year will be removed.
             */
            'keep_yearly_backups_for_years' => 2,

            /*
             * After cleaning up the backups remove the oldest backup until
             * this amount of megabytes has been reached.
             * Set null for unlimited size.
             */
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],

        /*
         * The number of attempts, in case the cleanup command encounters an exception
         */
        'tries' => 1,

        /*
         * The number of seconds to wait before attempting a new cleanup if the previous try failed
         * Set to `0` for none
         */
        'retry_delay' => 0,
    ],

];
