<?php

declare(strict_types=1);
use App\Filament\Resources\ActionMonitorings\ActionMonitoringResource;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\AuthenticationMonitorings\AuthenticationMonitoringResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\VisitMonitorings\VisitMonitoringResource;
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;

return [

    /*
    |--------------------------------------------------------------------------
    | Shield Resource
    |--------------------------------------------------------------------------
    |
    | Here you may configure the built-in role management resource. You can
    | customize the URL, choose whether to show model paths, group it under
    | a cluster, and decide which permission tabs to display.
    |
    */

    'shield_resource' => [
        // Bawaan Shield `shield/roles`. Dipendekkan supaya URL-nya tetap
        // /admin/roles seperti sebelum Shield masuk — tautan lama dan
        // dokumentasi tidak ikut patah.
        'slug' => 'roles',
        'show_model_path' => true,
        'cluster' => null,
        'tabs' => [
            'pages' => true,
            'widgets' => true,
            'resources' => true,

            // Wajib true. Akses panel dan pemulihan backup adalah izin kustom
            // (lihat `custom_permissions` di bawah) dan tanpa tab ini keduanya
            // tidak bisa dicentang lewat UI sama sekali.
            'custom_permissions' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Tenancy
    |--------------------------------------------------------------------------
    |
    | When your application supports teams, Shield will automatically detect
    | and configure the tenant model during setup. This enables tenant-scoped
    | roles and permissions throughout your application.
    |
    */

    'tenant_model' => null,

    /*
    |--------------------------------------------------------------------------
    | User Model
    |--------------------------------------------------------------------------
    |
    | This value contains the class name of your user model. This model will
    | be used for role assignments and must implement the HasRoles trait
    | provided by the Spatie\Permission package.
    |
    */

    'auth_provider_model' => 'App\\Models\\User',

    /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    |
    | Here you may define a super admin that has unrestricted access to your
    | application. You can choose to implement this via Laravel's gate system
    | or as a traditional role with all permissions explicitly assigned.
    |
    */

    /*
     * Namanya sengaja `super-admin`, bukan `super_admin` bawaan Shield.
     * Nama itu sudah dipakai baris role yang ada, migrasi
     * 2026_08_09_062000_move_is_admin_to_super_admin_role, dan seluruh data
     * instalasi yang sudah jalan. Memakai default Shield akan membuat role
     * kedua yang kosong sementara pemegang role lama kehilangan bypass-nya.
     *
     * `intercept_gate => 'before'` membuat Shield memasang Gate::before
     * sendiri. Karena itu Gate::before di AppServiceProvider dihapus —
     * dua-duanya terpasang berarti tiap pengecekan izin lewat dua callback
     * yang melakukan hal sama.
     */
    'super_admin' => [
        'enabled' => true,
        'name' => 'super-admin',

        /*
         * Wajib true, dan bawaan paket adalah false.
         *
         * `false` berarti super-admin memegang tiap izin sebagai baris nyata
         * di role_has_permissions. Itu justru peran `developer` di repo ini,
         * dan perbedaannya disengaja: developer tetap tunduk pada policy modul
         * baru, super-admin tidak. Membiarkan keduanya sama menghapus satu-
         * satunya alasan kedua role itu ada.
         *
         * Efek sampingnya juga tidak terlihat: dengan `false`, Shield tidak
         * memasang Gate::before sama sekali, jadi super-admin diam-diam
         * kehilangan bypass-nya dan hanya bisa apa yang kebetulan tergenerate
         * saat `shield:generate` terakhir jalan.
         */
        'define_via_gate' => true,

        /*
         * 'before' menghasilkan `hasRole(super-admin) ? true : null`.
         *
         * `null`-nya krusial: mengembalikan false untuk pengguna non-super-
         * admin akan menghentikan gate lebih awal dan menolak izin yang
         * sebenarnya mereka miliki. 'after' punya bug itu secara bawaan —
         * lihat sumber FilamentShieldServiceProvider.
         */
        'intercept_gate' => 'before',
    ],

    /*
    |--------------------------------------------------------------------------
    | Panel User
    |--------------------------------------------------------------------------
    |
    | When enabled, Shield will create a basic panel user role that can be
    | assigned to users who should have access to your Filament panels but
    | don't need any specific permissions beyond basic authentication.
    |
    */

    /*
     * Dimatikan. Akses panel di repo ini ditentukan izin, bukan role — lihat
     * User::canAccessPanel(). Role `panel_user` akan menjadi jalur kedua yang
     * memberi akses panel tanpa terlihat di pengecekan mana pun, dan pada
     * akhirnya jadi role yang diberikan orang tanpa tahu apa artinya.
     */
    'panel_user' => [
        'enabled' => false,
        'name' => 'panel_user',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Builder
    |--------------------------------------------------------------------------
    |
    | You can customize how permission keys are generated to match your
    | preferred naming convention and organizational standards. Shield uses
    | these settings when creating permission names from your resources.
    |
    | Supported formats: snake, kebab, pascal, camel, upper_snake, lower_snake
    |
    | Note: The separator must not conflict with the case format's own
    | delimiter. For example, `_` cannot be used with snake/lower_snake/
    | upper_snake, and `-` cannot be used with kebab.
    |
    | When `format_custom_permission_keys` is true (default), custom
    | permissions defined below will have their keys formatted according to
    | the case setting. If your custom permissions come from external sources
    | (e.g. Terraform, Keycloak) and must remain unchanged, set this to false.
    | When using the separator in custom permission definitions, each segment
    | will be formatted independently (e.g. 'view:system_log' with pascal
    | case becomes 'View:SystemLog').
    |
    */

    'permissions' => [
        'separator' => ':',
        'case' => 'pascal',
        'generate' => true,
        'format_custom_permission_keys' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Policies
    |--------------------------------------------------------------------------
    |
    | Shield can automatically generate Laravel policies for your resources.
    | Generated policies mirror each model's location: models under
    | app/Models map into the path below (keeping their nesting), models in
    | any other "Models" directory get a sibling "Policies" directory, and
    | vendor models fall back to the path below. When merge is enabled, the
    | methods below will be combined with any resource-specific methods you
    | define in the resources section.
    |
    */

    /*
     * `merge` wajib false. Kalau true, daftar method di `resources.manage`
     * di bawah di-array_merge dengan `methods` di sini, jadi ia menambah
     * bukan mengganti — pemangkasan yang ditulis di sana tidak berefek sama
     * sekali dan tiap resource tetap dapat 13 izin. Tidak ada error;
     * satu-satunya gejalanya jumlah izin yang tidak turun.
     */
    'policies' => [
        'path' => app_path('Policies'),
        'merge' => false,
        'generate' => true,
        'methods' => [
            'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny', 'restore',
            'forceDelete', 'forceDeleteAny', 'restoreAny', 'replicate', 'reorder',
        ],
        'single_parameter_methods' => [
            'viewAny',
            'create',
            'deleteAny',
            'forceDeleteAny',
            'restoreAny',
            'reorder',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Localization
    |--------------------------------------------------------------------------
    |
    | Shield supports multiple languages out of the box. When enabled, you
    | can provide translated labels for permissions to create a more
    | localized experience for your international users.
    |
    */

    'localization' => [
        'enabled' => false,
        'key' => 'filament-shield::filament-shield.resource_permission_prefixes_labels',
    ],

    /*
    |--------------------------------------------------------------------------
    | Resources
    |--------------------------------------------------------------------------
    |
    | Here you can fine-tune permissions for specific Filament resources.
    | Use the 'manage' array to override the default policy methods for
    | individual resources, giving you granular control over permissions.
    |
    */

    /*
     * Daftar method dipangkas per resource. Tanpa ini Shield membuat 13 izin
     * untuk tiap resource — termasuk restore, forceDelete, replicate, dan
     * reorder yang tidak satu pun punya tombolnya di panel ini. Izin yang
     * tidak menjaga apa pun tetap muncul di editor role dan membuat orang
     * mencentang sesuatu yang tidak berefek.
     *
     * Aktivitas sengaja hanya viewAny dan view: log audit yang bisa disunting
     * tidak ada gunanya, dan itu dikunci juga di ActivityResource.
     *
     * Kuncinya RoleResource milik aplikasi (hasil `shield:publish`), bukan
     * milik vendor. Menunjuk class vendor membuat override ini terlewat
     * diam-diam dan Shield kembali membuat set penuh.
     */
    'resources' => [
        'subject' => 'model',
        'manage' => [
            RoleResource::class => [
                'viewAny',
                'view',
                'create',
                'update',
                'delete',
            ],
            ActivityResource::class => [
                'viewAny',
                'view',
            ],
            // Ketiganya read-only dan tidak punya halaman detail — tabelnya
            // sudah memperlihatkan seluruh baris. `viewAny` saja; izin `view`
            // tanpa halaman view cuma centang yang tidak menjaga apa pun.
            VisitMonitoringResource::class => [
                'viewAny',
            ],
            ActionMonitoringResource::class => [
                'viewAny',
            ],
            AuthenticationMonitoringResource::class => [
                'viewAny',
            ],
            UserResource::class => [
                'viewAny',
                'view',
                'create',
                'update',
                'delete',
            ],
        ],
        'exclude' => [
            //
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Most Filament pages only require view permissions. Pages listed in the
    | exclude array will be skipped during permission generation and won't
    | appear in your role management interface.
    |
    */

    'pages' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [
            Dashboard::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Widgets
    |--------------------------------------------------------------------------
    |
    | Like pages, widgets typically only need view permissions. Add widgets
    | to the exclude array if you don't want them to appear in your role
    | management interface.
    |
    */

    'widgets' => [
        'subject' => 'class',
        'prefix' => 'view',
        'exclude' => [
            AccountWidget::class,
            FilamentInfoWidget::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Permissions
    |--------------------------------------------------------------------------
    |
    | Sometimes you need permissions that don't map to resources, pages, or
    | widgets. Define any custom permissions here and they'll be available
    | when editing roles in your application.
    |
    | Keys are formatted per the Permission Builder settings above; set
    | permissions.format_custom_permission_keys to false to use them as-is.
    |
    */

    /*
     * Lima izin yang tidak lahir dari resource, page, atau widget mana pun.
     *
     * `access:admin_panel` menjaga pintu masuk panel dan dibaca
     * User::canAccessPanel(). Sengaja izin, bukan role, supaya role apa pun
     * bisa diberi akses panel tanpa menyentuh model User.
     *
     * `restore:backup` menjaga tombol Pulihkan di /admin/backups. Terpisah
     * dari izin halamannya (`View:Backups`) karena restore mengganti tabel
     * users dengan versi arsip — kekuasaan yang lain dari "boleh mengunduh
     * arsip". Lihat bagian Restore di CLAUDE.md.
     *
     * `reload:octane` menjaga tombol Muat Ulang Worker di /admin/octane.
     * Terpisah dari `View:Octane` dengan alasan yang sama: membaca status
     * server tidak berbahaya, sedangkan memuat ulang worker menyentuh proses
     * yang sedang menyajikan panel itu sendiri.
     *
     * `view:log_viewer` menjaga /log-viewer. Rutenya milik paket dan berdiri
     * DI LUAR panel, jadi `Access:AdminPanel` tidak menjaganya sama sekali —
     * tanpa izin ini halaman itu terbuka untuk siapa pun di lingkungan yang
     * bukan produksi. Lihat bagian Log Viewer di CLAUDE.md.
     *
     * `delete:log_file` menjaga rute API log-viewer yang menghapus berkas dan
     * membuang cache indeks. Paketnya cuma punya satu gerbang untuk membaca
     * dan menghapus sekaligus; pemisahan ini dikerjakan sendiri lewat
     * App\Http\Middleware\AuthorizeLogViewerWrites, dengan alasan yang sama
     * dengan `restore:backup` dan `reload:octane`.
     *
     * Nama akhirnya diformat `permissions.case` (pascal) dengan pemisah `:`,
     * jadi kelimanya jadi `Access:AdminPanel`, `Restore:Backup`,
     * `Reload:Octane`, `View:LogViewer`, dan `Delete:LogFile`.
     */
    'custom_permissions' => [
        'access:admin_panel',
        'restore:backup',
        'reload:octane',
        'view:log_viewer',
        'delete:log_file',
    ],

    /*
    |--------------------------------------------------------------------------
    | Entity Discovery
    |--------------------------------------------------------------------------
    |
    | By default, Shield only looks for entities in your default Filament
    | panel. Enable these options if you're using multiple panels and want
    | Shield to discover entities across all of them.
    |
    */

    'discovery' => [
        'discover_all_resources' => false,
        'discover_all_widgets' => false,
        'discover_all_pages' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Role Policy
    |--------------------------------------------------------------------------
    |
    | Shield can automatically register a policy for role management itself.
    | This lets you control who can manage roles using Laravel's built-in
    | authorization system. Requires a RolePolicy class in your app.
    |
    */

    'register_role_policy' => true,

];
