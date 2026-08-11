<?php

return [
    /*
     * Main configuration settings for the package.
     */
    'config' => [
        'routes' => [
            /*
             * JANGAN diarahkan ke berkas yang tidak ada. Paket ini jatuh ke
             * rute bawaannya sendiri kalau berkas ini hilang, dan keenam rute
             * itu berjalan tanpa autentikasi sama sekali — termasuk DELETE.
             * Penjelasannya ada di dalam berkasnya.
             */
            'file_path' => 'routes/user-monitoring.php',
        ],

        /*
         * Tidak berpengaruh di repo ini: view bawaan paket tidak pernah
         * dirender karena rutenya dikosongkan. UI-nya resource Filament.
         */
        'dark_mode' => false,
    ],

    /*
     * User-specific configuration settings.
     *
     * Customize various aspects related to the user model, including the guard, table, foreign key, and display attributes.
     */
    'user' => [
        /*
         * Specify the fully qualified class name of the user model.
         */
        'model' => 'App\Models\User',

        /*
         * Name of the foreign key column linking user data to other models.
         */
        'foreign_key' => 'user_id',

        /*
         * Name of the table storing user data.
         */
        'table' => 'users',

        /*
         * Defines the authentication guards used for verifying the user.
         * Multiple guards can be specified for flexible authentication strategies.
         * Ensure these guards are configured correctly in the 'guards' section of the auth.php config file.
         */
        'guards' => ['web'],

        /*
         * Specify the type of foreign key being used (e.g., 'id', 'uuid', 'ulid').
         * For non-standard IDs, make sure to add the relevant traits to your models.
         */
        'foreign_key_type' => 'id', // Options: uuid, ulid, id

        /*
         * Attribute of the user model used to display the user's name.
         * If you wish to use a different attribute (e.g., username), change this value accordingly.
         */
        'display_attribute' => 'name',
    ],

    /*
     * Configuration settings for visit monitoring.
     */
    'visit_monitoring' => [
        /*
         * The table where visit data will be stored.
         */
        'table' => 'visits_monitoring',

        /*
         * Enable or disable the visit monitoring feature.
         * Set false to disable tracking of user visits.
         */
        'turn_on' => true,

        /*
         * WAJIB false di panel Filament, dan ini bukan preferensi.
         *
         * Filament berjalan di atas Livewire: tiap pengetikan di kolom
         * pencarian, tiap ganti halaman tabel, tiap polling notifikasi adalah
         * request XHR. Dengan `true`, satu menit seseorang mengetik di
         * /admin/users menulis puluhan baris `visits_monitoring` yang semuanya
         * menunjuk halaman yang sama — tabelnya membengkak dan justru
         * mengubur kunjungan sungguhan yang mau dilihat.
         */
        'ajax_requests' => false,

        /*
         * List of pages that should be excluded from visit monitoring.
         * Add route names or URL paths to this array if you want to exclude certain pages.
         */
        /*
         * Dicocokkan terhadap `$request->path()` (tanpa garis miring depan),
         * dan `*` diterjemahkan jadi `.*` — jadi polanya harus cocok penuh.
         *
         * Tiga entri bawaan paket dibuang: rutenya sudah tidak ada.
         *
         * Halaman pemantauan itu sendiri dikecualikan supaya membaca daftar
         * kunjungan tidak menambah baris ke daftar yang sedang dibaca.
         */
        'except_pages' => [
            'admin/visit-monitorings*',
            'admin/action-monitorings*',
            'admin/authentication-monitorings*',
            'admin/livewire/*',
            'livewire/*',
        ],

        /*
         * Retensi 90 hari. `visits_monitoring` tumbuh per halaman dibuka,
         * bukan per perubahan data — jauh lebih cepat daripada `activity_log`
         * yang batasnya 365 hari.
         *
         * Angka ini tidak melakukan apa pun sendirian: yang menghapus adalah
         * `laravel-user-monitoring:remove-visit-monitoring-records`, dan ia
         * sudah dijadwalkan di routes/console.php. Tanpa cron aktif, tabelnya
         * tumbuh selamanya.
         *
         * Perintah itu HANYA menyentuh visits. `actions_monitoring` dan
         * `authentications_monitoring` tidak punya pembersih bawaan sama
         * sekali — keduanya tumbuh tanpa batas sampai ada yang menulisnya.
         */
        'delete_days' => 90,

        /*
         * Determines whether to store `visits` even when the user is not logged in.
         */
        'guest_mode' => true,

        /*
        | Here you can define one or more conditions that determine whether a visit
        | should be logged. Each condition must return a boolean (true = log visit,
        | false = skip logging).
        |
        | All conditions are evaluated before monitoring. If any condition returns
        | false, the visit will NOT be recorded.
        */
        'conditions' => [],
    ],

    /*
     * Configuration settings for action monitoring.
     */
    'action_monitoring' => [
        /*
         * The table where action data (e.g., store, update, delete) will be stored.
         */
        'table' => 'actions_monitoring',

        /*
         * Enable or disable monitoring of specific actions (e.g., store, update, delete).
         * Set to true to monitor actions or false to disable.
         */
        'on_store' => true,
        'on_update' => true,
        'on_destroy' => true,
        'on_read' => true,
        'on_restore' => false,
        'on_replicate' => false,

        /*
         * If your application is behind a reverse proxy (e.g., Nginx or Cloudflare),
         * enable this setting to fetch the real client IP from the proxy headers.
         */
        'use_reverse_proxy_ip' => false,

        /*
         * The header used by reverse proxies to forward the real client IP.
         * Common values are 'X-Forwarded-For' or 'X-Real-IP'.
         */
        'real_ip_header' => 'X-Forwarded-For',

        /*
         * Determines whether to store `actions` even when the user is not logged in.
         */
        'guest_mode' => true,

        /*
        * Here you can define one or more conditions that determine whether an action
        * should be logged. Each condition must return a boolean (true = log action,
        * false = skip logging).
        */
        'conditions' => [],
    ],

    /*
     * Configuration settings for authentication monitoring.
     */
    'authentication_monitoring' => [
        /*
         * The table name.
         */
        'table' => 'authentications_monitoring',

        /*
         * If enabled, authentication records will be deleted when the associated user is deleted.
         */
        'delete_user_record_when_user_delete' => true,

        /*
         * Enable or disable monitoring of user login and logout events.
         * Set to true to track these actions, or false to disable.
         */
        'on_login' => true,
        'on_logout' => true,
    ],
];
