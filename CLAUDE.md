# CLAUDE.md

Panduan untuk Claude Code saat bekerja di repo ini.

## Stack

| Komponen | Versi |
|---|---|
| PHP | 8.4 lokal — `composer.json` minimum `^8.3` |
| Laravel | 13 |
| Filament (admin panel) | 5 |
| Database | SQLite — `database/database.sqlite` |
| Frontend | Vite |
| Audit log | spatie/laravel-activitylog 5 |
| Role & permission | spatie/laravel-permission 8 |
| Backup | spatie/laravel-backup 10 |
| WebSocket | laravel/reverb 1 + laravel-echo & pusher-js |
| Debug | barryvdh/laravel-debugbar (dev only) |
| Output tes | laravel/pao (dev only) |

Belum ada modul aplikasi (siswa, kelas, nilai, dst). Yang sudah jadi baru fondasinya: panel admin, otorisasi berbasis role/permission, audit log, UI kelola pengguna & role, backup terjadwal lengkap dengan password arsip dan restore dari panel, serta broadcasting WebSocket yang terpasang tapi belum menyiarkan satu event pun.

Role `guru`, `karyawan`, dan `murid` sudah ada di enum tapi belum punya modul apa pun — dua yang pertama masuk panel dan melihatnya kosong, yang terakhir tidak masuk sama sekali.

Tabel yang ada: bawaan Laravel (`users`, `cache`, `jobs`), `activity_log`, lima tabel role/permission, dan `backup_schedules`.

Halaman panel yang sudah ada: `/admin/users`, `/admin/roles`, `/admin/permissions`, `/admin/activities`, `/admin/backups`.

## Perintah

```bash
composer run dev      # serve + queue:listen + reverb + pail (log) + vite, sekaligus
composer run test     # config:clear lalu artisan test
composer run setup    # install deps, generate key, migrate, build asset
./vendor/bin/pint     # format kode PHP
php artisan reverb:start  # server WebSocket saja, tanpa sisa proses dev
php artisan backup:run    # buat arsip backup sekarang
php artisan backup:list   # daftar arsip + status sehat/tidak
# restore: lewat tombol Pulihkan di /admin/backups, atau langkah CLI di bagian Restore
```

## Filament (admin panel)

Panel `admin` di `app/Providers/Filament/AdminPanelProvider.php`, terdaftar di `bootstrap/providers.php`.

- URL: `/admin`, login di `/admin/login`.
- Resource, page, dan widget auto-discovery dari `app/Filament/Resources`, `app/Filament/Pages`, `app/Filament/Widgets`. Cukup buat file di sana, tidak perlu registrasi manual.
- Generator: `php artisan make:filament-resource Siswa`, `make:filament-page`, `make:filament-widget`.
- Buat user: `php artisan migrate:fresh --seed` (lihat bagian Seeder), atau lewat menu **Pengguna** di panel. `php artisan make:filament-user` juga bisa, tapi akun hasilnya **langsung kena 403** sampai diberi role — lihat bagian Akses panel.
- UI panel sudah berbahasa Indonesia otomatis — Filament membawa locale `id` sendiri dan mengikuti `APP_LOCALE`. Tidak perlu menerjemahkan apa pun untuk teks bawaan panel.

### Struktur resource

Filament 5 memecah resource jadi beberapa file. Ikuti pola yang sudah ada, jangan digabung ke satu file:

```
app/Filament/Resources/Users/
├── UserResource.php          <- model, navigasi, canAccess/canEdit/canDelete
├── Pages/                    <- ListUsers, CreateUser, EditUser, ViewUser
├── Schemas/UserForm.php      <- form
├── Schemas/UserInfolist.php  <- tampilan detail
└── Tables/UsersTable.php     <- kolom, filter, aksi baris
```

Resource read-only (Log Aktivitas, Izin) sengaja **tidak punya** `Pages/Create*`, `Pages/Edit*`, dan `Schemas/*Form.php` — filenya dihapus, bukan sekadar disembunyikan.

Navigasi dikelompokkan lewat `$navigationGroup`. Yang ada sekarang: grup **Manajemen Akses** (`$navigationSort` 10/20/30) dan Log Aktivitas tanpa grup (`$navigationSort` 90).

**Asset panel di-gitignore.** `public/css/filament`, `public/js/filament`, `public/fonts/filament` adalah hasil generate, bukan source. Setelah `composer update` atau saat deploy **wajib** jalankan:

```bash
php artisan filament:assets
```

Kalau dilewat, panel tampil tanpa CSS.

### Akses panel

`App\Models\User` implement `Filament\Models\Contracts\FilamentUser`. Yang menentukan akses adalah **permission** `akses-panel-admin`, bukan role. Tanpa itu → HTTP 403.

```php
public function canAccessPanel(Panel $panel): bool
{
    return match ($panel->getId()) {
        'admin' => $this->can(Permission::AksesPanelAdmin->value),
        default => false,
    };
}
```

Dua hal yang sengaja begini, jangan "disederhanakan":

- **Cek permission, bukan `hasRole('super-admin')`.** Ini yang membuat role `guru` dan `karyawan` bisa masuk panel hanya dengan diberi `akses-panel-admin`, tanpa menyentuh model User sama sekali.
- **Cabang `default` = `false`.** Panel kedua (guru, siswa, wali murid) **tertutup sampai ditambahkan eksplisit** ke `match`, tidak diam-diam ikut aturan admin.

## Otorisasi (spatie/laravel-permission)

Role dan permission disimpan di tabel `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`. Guard-nya `web`, sama dengan guard panel admin.

Modelnya `App\Models\Role` dan `App\Models\Permission` — subclass model Spatie yang menambah pencatatan audit. **Jangan import dari `Spatie\Permission\Models\`**, penjelasannya di bagian Audit log.

### Nama role & permission ada di enum

Jangan tulis string mentah. Sumber kebenarannya:

| File | Isi |
|---|---|
| `app/Enums/Role.php` | `Developer`, `SuperAdmin`, `Admin`, `Guru`, `Karyawan`, `Murid` |
| `app/Enums/Permission.php` | `AksesPanelAdmin`, `LihatLogAktivitas`, `KelolaPengguna`, `KelolaRole`, `KelolaBackup`, `PulihkanBackup` |

```php
$user->can(Permission::LihatLogAktivitas->value);
$user->assignRole(Role::SuperAdmin->value);
```

**Menambah permission baru = dua langkah.** Tambah case di enum, lalu jalankan `php artisan db:seed --class=RolePermissionSeeder`. Case enum tanpa baris di tabel `permissions` akan selalu `false`. Tes `test_every_enum_permission_is_seeded` menangkap kalau langkah kedua terlewat. Hal yang sama berlaku untuk role — tesnya `test_every_enum_role_is_seeded`.

### Role yang ada dan izin bawaannya

`Role::permissions()` di enum adalah sumber kebenarannya, dan itulah yang dibaca `RolePermissionSeeder`:

| Role | Izin bawaan | Catatan |
|---|---|---|
| `developer` | **semua** (`Permission::cases()`) | Eksplisit, bukan lewat gate — lihat di bawah |
| `super-admin` | *tidak ada* | Lolos semua lewat `Gate::before` |
| `admin` | `akses-panel-admin`, `kelola-pengguna`, `lihat-log-aktivitas` | Sengaja tanpa `kelola-role`, `kelola-backup`, dan `pulihkan-backup` |
| `guru` | `akses-panel-admin` | Belum ada modul untuknya |
| `karyawan` | `akses-panel-admin` | Belum ada modul untuknya |
| `murid` | *tidak ada* | Tidak masuk panel admin |

**`developer` bukan super-admin kedua.** Dia memegang tiap izin sebagai baris nyata di `role_has_permissions`, tidak lewat `Gate::before`. Bedanya baru terasa saat modul bertambah: policy modul baru **tetap berlaku** untuk developer, dan izin yang baru ditambahkan ke enum harus diberikan secara sadar (lewat seeder). Satu-satunya role yang melewati semuanya tetap `super-admin`. Tes `test_developer_holds_every_permission_without_bypassing_the_gate` mengunci perbedaan ini.

**`admin` sengaja tidak dapat `kelola-role`.** Siapa pun yang memegangnya bisa membuat role berisi izin apa pun lalu memberikannya ke dirinya sendiri — praktis setara developer. `kelola-backup` juga ditahan karena berjarak satu klik dari mengunduh seluruh isi database, dan `pulihkan-backup` karena ia mengganti tabel `users` dengan versi arsip — lihat *Restore*.

**Seeder menambah, tidak pernah sync.** `givePermissionTo` dipakai, bukan `syncPermissions`. Ini disengaja: langkah 6 checklist deploy menjalankan seeder ini di **setiap** rilis, dan sync akan diam-diam membatalkan setiap perubahan izin yang dibuat lewat panel sejak deploy terakhir. Konsekuensinya, menghapus izin dari `permissions()` **tidak** mencabutnya di instalasi yang sudah jalan — itu harus dilakukan manual lewat panel. Dikunci `test_reseeding_does_not_revoke_a_permission_granted_by_hand`.

Role selain `super-admin` **tidak dikunci** dari edit/hapus di panel. Namanya tidak dirujuk dari kode saat runtime, jadi mengubahnya tidak memecahkan apa pun — tapi user yang sudah memegang role itu ikut kehilangannya, dan seeder hanya akan membuat ulang role kosong tanpa mengembalikan penggunanya.

### super-admin

Role `super-admin` **tidak memegang permission apa pun secara eksplisit**. Dia lolos semua pengecekan lewat `Gate::before` di `AppServiceProvider`:

```php
Gate::before(fn (User $user) => $user->hasRole(Role::SuperAdmin->value) ? true : null);
```

Wajib `null`, bukan `false`, untuk user non-super-admin. `false` akan menghentikan gate lebih awal dan menolak permission yang sebenarnya dimiliki user itu.

Konsekuensinya: jangan pernah memberi `super-admin` ke user biasa. Role itu melewati **semua** policy, bukan cuma permission yang terdaftar.

### UI di panel admin

Grup navigasi **Manajemen Akses**:

| Menu | URL | Izin | Sifat |
|---|---|---|---|
| Pengguna | `/admin/users` | `kelola-pengguna` | CRUD + centang role |
| Role | `/admin/roles` | `kelola-role` | CRUD + centang izin |
| Izin | `/admin/permissions` | `kelola-role` | **read-only** |

Di luar grup itu: **Log Aktivitas** (`/admin/activities`, izin `lihat-log-aktivitas`, read-only) dan **Backup** (`/admin/backups`, izin `kelola-backup`, plus `pulihkan-backup` khusus untuk tombol Pulihkan) — lihat bagian *Audit log* dan *Backup*.

Pengaman yang sengaja dipasang — jangan dilonggarkan tanpa alasan:

- **Izin read-only.** Nama izin bukan data bebas; tiap nama adalah case di `App\Enums\Permission` dan dirujuk dari `canAccess()` atau `can()`. Izin yang dibuat lewat UI tidak cocok dengan pengecekan mana pun, dan menghapus izin diam-diam mencabut akses. Keduanya perubahan kode, tempatnya di enum + seeder.
- **Role `super-admin` terkunci** dari edit dan hapus. Namanya dirujuk dari `App\Enums\Role`, dari `Gate::before`, dan dari sebuah migrasi. Daftar izinnya juga tidak berarti karena gate memberi semuanya.
- **Super-admin terakhir tidak bisa dilepas rolenya**, baik lewat form edit maupun tombol hapus. Tanpa ini, admin bisa mencabut role dari satu-satunya akun yang punya — termasuk akunnya sendiri — dan mengunci semua orang, karena tidak ada jalur lain di panel untuk mengembalikannya.
- **Tidak bisa menghapus akun sendiri.**
- **Bulk delete dimatikan** di Pengguna dan Role. Filament tidak menjalankan `canDelete()` per baris saat bulk, jadi pengaman di atas akan terlewat.

### Action Filament TIDAK ikut `canEdit()` / `canDelete()`

Jebakan paling berbahaya di panel ini. Dari sumber Filament (`Filament\Actions\Concerns\CanBeAuthorized`):

> Actions do not have automatic policy-based authorization. Authorization defaults to `null` (allowed for all users). You must explicitly use `authorize()`, `visible()`, or `hidden()`.

Artinya `canEdit()` / `canDelete()` di resource **hanya menjaga halaman**, bukan tombolnya. Tombol `DeleteAction` yang tidak dijaga akan **benar-benar menghapus record** walaupun `canDelete()` mengembalikan `false` — tanpa error, tanpa 403.

Setiap `DeleteAction` di repo ini karena itu dipasangi:

```php
DeleteAction::make()
    ->disabled(fn (User $record) => ! UserResource::canDelete($record))
    ->tooltip(fn (User $record) => /* alasannya */),
```

`disabled()` diperiksa di sisi server sebelum action dijalankan (`InteractsWithActions::callMountedAction`), jadi bukan sekadar abu-abu di tampilan. Dipilih ketimbang `hidden()` supaya alasannya terbaca lewat tooltip — tombol yang hilang begitu saja terbaca seperti bug.

**Kalau menambah `DeleteAction` atau `EditAction` baru di mana pun — tabel maupun header halaman — wajib pasang `->disabled(...)` sendiri.** Jangan berasumsi resource sudah menjaganya.

`tests/Feature/TableActionAuthorizationTest.php` memanggil tombolnya sungguhan (`callTableAction`) lalu memeriksa recordnya masih ada. Tes yang cuma memanggil `canDelete()` tidak akan menangkap kelas bug ini.

### Lewat CLI

```bash
php artisan permission:create-role wali-kelas
php artisan tinker --execute="App\Models\User::where('email','x@y.com')->first()->assignRole('developer');"
```

Setelah mengubah role/permission langsung lewat SQL (bukan lewat model), bersihkan cache:

```bash
php artisan permission:cache-reset
```

Dua batasan yang berbeda, jangan tertukar:

- **Izin tidak boleh lahir dari CLI.** Jangan pakai `permission:create-permission` — tiap nama izin adalah case di `App\Enums\Permission` dan dirujuk dari `canAccess()` atau `can()`. Izin yang tidak ada di enum tidak cocok dengan pengecekan mana pun.
- **Role tambahan boleh.** Enam role di enum adalah bawaan, bukan daftar tertutup. Role tambahan seperti `wali-kelas` sah dibuat lewat CLI atau panel — yang penting izin yang dicentang ke dalamnya sudah ada di enum. Yang perlu diingat: role di luar enum tidak dibuat ulang oleh seeder, jadi kalau terhapus ia hilang beserta penugasannya.

### Kolom `is_admin` sudah tidak ada

Dulu akses panel ditentukan kolom boolean `users.is_admin`. Migrasi `2026_08_09_062000_move_is_admin_to_super_admin_role` memindahkan setiap user `is_admin = true` ke role `super-admin` lalu membuang kolomnya. Kalau menemukan referensi `is_admin` di kode atau dokumen, itu sisa yang terlewat.

## Seeder

`php artisan migrate:fresh --seed` menghasilkan enam role beserta izin bawaannya (lihat *Role yang ada dan izin bawaannya*) plus satu user admin siap pakai:

| Field | Nilai |
|---|---|
| Email | `admin@admin.com` |
| Password | `admin` |
| Role | `super-admin` |

Hanya user itu yang dibuat. Lima role lainnya lahir tanpa pemegang — penugasannya lewat menu **Pengguna** di panel.

Urutannya penting: `RolePermissionSeeder` membuat role dan permission, baru `AdminUserSeeder` memberikan rolenya. Keduanya idempoten (`findOrCreate` / `updateOrCreate` / `givePermissionTo`), aman dijalankan berulang tanpa `migrate:fresh`.

**Kredensial dev saja.** Jangan pernah jalankan `AdminUserSeeder` di produksi. `RolePermissionSeeder` aman — isinya cuma role dan permission, tanpa user.

### `DatabaseSeeder` membungkam model event — dan itu mematikan cache Spatie

`DatabaseSeeder` memakai trait `WithoutModelEvents`, jadi seluruh seeding berjalan di dalam `Model::withoutEvents()`. Efeknya bukan cuma "seeding tidak masuk audit log": paket pihak ketiga menumpang event Eloquent untuk **kebenaran**, bukan sekadar efek samping.

`spatie/laravel-permission` membatalkan cache permission-nya lewat trait `RefreshesPermissionCache`, yang hook ke event `created`/`deleted`. Dengan event dibungkam, rantainya jadi begini di database kosong:

1. `Permission::findOrCreate()` yang pertama melakukan lookup → `PermissionRegistrar` memuat isi tabel (masih kosong) dan **menyimpannya sebagai koleksi kosong**.
2. Enam baris permission tertulis ke tabel. Tidak ada yang membatalkan cache tadi.
3. `givePermissionTo()` bertanya ke cache, bukan ke tabel → `PermissionDoesNotExist: There is no permission named 'akses-panel-admin' for guard 'web'` untuk izin yang barisnya jelas-jelas ada.

Karena itu `RolePermissionSeeder` memanggil `forgetCachedPermissions()` **tiga kali**: di awal, **di antara loop pembuatan permission dan loop role**, dan di akhir. Yang di tengah itulah yang menambal celahnya — jangan dihapus karena terlihat kembar dengan yang di awal. Flush eksplisit juga membuat seeder berhenti bergantung pada model event sama sekali, jadi ia benar dipanggil dari mana pun.

Gejala yang sama akan muncul di seeder lain mana pun yang membuat permission lalu memakainya dalam satu proses. Kalau menulis seeder baru yang menyentuh role/permission, flush di antaranya.

Yang menguncinya `test_seeding_through_the_database_seeder_grants_role_permissions` — dan ia satu-satunya tes yang bisa, karena tes lain memanggil `RolePermissionSeeder` **langsung** dan jalur itu tidak lewat `WithoutModelEvents`.

## Audit log (spatie/laravel-activitylog)

Tabel `activity_log`, config di `config/activitylog.php`. Dilihat lewat menu **Log Aktivitas** di panel admin (`/admin/activities`).

### Apa yang tercatat

| Kanal (`log_name`) | Sumber | Event |
|---|---|---|
| `user` | trait `LogsActivity` di `App\Models\User` | `created`, `updated`, `deleted` |
| `auth` | `App\Listeners\LogAuthenticationActivity` | `login`, `logout`, `failed`, `lockout` |
| `otorisasi` | `App\Listeners\LogAuthorizationChanges` | `role-diberikan`, `role-dicabut`, `izin-diberikan`, `izin-dicabut` |
| `otorisasi` | trait `LogsActivity` di `App\Models\Role` dan `App\Models\Permission` | `created`, `updated`, `deleted` |
| `backup` | aksi di `App\Filament\Pages\Backups` | `backup-dijalankan`, `backup-diunduh`, `backup-dihapus`, `password-arsip-diubah`, `backup-dipulihkan` |
| `backup` | trait `LogsActivity` di `App\Models\BackupSchedule` | `updated` |

Kanal `otorisasi` butuh `'events_enabled' => true` di `config/permission.php`. Kalau dimatikan, pemberian dan pencabutan hak akses hilang dari jejak audit — padahal justru itu perubahan yang paling perlu terlacak.

### Model Role & Permission dioverride

`App\Models\Role` dan `App\Models\Permission` meng-extend model Spatie dan menambah `LogsActivity`. Terdaftar di `config/permission.php` → `models.role` / `models.permission`, dan itulah yang membuat paket memakai class ini di mana-mana, termasuk relasi `$user->roles` dan pemanggilan `assignRole()`.

**Selalu import `App\Models\Role`, jangan `Spatie\Permission\Models\Role`.** Class Spatie tetap berfungsi dan menulis baris ke tabel yang sama, tapi tanpa jejak audit. Ada tes `test_the_package_resolves_the_app_models` yang mengunci konfigurasinya.

Hati-hati tabrakan nama: `App\Enums\Role` menyimpan *nama* role, `App\Models\Role` adalah modelnya. Di file yang butuh keduanya, beri alias — konvensi di repo ini `use App\Enums\Role as RoleEnum`.

### Nama method listener wajib `recordX`, jangan `handleX`

Semua listener didaftarkan manual di `AppServiceProvider::boot()`. Methodnya dinamai `recordLogin`, `recordRoleAttached`, dan seterusnya — **bukan** `handleLogin`.

Alasannya: auto-discovery Laravel mencocokkan pola `handle*`, bukan cuma `handle` persis (`DiscoverEvents.php`, `Str::is('handle*', ...)`). Method bernama `handleLogin` akan terdaftar **dua kali** — sekali oleh discovery, sekali oleh `Event::listen` — dan setiap aktivitas tertulis dobel di `activity_log`.

Kalau menambah listener baru: nama method `recordX`, lalu daftarkan di `AppServiceProvider`. Tes `ActivityLogTest` dan `AccessManagementUiTest` memakai `assertCount(1, ...)` khusus untuk menangkap regresi ini.

### Menambah model baru ke audit log

Pasang trait dan definisikan opsinya:

```php
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

public function getActivitylogOptions(): LogOptions
{
    return LogOptions::defaults()
        ->logFillable()
        ->logOnlyDirty()        // hanya kolom yang benar-benar berubah
        ->dontLogEmptyChanges()
        ->useLogName('siswa');
}
```

Tidak perlu mengubah apa pun di resource Filament — tabel log otomatis menampilkannya.

Bentuk data per event, berguna saat menulis tes:

| Event | Isi `attribute_changes` |
|---|---|
| `created` | `attributes` saja |
| `updated` | `attributes` (nilai baru) + `old` (nilai lama) |
| `deleted` | `old` saja |

### Keamanan

- `default_except_attributes` di `config/activitylog.php` berisi `password` dan `remember_token`. **Jangan dikosongkan** — kalau kosong, hash password ikut tersimpan di `activity_log` tiap kali user di-update.
- Kalau menambah model dengan kolom sensitif (NIK, nomor rekening, token), tambahkan `->logExcept([...])` di `getActivitylogOptions()`. Nilai ini digabung dengan `default_except_attributes`.
- Listener `recordFailed` sengaja **tidak** membaca `$event->credentials['password']`. Jangan diubah jadi menyimpan seluruh array credentials.

### Resource Filament — read-only

`app/Filament/Resources/Activities/` sengaja tidak punya page `create`/`edit`, dan `canCreate()`/`canEdit()`/`canDelete()` semuanya `false`. Audit trail yang bisa disunting tidak ada gunanya — jangan dibuka.

Akses dibatasi lewat `canAccess()` yang mengecek permission `lihat-log-aktivitas`. Terpisah dari `akses-panel-admin`, jadi user bisa dimasukkan ke panel tanpa sekalian diberi jejak audit.

### Pembersihan

`activitylog:clean --force` terjadwal harian jam 02:00 di `routes/console.php`, dengan `onOneServer()` supaya tidak jalan dobel kalau nanti ada lebih dari satu server. Batas umurnya `clean_after_days` (default 365) di `config/activitylog.php`. Scheduler perlu cron aktif di server (`php artisan schedule:run` tiap menit).

`--force` khusus untuk produksi. `CleanActivitylogCommand` memakai `ConfirmableTrait`, yang **hanya** meminta konfirmasi kalau `APP_ENV=production`. Di cron tidak ada yang menjawab, jadi tanpa `--force` perintahnya berhenti diam-diam (`return 1`, tanpa error mencolok) dan `activity_log` tumbuh terus. Di lokal flag ini tidak berpengaruh — jangan dilepas hanya karena "di dev jalan-jalan saja".

### Mematikan sementara

`ACTIVITYLOG_ENABLED=false` di `.env`. Berguna saat impor data massal supaya tidak membanjiri tabel.

## Backup (spatie/laravel-backup)

### UI di panel — `/admin/backups`

Menu **Backup** (`$navigationSort` 80, tanpa grup, bertetangga dengan Log Aktivitas). Izinnya `kelola-backup`, **terpisah dari `akses-panel-admin`** — halaman ini memperlihatkan kapan database terakhir ditangkap dan berjarak satu klik dari menyerahkan seluruh isinya, jadi tidak ikut terbawa hanya karena seseorang boleh masuk panel.

Satu aksi di halaman ini dijaga izin **kedua**: tombol **Pulihkan** butuh `pulihkan-backup`. Pemegang `kelola-backup` melihat halamannya tanpa tombol itu. Alasannya di bagian *Restore*.

Isinya: ringkasan status di subheading (jumlah arsip, total ukuran, umur arsip terbaru), tabel arsip, dan tiga aksi.

| Aksi | Perilaku |
|---|---|
| **Ubah Jadwal** | Form frekuensi/hari/jam, disimpan ke `backup_schedules` |
| **Password Arsip** | Password enkripsi AES-256, disimpan terenkripsi di `backup_schedules.archive_password` |
| **Backup Sekarang** | Dispatch `App\Jobs\RunBackup` ke queue, bukan dijalankan di request |
| **Unduh** | Streaming download, dicatat ke `activity_log` |
| **Hapus** | Konfirmasi wajib, arsip terbaru dikunci, dicatat ke `activity_log` |
| **Pulihkan** | Izin terpisah `pulihkan-backup`, konfirmasi ketik nama berkas, lihat *Restore* |

**Bukan Resource, melainkan `Filament\Pages\Page`.** Backup itu berkas di disk, bukan record Eloquent — tidak ada model, tidak ada id, tidak ada yang bisa di-query. Tabelnya diisi lewat `->records()`, bukan `->query()`, sehingga tiap baris sampai ke closure sebagai **array biasa, bukan Model**. Semua closure di halaman itu karena itu bertipe `array $record`.

#### Kunci baris adalah input pengguna

Konsekuensi paling penting dari tabel non-Eloquent, dan alasan `resolveBackup()` ada.

Kunci tiap baris adalah path arsip, dan Filament memulangkan kunci itu lewat browser setiap kali aksi dipanggil — artinya ia **kembali sebagai input pengguna**. Memakainya langsung sebagai path akan membuka path traversal: `../../../.env` akan mengunduh kredensial yang justru dijaga mati-matian supaya tidak ikut terarsip.

Karena itu kunci **tidak pernah dipakai sebagai path**. Ia dicocokkan dengan daftar path yang benar-benar dilaporkan disk, dan hanya yang cocok yang dieksekusi.

Ada dua lapis, keduanya dikunci tes:

1. Filament lebih dulu mencocokkan kunci dengan record yang dirender, dan melempar `ActionNotResolvableException` kalau tidak ketemu.
2. `resolveBackup()` mencocokkan lagi dengan isi disk, lalu `abort(404)`.

`test_a_forged_record_key_cannot_reach_a_file_outside_the_backup_folder` menembakkan kunci palsu ke tombol unduh **dan** hapus, lalu memastikan berkas di luar folder backup tidak tersentuh. **Kalau menambah aksi baru di halaman ini, lewatkan recordnya melalui `resolveBackup()` — jangan pernah menyentuh `$record` sebagai path.**

#### Arsip terbaru tidak bisa dihapus

Dijaga dua kali: `->disabled()` di tombolnya (diperiksa server-side, lihat bagian *Action Filament TIDAK ikut canEdit/canDelete*) **dan** dicek ulang di dalam `delete()` terhadap keadaan terkini — status "terbaru" bisa berubah antara halaman dirender dan tombol diklik.

#### Jadwal bisa diubah user — dan itu mengubah dua hal lain

`backup:run` **tidak** lagi hardcode di `routes/console.php`. Jadwalnya dibaca dari tabel `backup_schedules` (satu baris) lewat `App\Models\BackupSchedule::current()`. Default **mingguan, Minggu 01:30**.

Barisnya dibuat saat pertama dibaca, bukan lewat seeder, supaya instalasi baru dan yang sudah jalan menempuh jalur sama. Pembuatan default itu dibungkus `withoutEvents()` — nilai defaultnya bukan pilihan siapa pun, dan kalau dicatat normal ia akan tampil sebagai `created` atas nama orang yang kebetulan pertama membuka halaman. Perubahan sungguhan lewat form tetap tercatat sebagai `updated`.

`backup:clean` (01:00) dan `backup:monitor` (07:00) **tetap hardcode**. Keduanya perawatan; membiarkannya ikut disetel membuka skenario cleanup tiap jam sementara backup sebulan sekali.

**Dua konsekuensi yang tidak terlihat dari form:**

**1. `routes/console.php` sekarang membaca database.** File itu dieksekusi pada **setiap** invokasi artisan, termasuk `migrate` di database yang belum punya tabelnya. Karena itu pembacaannya dibungkus `try/catch (QueryException)` — tabel hilang berarti "jadwal tidak didaftarkan", bukan crash. Tanpa itu, instalasi baru tidak bisa boot cukup jauh untuk memperbaiki dirinya sendiri: migrasi yang membuat tabel tidak akan pernah bisa jalan.

**2. Ambang health check ikut jadwal.** `MaximumAgeInDays` bawaan paket default 1 hari — benar hanya selama backup harian. Begitu jadwalnya mingguan, `backup:monitor` melapor "unhealthy" **tiap hari** mulai hari kedua. Alarm palsu harian persis yang membuat orang berhenti membaca notifikasi backup.

Diganti `App\Support\Backup\MaximumAgeMatchingSchedule`, yang menurunkan ambangnya dari jadwal:

| Frekuensi | Ambang |
|---|---|
| Harian | 2 hari |
| Mingguan | 8 hari |
| Bulanan | 32 hari |
| Dimatikan | praktis tak terbatas |

Satu interval plus satu interval kelonggaran: satu kali terlewat dimaafkan, dua kali berturut-turut tidak.

Aman terhadap `php artisan config:cache` — yang tersimpan di cache cuma nama class-nya; ambangnya dihitung saat `backup:monitor` jalan.

**Kalau menambah frekuensi baru di `App\Enums\BackupFrequency`, tambahkan juga cabangnya di `MaximumAgeMatchingSchedule`.** `match` di sana tidak punya `default`, jadi case baru akan melempar `UnhandledMatchError` — sengaja, supaya ketahuan saat itu juga ketimbang diam-diam memakai ambang yang salah.

#### Tombol Backup Sekarang lewat queue

`RunBackup` di-dispatch, tidak dijalankan langsung. Dump + zip memakan waktu yang tumbuh seiring isi database, dan timeout PHP di tengah proses meninggalkan berkas separuh jadi. Job-nya `tries = 1` dengan sengaja: backup gagal hampir selalu masalah konfigurasi (biner `sqlite3` hilang, disk tidak bisa ditulis), dan mengulang cuma mengulang kegagalan yang sama.

**Butuh queue worker jalan.** `composer run dev` sudah membawanya. Kalau worker mati, tombolnya tetap memberi notifikasi "sedang diproses" tapi tidak ada arsip yang pernah muncul — jalankan `php artisan queue:work` atau pakai `backup:run` di CLI.

### Variabel environment

| Key | Isi |
|---|---|
| `BACKUP_NAME` | Nama folder di disk tujuan **dan** nama yang dipantau. Ubah = mulai menulis ke folder baru |
| `BACKUP_ARCHIVE_PASSWORD` | Password AES-256 **cadangan** — dipakai hanya kalau belum disetel lewat panel. Hilang keduanya = seluruh arsip tidak bisa dibuka |
| `BACKUP_NOTIFICATION_EMAIL` | Tujuan notifikasi kegagalan |
| `DB_DUMP_BINARY_PATH` | Direktori biner `sqlite3`, diakhiri garis miring. Kosong = ikut PATH |

### Config

Config di `config/backup.php`. Arsip mendarat di disk `backups` (`storage/app/backups/school-management/`), sudah ikut ter-gitignore lewat `storage/app/.gitignore`.

```bash
php artisan backup:run       # buat arsip sekarang
php artisan backup:list      # daftar arsip + status sehat/tidak
php artisan backup:clean     # hapus arsip lama sesuai aturan retensi
php artisan backup:monitor   # cek umur & ukuran, kirim notifikasi kalau bermasalah
```

Terjadwal di `routes/console.php`: `backup:clean` 01:00 dan `backup:monitor` 07:00 (keduanya tetap, harian), sementara `backup:run` mengikuti jadwal yang disetel user — default mingguan. Semua `onOneServer()`, `backup:run` juga `withoutOverlapping()`.

Urutan clean vs run tidak diikat: `DefaultStrategy` tidak pernah menghapus arsip terbaru, jadi keduanya aman dalam urutan apa pun. `backup:clean` dijauhkan dari `activitylog:clean` (02:00) supaya tidak menyentuh `database.sqlite` bersamaan.

### Yang di-backup — dan yang sengaja tidak

| Masuk arsip | Tidak masuk |
|---|---|
| Dump database (`db-dumps/…sql.gz`) | `vendor/`, `node_modules/` |
| `storage/app/public` (berkas unggahan) | Kode aplikasi — sudah di git |
| | Asset panel — hasil `filament:assets` |
| | `public/build` — hasil `npm run build` |
| | **`.env`** |

Prinsipnya: hanya yang **tidak bisa dibuat ulang**. Sisanya lahir dari git + `composer install` + build.

**`.env` sengaja ada di `exclude`.** Isinya `APP_KEY` beserta semua kredensial, sementara arsip backup justru file yang paling mungkin disalin keluar server. Baris itu juga jaring pengaman kalau suatu saat `include` diperluas ke `base_path()` — jangan dihapus.

Konsekuensi yang harus disadari: **arsip ini tidak cukup untuk memulihkan aplikasi sendirian.** Restore = `git clone` + `composer install` + `.env` (dari brankas terpisah) + bongkar arsip + import dump — langkah lengkapnya di bagian *Restore*.

### Enkripsi

Arsip dienkripsi AES-256. Passwordnya diambil dari `backup_schedules.archive_password` (disetel lewat tombol **Password Arsip** di panel), dan **jatuh ke `BACKUP_ARCHIVE_PASSWORD` di `.env`** kalau kolom itu masih kosong. Fallback ini yang membuat instalasi lama tetap menghasilkan arsip yang bisa dibuka pemiliknya — jangan dihapus.

Kolomnya bertipe `encrypted`, jadi dump database yang bocor tidak sekalian menyerahkan kunci tiap arsip di dalamnya. Konsekuensinya **`APP_KEY` ikut jadi taruhan**: rotasi `APP_KEY` membuat nilai itu tidak bisa didekripsi lagi, sama saja dengan kehilangan passwordnya.

#### Jebakan melingkar: password ada di dalam benda yang ia lindungi

Password yang disetel lewat panel hidup di database — database yang sama yang arsip itu backup. Kehilangan database berarti kehilangan password, dan **arsip yang dibuat setelah password disetel tidak bisa dibuka lagi**. Persis kebalikan dari gunanya backup.

Sudah kejadian di repo ini: arsip `2026-08-09-15-10-59.zip` dikunci password yang disetel lewat panel, lalu databasenya di-reset. Arsip itu tidak bisa dibuka dengan `BACKUP_ARCHIVE_PASSWORD` maupun password mana pun yang masih tercatat — sementara `2026-08-09-14-50-02.zip`, yang dibuat sebelum password panel disetel, tetap terbuka dengan nilai `.env`.

Yang menahan supaya ini tidak lebih sering terjadi:

- Fallback ke `BACKUP_ARCHIVE_PASSWORD` di `.env`. Selama kolom di panel **tidak pernah diisi**, password hidup di `.env` yang tidak ikut mati bersama database.
- Helper text di formnya menyuruh menyimpan salinan di luar server.

Keduanya bukan pengaman teknis — tidak ada tes yang bisa menangkap password yang cuma ada di satu tempat. **Setiap kali password disetel lewat panel, salinannya wajib masuk password manager di detik yang sama.** Kalau tidak siap melakukan itu, biarkan kolomnya kosong dan pakai `.env` saja.

**Password harus sampai ke dua jalur berbeda**, dan itu alasan `BackupSchedule::applyArchivePassword()` dipanggil dua kali di tempat berbeda:

| Jalur | Pemanggil | Kenapa perlu sendiri |
|---|---|---|
| Tombol **Backup Sekarang** | `App\Jobs\RunBackup::handle()` | Queue worker proses panjang — `routes/console.php` hanya dieksekusi sekali saat worker boot, nilainya sudah basi saat tombol diklik |
| Scheduler & `php artisan backup:run` | `routes/console.php` | Scheduler menjalankan artisan sebagai proses baru; file ini dieksekusi saat boot proses itu, dan barisnya sudah terbaca di sana (nol query tambahan) |

Melewatkan salah satunya = separuh arsip terkunci password berbeda, dan itu baru ketahuan **saat restore**. Yang mengunci: `test_the_queued_job_applies_the_password_before_running_backup` dan `test_the_panel_password_wins_over_the_env_password`.

Cara kerjanya menumpang perilaku paketnya: spatie mengikat `Config::class` dengan `$app->scoped()` yang membaca `config('backup')` secara lazy, jadi `config(['backup.backup.password' => ...])` yang diset **sebelum** `backup:run` jalan sudah cukup — tidak perlu menyentuh paketnya.

**Password tidak boleh masuk `activity_log`.** `LogsActivity` di `BackupSchedule` memakai `logFillable()`, yang akan menyalin nilainya mentah-mentah ke tabel yang bisa dibaca siapa pun pemegang `lihat-log-aktivitas` — kelompok yang jauh lebih luas daripada `kelola-backup`. Karena itu ada `->logExcept(['archive_password'])`, dan perubahannya dicatat manual di `Backups::savePassword()` sebagai event `password-arsip-diubah` **tanpa nilainya**. Dikunci `test_the_password_never_reaches_the_activity_log`.

Form-nya sengaja **memperlihatkan password yang sedang berlaku** (`revealable`, prefilled). Field write-only justru mengunci orang yang halaman ini dibuat untuknya: yang butuh membuka arsip tidak punya cara lain mengetahui kuncinya.

**Password hilang = seluruh arsip hilang selamanya.** Tidak ada jalur pemulihan. Simpan salinannya di luar server — password manager, bukan hanya `.env` yang ikut mati bersama mesinnya. Mengganti password tidak membuka arsip lama; arsip tetap terkunci dengan password saat ia dibuat.

**`unzip` biasa tidak bisa membukanya.** Info-ZIP mentok di format v4.6, sedangkan AES butuh v5.1 — errornya berbunyi `need PK compat. v5.1 (can do v4.6)` dan itu **bukan** tanda password salah. Pakai `7z`:

```bash
sudo apt install p7zip-full
7z x -p"$BACKUP_ARCHIVE_PASSWORD" storage/app/backups/school-management/2026-08-09-01-30-00.zip
```

Kalau `7z` tidak tersedia, PHP sendiri bisa (libzip sudah mendukung AES):

```bash
php -r '$z = new ZipArchive; $z->open($argv[1]); $z->setPassword($argv[2]); $z->extractTo($argv[3]);' -- ARSIP.zip PASSWORD ./tujuan
```

### Restore

Isi arsip cuma dua, jadi restore juga dua hal: dump database dan `storage/app/public`. Sisanya (kode, `vendor/`, `.env`) datang dari luar arsip — lihat *Yang di-backup — dan yang sengaja tidak*.

**`APP_KEY` harus sama dengan saat arsip dibuat.** Ia tidak ikut di dalam arsip, jadi harus datang dari `.env` yang disimpan terpisah. Beda `APP_KEY` = tiap nilai terenkripsi di database tidak bisa dibaca lagi — termasuk `backup_schedules.archive_password` — dan semua sesi login batal.

#### Lewat panel — tombol Pulihkan

Aksi baris **Pulihkan** di `/admin/backups`, dijalankan `App\Support\Backup\RestoreArchive`. Izinnya `pulihkan-backup`, **bukan** `kelola-backup`: restore mengganti tabel `users` dengan versi arsip, jadi pemegangnya bisa menghidupkan akun lama yang passwordnya ia tahu atau membatalkan pencabutan role. Itu kekuasaan lain dari "boleh mengunduh arsip".

Aksinya `->visible()` pada izin itu **dan** `abort_unless` di dalam `restore()`. Yang kedua bukan duplikasi: action Filament tidak punya otorisasi otomatis, jadi aksi yang bisa dipanggil namanya bisa dipanggil siapa saja — lihat *Action Filament TIDAK ikut canEdit()/canDelete()*. Konfirmasinya mengetik ulang **nama berkas**, bukan kata generik, karena itu satu-satunya konfirmasi yang juga menangkap klik di baris yang salah.

**Database hidup tidak pernah ditulisi.** Ini poros seluruh desainnya:

```
ekstrak arsip → impor dump ke file sqlite SEMENTARA → validasi → rename() ke tempatnya
      (lambat, nol dampak)                                        (milidetik, atomik)
```

Dua hal yang mahal kalau dibalik:

- **Impor langsung ke `database.sqlite` lalu timeout di tengah** meninggalkan database dengan sebagian tabel terpulihkan dan sebagian tidak, tanpa jalan pulang. Dengan staging, timeout cuma membuang file temp.
- **Restore TIDAK boleh lewat queue**, walau itu jawaban yang biasa untuk pekerjaan lambat. `QUEUE_CONNECTION=database`: worker mencatat job yang sedang jalan di tabel `jobs` — tabel yang sedang diganti. Job restore akan menarik lantai dari bawah kakinya, dan tabel `jobs` hasil restore bisa berisi job lama dari masa arsip yang ikut dieksekusi. `RunBackup` memang milik queue; ini kebalikannya.

Pengaman lain, semuanya dikunci `BackupRestoreTest`:

| Pengaman | Kenapa |
|---|---|
| Hanya SQLite | Driver lain berhenti di awal, ketimbang setengah jalan lewat jalur kode yang salah |
| Entri zip disaring (`db-dumps/`, `storage/app/public/`, tanpa `..`) | Ekstraktor yang menulis ke mana pun nama entri menunjuk berhenti aman begitu ada tombol unggah arsip |
| Dump tanpa tabel `users`/`migrations` ditolak | Gagal sebelum swap, bukan sesudah |
| Dump dengan nol pengguna ditolak | Restore-nya "berhasil" lalu tidak ada yang bisa login untuk membatalkannya |
| Salinan pengaman ke `storage/app/pre-restore/` | Satu-satunya jalan pulang. Diambil tepat sebelum `rename()` |
| `-wal` dan `-shm` dihapus setelah swap | Milik database yang baru saja pergi; kalau ditinggal, SQLite memutar ulang tulisan yang justru mau dibatalkan |
| Kunci baris lewat `resolveBackup()` | Sama seperti Unduh dan Hapus — kunci baris itu input pengguna |

Setelah swap: `migrate --force` (arsip membawa tabel `migrations`-nya sendiri, jadi hanya migrasi yang lebih baru yang jalan) lalu `forgetCachedPermissions()`. Yang terakhir wajib — cache permission menyimpan **id**, dan id di database hasil restore berbeda.

**Yang belum ditangani: `RestoreArchive` tidak menjalankan `RolePermissionSeeder`.** Migrasi mengembalikan struktur, bukan baris. Memulihkan arsip yang lebih tua dari case terbaru di `App\Enums\Permission` meninggalkan izin itu tanpa baris di tabel `permissions` — dan izin yang tidak ada di tabel selalu `false`, jadi menunya hilang diam-diam untuk semua orang kecuali `super-admin` yang lolos lewat `Gate::before`. Sudah terjadi saat memulihkan arsip 14:50 di repo ini. Sampai `settleApplication()` ikut memanggil seeder, **jalankan manual setelah restore lewat panel**:

```bash
php artisan db:seed --class=RolePermissionSeeder
```

**Yang menjalankan pasti logout**, karena tabel `sessions` ikut diganti. Karena itu aksinya redirect ke halaman login tanpa notifikasi: notifikasi Filament diflash ke session yang barusan lenyap. Layar login itulah pesannya.

Salinan di `storage/app/pre-restore/` **tidak pernah dibersihkan otomatis** — masing-masing sebesar database saat itu. Hapus manual setelah yakin hasil restore benar.

#### Lewat CLI

Dipakai saat panelnya sendiri tidak bisa dibuka, atau saat databasenya bukan SQLite.

Langkahnya sengaja meniru `RestoreArchive`: bangun dulu di file sementara, baru tukar. Jangan mengimpor langsung ke `database/database.sqlite`.

```bash
# 1. Salinan pengaman — satu-satunya jalan pulang.
mkdir -p storage/app/pre-restore
cp database/database.sqlite storage/app/pre-restore/manual-$(date +%Y-%m-%d-%H-%M-%S).sqlite

# 2. Ekstrak arsip (butuh password, lihat bagian Enkripsi).
mkdir -p /tmp/restore
7z x -o/tmp/restore -p"$BACKUP_ARCHIVE_PASSWORD" \
  storage/app/backups/school-management/2026-08-09-01-30-00.zip
gunzip -c /tmp/restore/db-dumps/*.sql.gz > /tmp/restore/dump.sql

# 3. Bangun database pengganti DI SEBELAH yang asli — harus di database/,
#    karena rename() cuma atomik dalam satu filesystem.
sqlite3 -bail database/.restore-manual.sqlite < /tmp/restore/dump.sql

# 4. Validasi sebelum menyentuh yang hidup. Nol user = jangan diteruskan.
sqlite3 database/.restore-manual.sqlite 'select count(*) from users;'

# 5. Tukar. Ini satu-satunya langkah yang tidak bisa dibatalkan.
mv database/.restore-manual.sqlite database/database.sqlite
rm -f database/database.sqlite-wal database/database.sqlite-shm

# 6. Berkas unggahan.
cp -a /tmp/restore/storage/app/public/. storage/app/public/

# 7. Samakan skema, isi, dan cache dengan kode yang sedang jalan.
php artisan migrate --force
php artisan db:seed --class=RolePermissionSeeder
php artisan permission:cache-reset
php artisan config:clear
```

Empat hal yang tidak kelihatan dari perintahnya:

**Kenapa staging, bukan impor langsung.** Dump berisi `CREATE TABLE IF NOT EXISTS` dan `INSERT` polos. Diimpor ke database yang masih berisi tabel, ia **tidak menimpa apa pun** — hasilnya campuran data lama dan data arsip, `INSERT`-nya bentrok primary key lalu berhenti separuh jalan, dan exit code-nya tetap 0. File kosong yang baru dibuat tidak punya masalah itu, dan kegagalan di langkah 3 tidak menyentuh apa pun.

**`composer run dev` tidak perlu dimatikan.** Setelah `mv`, proses yang masih memegang file lama menulis ke inode yang sudah dilepas, bukan ke database baru. Tulisan itu hilang bersama filenya — yang justru diinginkan. Ini keuntungan langsung dari swap; jalur "kosongkan lalu impor" memang mengharuskan semuanya berhenti.

**Restore memundurkan skema *dan* isi seeder** — dan keduanya butuh langkah berbeda. `migrate --force` mengembalikan strukturnya (arsip membawa tabel `migrations` sendiri, jadi hanya migrasi yang lebih baru yang jalan). Tapi migrasi tidak menambah **baris**: role dan permission yang lahir setelah arsip dibuat tetap hilang sampai `RolePermissionSeeder` dijalankan. Terjadi persis begitu saat memulihkan arsip 14:50 di repo ini — `pulihkan-backup` hilang, dan tombol Pulihkan mati untuk semua orang kecuali `super-admin` yang lolos lewat `Gate::before`.

**`permission:cache-reset` bukan formalitas.** Cache permission Spatie menyimpan **id**, bukan nama, dan database hasil restore punya id yang lain. Cache basi adalah cara paling umum orang mengunci dirinya dari `/admin` padahal datanya utuh.

Verifikasi sebelum membuang salinannya:

```bash
php artisan tinker --execute="
\$u = App\Models\User::first();
echo 'user: '.\$u->email.' | role: '.\$u->getRoleNames()->implode(', ').PHP_EOL;
echo 'role: '.App\Models\Role::count().' | izin: '.App\Models\Permission::count().PHP_EOL;
echo 'log: '.Spatie\Activitylog\Models\Activity::count().PHP_EOL;
echo 'akses panel: '.var_export(\$u->canAccessPanel(Filament\Facades\Filament::getPanel('admin')), true).PHP_EOL;
"
```

Jumlah izin harus sama dengan jumlah case di `App\Enums\Permission`. Kalau kurang, langkah seeder terlewat.

Gagal? `cp storage/app/pre-restore/manual-*.sqlite database/database.sqlite`.

### Butuh biner `sqlite3`, bukan cuma PHP

Jebakan yang paling mungkin menggigit di produksi. Dumper SQLite milik `spatie/db-dumper` **tidak menyalin file database** — ia menjalankan shell:

```
echo 'BEGIN IMMEDIATE;\n.dump' | sqlite3 --bail database/database.sqlite
```

`BEGIN IMMEDIATE` itulah alasan dump lebih aman daripada menyalin filenya: ia mengambil kunci tulis, jadi tidak mungkin menangkap halaman yang setengah tertulis.

Konsekuensinya: **PHP boleh sempurna, `backup:run` tetap gagal kalau CLI `sqlite3` tidak terpasang.** Ekstensi PDO sqlite milik PHP tidak menggantikannya.

```bash
sudo apt install sqlite3
```

Kalau `backup:run` manual berhasil tapi lewat scheduler gagal, hampir pasti PATH — PATH milik cron jauh lebih sempit daripada shell interaktif. Isi `DB_DUMP_BINARY_PATH` di `.env` dengan **direktorinya**, diakhiri garis miring:

```
DB_DUMP_BINARY_PATH=/usr/bin/
```

Dibaca dari `config/database.php` → `connections.sqlite.dump.dump_binary_path`.

### Notifikasi

Hanya kabar buruk yang dikirim: backup gagal, cleanup gagal, backup tidak sehat. Notifikasi sukses sengaja dimatikan (`=> []`) — kabar sukses harian membuat orang berhenti membaca notifikasi backup, dan yang ikut terlewat adalah notifikasi gagalnya.

Tujuannya `BACKUP_NOTIFICATION_EMAIL`. Selama `MAIL_MAILER=log`, "email" itu hanya mendarat di `storage/logs/laravel.log` — **setel SMTP sebelum produksi**, kalau tidak kegagalan backup tidak sampai ke siapa pun.

### Ini belum backup sungguhan

Disk `backups` masih satu mesin dan satu disk dengan aplikasinya. Aman dari "kehapus tidak sengaja", **tidak** aman dari disk mati, server hilang, atau ransomware. Untuk produksi tambahkan disk kedua di luar mesin:

```php
'disks' => ['backups', 's3'],
```

Kredensial S3 sudah ada tempatnya di `config/filesystems.php`, tinggal isi `AWS_*` di `.env`.

### Kalau nanti pindah dari SQLite

Ganti `DB_CONNECTION` saja tidak cukup — `backup.source.databases` mengikuti env itu, tapi binernya berubah (`mysqldump`/`pg_dump`) dan harus ikut terpasang di server.

## Broadcasting (laravel/reverb)

Server WebSocket sendiri, dipasang lewat `php artisan install:broadcasting --reverb`. Belum ada satu pun event yang di-broadcast — yang sudah jadi baru fondasinya, sama seperti sisa repo ini.

| Berkas | Isi |
|---|---|
| `config/reverb.php` | Server, aplikasi, dan penskalaan |
| `config/broadcasting.php` | Koneksi `reverb`, `pusher`, `ably`, `log`, `null` |
| `routes/channels.php` | Otorisasi channel privat — didaftarkan di `bootstrap/app.php` |
| `resources/js/echo.js` | Klien Echo, diimpor dari `resources/js/app.js` |

```bash
php artisan reverb:start           # jalankan server WebSocket (0.0.0.0:8080)
php artisan reverb:start --debug   # plus dump tiap pesan masuk/keluar
php artisan reverb:restart         # suruh worker yang jalan berhenti dengan rapi
```

`reverb:restart` **tidak menyalakan apa pun** — ia cuma menaruh sinyal supaya worker yang sedang jalan berhenti di titik aman. Yang menghidupkannya lagi adalah supervisor. Dijalankan tanpa supervisor, servernya mati dan tidak kembali.

`composer run dev` sudah membawa `reverb:start` sebagai proses ke-3, bertetangga dengan queue.

### Reverb bicara protokol Pusher

Itu sebabnya kliennya tetap `pusher-js` + `laravel-echo`, dan `broadcaster: 'reverb'` di `echo.js` sebenarnya cuma preset Pusher yang diarahkan ke host sendiri. Konsekuensi praktisnya: dokumentasi, tooling debug, dan library klien Pusher mana pun tetap berlaku — yang hilang cuma tagihan per-pesan dan ketergantungan ke layanan luar.

### Kredensial

| Key | Isi |
|---|---|
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Identitas aplikasi. Digenerate saat instalasi |
| `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME` | Yang dipakai **server PHP** saat menerbitkan pesan |
| `VITE_REVERB_*` | Yang dipakai **browser** saat menyambung |

`VITE_*` di-inline ke bundel saat `npm run build` — **bukan rahasia**, dan memang begitu desainnya: `REVERB_APP_KEY` publik, `REVERB_APP_SECRET` tidak pernah ikut ke frontend. Karena tertanam di bundel, **mengubah `REVERB_HOST`/`REVERB_PORT` mengharuskan build ulang** — `config:clear` saja tidak cukup dan gejalanya berupa browser yang tetap menyambung ke alamat lama.

`REVERB_HOST` di `.env` dipakai dua arah dengan arti berbeda. Di produksi keduanya biasanya berbeda: server menerbitkan ke `127.0.0.1`, browser menyambung ke domain publik lewat `wss://` di port 443. Pisahkan nilainya, jangan biarkan `VITE_REVERB_HOST` ikut `${REVERB_HOST}` begitu saja seperti default instalasi.

### Produksi butuh reverse proxy dan supervisor

Dua hal yang tidak terlihat dari `reverb:start`:

- **`reverb:start` adalah proses yang harus terus hidup**, bukan perintah sekali jalan. Tanpa supervisor (systemd/supervisord), ia mati bersama sesi SSH dan tidak ada yang menyalakannya lagi. Ini kelas masalah yang sama dengan queue worker.
- **Browser di `https://` menolak menyambung ke `ws://`.** Nginx harus mem-proxy `wss://` ke port 8080 dengan header `Upgrade`/`Connection`, kalau tidak koneksi ditolak di sisi klien tanpa jejak apa pun di log server.

Batas file descriptor juga ikut berlaku: tiap koneksi WebSocket = satu file descriptor, jadi `ulimit -n` bawaan (1024) adalah plafon jumlah pengguna yang tersambung.

### Otorisasi channel

`routes/channels.php` baru berisi channel bawaan `App.Models.User.{id}` yang cuma mencocokkan id. Channel apa pun yang menyiarkan data terbatas **wajib mengecek permission**, bukan sekadar "user login" — closure yang mengembalikan `true` polos membuat channel itu terbuka untuk semua akun.

```php
Broadcast::channel('backup', fn (User $user) => $user->can(Permission::KelolaBackup->value));
```

Pakai enum, jangan string mentah — aturannya sama dengan seluruh repo ini, lihat *Nama role & permission ada di enum*.

**`super-admin` otomatis lolos** lewat `Gate::before`, karena `can()` melewati gate yang sama. Tidak perlu dikecualikan manual.

Closure ini dijalankan lewat route `POST /broadcasting/auth` yang didaftarkan otomatis oleh `withRouting(channels: ...)`. Route itu bermiddleware `web`, jadi otorisasinya bersandar pada **session yang sama dengan panel admin** — bukan token terpisah. Dua konsekuensi:

- Kalau nanti ada klien di luar browser (mobile, API), ia butuh jalur auth sendiri; session cookie tidak sampai ke sana.
- Restore backup mengganti tabel `sessions`, jadi setiap koneksi WebSocket privat ikut kehilangan dasar otorisasinya bersamaan dengan logout — lihat *Restore*.

### Panel Filament belum memuat Echo

Jebakan yang paling mungkin memakan waktu di repo ini. `resources/js/app.js` — satu-satunya berkas yang mengimpor `echo.js` — hanya dirujuk dari `resources/views/welcome.blade.php` lewat `@vite`. **Panel Filament punya pipeline asset sendiri dan tidak pernah memuat berkas itu.** Artinya `window.Echo` `undefined` di seluruh `/admin`, sementara `/` (halaman welcome bawaan Laravel) justru punya — kebalikan dari yang dibutuhkan, karena panel itulah satu-satunya UI sungguhan di sini.

Belum dikerjakan karena belum ada event yang disiarkan. Saat dibutuhkan, **dua langkah**, dan yang pertama gampang terlewat:

**1. Jadikan `echo.js` entry Vite.** Sekarang ia bukan entry — ia ikut terbundel ke dalam `app.js`, jadi tidak punya baris sendiri di `public/build/manifest.json`. Memanggil `Vite::asset('resources/js/echo.js')` tanpa langkah ini melempar *"Unable to locate file in Vite manifest"*, dan pesannya tidak menyebut penyebab sesungguhnya. Tambahkan ke `vite.config.js`:

```js
input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/echo.js'],
```

**2. Daftarkan ke panel** lewat `FilamentAsset` di sebuah service provider:

```php
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Vite;

FilamentAsset::register([
    Js::make('echo', Vite::asset('resources/js/echo.js'))->module(),
]);
```

`->module()` wajib — `echo.js` memakai `import`, dan tanpa `type="module"` browser menolaknya.

Setelah `echo.js` jadi entry, **lepaskan `import './echo'` dari `app.js`** kalau halaman welcome tetap dipertahankan. Kalau tidak, halaman itu memuat Echo dua kali: sekali lewat bundel `app.js`, sekali lewat entry baru. Dua instance `window.Echo` = dua koneksi WebSocket per tab, dan yang belakangan menimpa yang duluan sehingga listener yang sudah terpasang diam-diam berhenti menerima.

### `BROADCAST_CONNECTION` sekarang `reverb`, bukan `log`

Selama belum ada event yang di-broadcast, ini tidak berdampak. Begitu ada, event `ShouldBroadcast` yang diterbitkan **saat server Reverb mati** akan gagal terkirim — dan karena `ShouldBroadcast` lewat queue, kegagalannya mendarat di `failed_jobs`, bukan di layar. Untuk mematikan sementara tanpa menyentuh kode: `BROADCAST_CONNECTION=log`.

## Tes

`composer run test` — semuanya harus hijau sebelum commit. Database tes SQLite `:memory:` (`phpunit.xml`), jadi tidak menyentuh `database/database.sqlite`.

Kalau yang menjalankan adalah AI agent, outputnya berupa satu baris JSON, bukan tampilan PHPUnit biasa. Itu ulah `laravel/pao` — lihat bagian *Tooling dev*.

| File | Yang dijaga |
|---|---|
| `AdminPanelAccessTest` | Siapa boleh membuka `/admin`, dan panel id asing tidak mewarisi aturan admin |
| `RolePermissionTest` | Guard `web` cocok, `Gate::before` super-admin, developer tidak ikut bypass, tiap role dapat izin bawaannya dan tidak lebih, seeder idempoten & tidak mencabut, dan seeding lewat `DatabaseSeeder` benar-benar memberikan izinnya |
| `ActivityLogTest` | Login/gagal login tercatat, password tidak bocor, halaman log render, filter pelaku |
| `AccessManagementUiTest` | Form pengguna & role, pengaman anti-terkunci, perubahan otorisasi masuk log |
| `TableActionAuthorizationTest` | Tombol Ubah/Hapus benar-benar menolak, bukan cuma `can*()` yang bilang `false` |
| `BackupConfigurationTest` | Tujuan backup, `.env` tidak ikut terarsip, nama pantauan cocok, jadwal terdaftar |
| `BackupPageTest` | Izin halaman backup, tombol unduh/hapus, arsip terbaru terlindungi, kunci baris palsu ditolak |
| `BackupScheduleTest` | Default mingguan, cron tiap frekuensi, scheduler pakai jadwal user, tabel hilang tidak bikin crash, ambang monitor ikut frekuensi |
| `BackupArchivePasswordTest` | Password panel menang atas `.env`, fallback ke `.env`, sampai ke kedua jalur backup, terenkripsi di DB, tidak bocor ke `activity_log` |
| `BackupRestoreTest` | Tombol Pulihkan butuh izinnya sendiri, salah ketik nama berkas menolak, swap berhasil, dan **tiap jalur gagal meninggalkan database hidup utuh**. Belum menjaga: izin baru yang hilang setelah memulihkan arsip lama |

**Broadcasting belum punya tes sama sekali.** Disengaja selama belum ada event yang disiarkan — tidak ada perilaku yang bisa dikunci. Begitu channel pertama lahir, yang wajib diuji adalah **closure otorisasinya**, bukan pengirimannya:

```php
$this->actingAs($tanpaIzin)->postJson('/broadcasting/auth', [
    'channel_name' => 'private-backup',
    'socket_id' => '1234.5678',
])->assertForbidden();
```

Menguji `Event::fake()` + `assertDispatched` cuma membuktikan event terkirim, dan itu bagian yang paling tidak mungkin salah. Yang benar-benar berisiko adalah channel yang lolos ke akun yang seharusnya tidak melihat datanya — kelas bug yang sama dengan *Action Filament TIDAK ikut `canEdit()`/`canDelete()`*: pengaman di satu lapis tidak otomatis berlaku di lapis lain.

`tests/Feature/ExampleTest.php` dan `tests/Unit/ExampleTest.php` masih bawaan Laravel. Yang Feature menjaga halaman `/` (view `welcome`) tetap 200 — kalau `routes/web.php` diganti, tes itu ikut diganti atau dihapus, jangan dibiarkan merah.

Beberapa tes menjaga hal yang **tidak kelihatan dari kode** — jangan dihapus karena terlihat sepele:

- `assertCount(1, ...)` pada tes log: menangkap listener yang terdaftar dua kali (lihat bagian `recordX`).
- `test_every_enum_permission_is_seeded` dan `test_every_enum_role_is_seeded`: menangkap case enum yang lupa di-seed.
- `test_each_role_receives_its_baseline_permissions_and_no_more`: memeriksa **dua arah**. Menguji grantnya saja akan meloloskan role yang kelebihan izin, dan itu justru kegagalan yang penting.
- `test_reseeding_does_not_revoke_a_permission_granted_by_hand`: menangkap seeder yang diubah jadi `syncPermissions`, yang akan membuat tiap deploy membatalkan penyesuaian izin lewat panel.
- `test_seeding_through_the_database_seeder_grants_role_permissions`: satu-satunya tes yang memanggil `$this->seed()` tanpa argumen, jadi satu-satunya yang melewati `WithoutModelEvents` milik `DatabaseSeeder` — jalur yang sebenarnya dipakai `migrate:fresh --seed`. Assert-nya sengaja pada **grant**, bukan `Permission::count()`: barisnya tetap tertulis walau bug-nya kambuh, jadi menghitung baris akan hijau sepanjang kegagalan. Role yang diuji `guru`, bukan `super-admin`, karena yang terakhir lolos lewat `Gate::before` dan akan hijau apa pun keadaan cachenya. Latar belakangnya di bagian *`DatabaseSeeder` membungkam model event*.
- `test_the_package_resolves_the_app_models`: menangkap `config/permission.php` yang balik menunjuk model Spatie.
- `assertStringNotContainsString` pada tes gagal login: menangkap password yang ikut tersimpan.
- `callTableAction` + `assertModelExists` di `TableActionAuthorizationTest`: menangkap tombol hapus yang lolos pengaman. Memanggil `canDelete()` saja tidak cukup.

Menulis tes halaman Filament pakai Livewire, bukan HTTP:

```php
Livewire::test(ListUsers::class)->assertSuccessful();
Livewire::test(EditUser::class, ['record' => $user->getKey()])
    ->fillForm(['name' => 'Baru'])
    ->call('save')
    ->assertHasNoFormErrors();
```

Factory `UserFactory` punya dua state untuk menyiapkan hak akses:

```php
User::factory()->superAdmin()->create();
User::factory()->withPermissions([Permission::AksesPanelAdmin])->create();
```

Keduanya membuat role/permission-nya sendiri kalau seeder belum jalan.

## Menambah modul baru

Contoh: modul Siswa. Urutan ini menyentuh semua subsistem yang sudah ada — melewati satu langkah biasanya baru ketahuan jauh belakangan.

**1. Migrasi + model**

```bash
php artisan make:model Siswa -m
php artisan migrate
```

**2. Pasang audit log** di modelnya — lihat bagian *Menambah model baru ke audit log*. Kalau ada kolom sensitif (NIK, nomor HP wali), tambahkan `->logExcept([...])`.

**3. Tambah permission** ke `app/Enums/Permission.php`, lalu seed:

```php
case LihatSiswa = 'lihat-siswa';
case KelolaSiswa = 'kelola-siswa';
```

```bash
php artisan db:seed --class=RolePermissionSeeder
```

Jangan lewat, case enum tanpa baris di tabel `permissions` **selalu** `false`.

Lalu tentukan role mana yang mendapatkannya di `Role::permissions()`. `developer` otomatis ikut (`Permission::cases()`), sisanya tidak — role `guru` tidak akan bisa melihat modul guru sampai izinnya ditambahkan ke sana.

**4. Buat resource Filament**

```bash
php artisan make:filament-resource Siswa --generate --view
```

**5. Pasang `canAccess()`** di resource — tanpa ini menunya terbuka untuk semua yang bisa masuk panel:

```php
public static function canAccess(): bool
{
    return (bool) Filament::auth()->user()?->can(Permission::LihatSiswa->value);
}
```

**6. Jaga setiap `DeleteAction` / `EditAction`** dengan `->disabled(...)` kalau ada aturan yang membatasi. Baca bagian *Action Filament TIDAK ikut canEdit/canDelete* — ini bukan opsional.

**7. Tulis tesnya.** Minimal: user tanpa permission dapat 403, user dengan permission bisa render, dan setiap pengaman diuji dengan **memanggil tombolnya**, bukan memanggil `can*()`.

**8. Bahasa.** Label dibungkus `__()`. Teks bawaan Filament sudah otomatis Indonesia, yang perlu diterjemahkan hanya milik sendiri.

Terakhir: `./vendor/bin/pint` lalu `composer run test`.

## Tooling dev

`barryvdh/laravel-debugbar` terpasang sebagai **dev dependency** (`require-dev`).

- Auto-discovery, tidak perlu daftar provider manual.
- Dikendalikan `DEBUGBAR_ENABLED` di `.env`. Kalau tidak diset, ikut `APP_DEBUG`.
- `.env.example` sengaja `DEBUGBAR_ENABLED=false` — aman kalau di-copy ke server.
- **Jangan pernah aktif di produksi.** Debugbar membocorkan query SQL, isi session, dan variabel env.
- Config bisa di-publish kalau perlu tuning collector: `php artisan vendor:publish --provider="Barryvdh\Debugbar\ServiceProvider"`.

### laravel/pao — output tes berbeda untuk agent

`laravel/pao` juga dev dependency, auto-discovery. Fungsinya satu: **mengganti bentuk output phpunit ketika yang menjalankan adalah AI agent**, bukan manusia.

| Yang menjalankan | Output `composer run test` |
|---|---|
| Manusia di terminal | Output PHPUnit biasa — titik-titik, warna, ringkasan |
| Claude Code / agent lain | Satu baris JSON: `{"tool":"phpunit","result":"passed","tests":…,"passed":…,"duration_ms":…}` |

Deteksinya lewat `Laravel\AgentDetector` (variabel environment yang dipasang agent), dipanggil dari `src/Autoload.php` yang di-autoload lewat `files` — jadi aktif untuk **setiap** proses PHP yang memuat `vendor/autoload.php`, bukan cuma lewat artisan.

Yang memicu mode JSON adalah **nama binernya**, bukan cara memanggil: `phpunit`, `pest`, `paratest`, `phpstan`, `rector`. `./vendor/bin/phpunit` langsung pun tetap kena — memanggil biner mentah **bukan** jalan keluar.

Saklarnya environment variable:

```bash
PAO_DISABLE=1 ./vendor/bin/phpunit    # paksa output PHPUnit biasa (buat baca error panjang)
PAO_FORCE=1 php artisan test          # paksa output JSON walau bukan agent
```

Konsekuensi yang perlu diingat:

- **Perintahnya sama, tampilannya beda.** Kalau user bilang "output tes saya tidak seperti itu", bukan berarti salah satu salah — memang dua bentuk untuk perintah yang sama.
- **Jangan menulis skrip yang mem-parsing output tes** dengan asumsi salah satu bentuk. Bentuknya berubah tergantung siapa yang memanggil.
- Pao hanya mengubah tampilan. Exit code, tes yang jalan, dan hasilnya identik — aman diabaikan saat menilai hijau/merah.
- Ringkasan JSON memuat `failures` beserta pesannya, tapi sudah dipangkas. Kalau butuh stack trace utuh, ulangi dengan `PAO_DISABLE=1`.
- **Pint bukan bagian dari pao.** `./vendor/bin/pint` juga mengeluarkan JSON (`{"tool":"pint",...}`) untuk agent, tapi itu bawaan Pint sendiri — `PAO_DISABLE=1` tidak mengubahnya.

## Lokalisasi (Indonesia)

Aplikasi ini disetel untuk wilayah Indonesia. Jangan kembalikan ke default Laravel (UTC / `en`).

### Zona waktu

- `config/app.php` → `'timezone' => env('APP_TIMEZONE', 'Asia/Jakarta')` (WIB, UTC+7)
- `.env` / `.env.example` → `APP_TIMEZONE=Asia/Jakarta`
- Zona lain kalau dibutuhkan: `Asia/Makassar` (WITA), `Asia/Jayapura` (WIT) — cukup ganti env, jangan hardcode di config.
- Laravel menyimpan `created_at`/`updated_at` memakai timezone ini. Kalau timezone diubah setelah ada data, timestamp lama tidak ikut bergeser.

### Bahasa

- `config/app.php` → `'locale' => env('APP_LOCALE', 'id')`, `'faker_locale' => env('APP_FAKER_LOCALE', 'id_ID')`
- `'fallback_locale'` sengaja tetap `en` — jaring pengaman untuk key terjemahan yang belum ada di `id`.
- `.env` / `.env.example` → `APP_LOCALE=id`, `APP_FAKER_LOCALE=id_ID`

### File terjemahan

Laravel tidak membawa terjemahan `id` bawaan (beda dengan Filament, yang bawa sendiri). File `id` untuk Laravel ditulis manual di repo ini:

```
lang/en/   <- hasil `php artisan lang:publish` (referensi key)
lang/id/   <- terjemahan Indonesia: validation, auth, passwords, pagination
```

Kalau menambah key baru, tambahkan ke **kedua** folder. Kalau upgrade Laravel menambah rule validasi baru, jalankan `php artisan lang:publish` lalu terjemahkan key baru ke `lang/id/validation.php`.

### Carbon

`app/Providers/AppServiceProvider.php` boot():

```php
Carbon::setLocale(config('app.locale'));
CarbonImmutable::setLocale(config('app.locale'));
```

Ikut `config('app.locale')` — jangan hardcode `'id'`.

**Penting di Blade/controller:** `format()` TIDAK diterjemahkan (tetap `Saturday, 08 August`). Untuk tanggal berbahasa Indonesia pakai:

- `->translatedFormat('l, d F Y H:i')` → `Sabtu, 08 Agustus 2026 22:40`
- `->isoFormat('dddd, D MMMM YYYY')` → `Sabtu, 8 Agustus 2026`
- `->diffForHumans()` → `3 hari yang lalu`

## Konvensi

- Teks yang tampil ke user dibungkus `__()` supaya ikut locale, jangan hardcode di Blade.
- Setelah mengubah apa pun di `config/` atau `.env`, jalankan `php artisan config:clear`.
- Prefix pesan commit ikut pola yang ada: `[ADD]`, `[FIX]`, `[UPDATE]`.
- Bahasa diskusi dengan user: Bahasa Indonesia.

## Checklist deploy produksi

1. `composer install --no-dev --optimize-autoloader` — mengeluarkan Debugbar sepenuhnya.
2. `php artisan filament:assets` — asset panel tidak ada di git.
3. `npm run build`
4. `php artisan migrate --force`
5. Pastikan `APP_DEBUG=false` dan `DEBUGBAR_ENABLED` tidak `true`.
6. `php artisan db:seed --class=RolePermissionSeeder` — role dan permission harus ada, kalau tidak semua pengecekan akses `false` dan tidak ada yang bisa masuk panel. Seeder ini tidak membuat user, jadi aman di produksi. Aman juga diulang tiap rilis: ia menambah, tidak pernah mencabut, jadi penyesuaian izin yang dibuat lewat panel tetap utuh.
7. **Jangan jalankan `db:seed` polos** — itu ikut menjalankan `AdminUserSeeder` yang memakai password `admin`. Buat akun produksi lewat `php artisan make:filament-user`, lalu `assignRole('super-admin')` manual. Tanpa role, akun barunya kena 403.
8. Pastikan cron scheduler aktif, kalau tidak `activitylog:clean` dan seluruh perintah backup tidak pernah jalan — `activity_log` tumbuh tanpa batas dan tidak ada arsip yang pernah dibuat.
9. `sudo apt install sqlite3` — `backup:run` **dan** tombol Pulihkan sama-sama butuh binernya, ekstensi PHP saja tidak cukup.
10. Setel password arsip lewat tombol **Password Arsip** di `/admin/backups` (atau isi `BACKUP_ARCHIVE_PASSWORD` di `.env` sebagai cadangan), **lalu simpan salinannya di luar server**. Keduanya kosong = arsip tidak terenkripsi, tanpa peringatan apa pun. Password disimpan terenkripsi dengan `APP_KEY` — rotasi `APP_KEY` = password hilang. Password yang disetel lewat panel hidup di database yang ia backup: kehilangan database = kehilangan password = arsip mati. Lihat *Jebakan melingkar* di bagian Enkripsi.
11. Setel SMTP sungguhan. Dengan `MAIL_MAILER=log`, notifikasi backup gagal hanya masuk file log dan tidak dibaca siapa pun.
12. Jalankan `php artisan backup:run` sekali secara manual, lalu `php artisan backup:list`. Kegagalan PATH `sqlite3` paling enak ketahuan sekarang, bukan jam 01:30 saat tidak ada yang melihat.
13. Tambahkan disk luar (S3/rsync) ke `backup.destination.disks`. Arsip yang hanya duduk di disk yang sama dengan aplikasinya tidak menolong saat disknya yang mati.
14. Jangan berikan `pulihkan-backup` ke siapa pun secara default. Pemegangnya bisa mengganti tabel `users` dengan versi arsip — lihat *Restore*. Berikan saat dibutuhkan, cabut setelahnya.
15. Uji restore-nya **sekali** ke instalasi lain sebelum mempercayainya. Backup yang belum pernah dipulihkan belum terbukti backup. Ingat menjalankan `db:seed --class=RolePermissionSeeder` sesudahnya — lihat *Restore*.
16. Setel `VITE_REVERB_HOST` ke domain publik dan `VITE_REVERB_SCHEME=https` **sebelum** langkah 3 — nilainya tertanam di bundel saat build, jadi mengubahnya sesudah `npm run build` tidak berpengaruh sampai di-build ulang. Biarkan `REVERB_HOST` sendiri menunjuk `127.0.0.1`; keduanya beda arah, lihat *Broadcasting*.
17. Jalankan `reverb:start` di bawah supervisor (systemd/supervisord), bukan dari SSH. Ia proses yang harus terus hidup, sama seperti queue worker.
18. Proxy `wss://` di Nginx ke port 8080 dengan header `Upgrade`/`Connection`. Tanpa itu browser di `https://` menolak menyambung, dan penolakannya tidak meninggalkan jejak di log server.

## Verifikasi cepat

### Lokalisasi

```bash
php artisan config:clear
php artisan tinker --execute="
echo config('app.timezone').' | '.app()->getLocale().PHP_EOL;
echo now()->translatedFormat('l, d F Y H:i').PHP_EOL;
echo __('validation.required', ['attribute' => 'nama']).PHP_EOL;
echo __('filament-panels::auth/pages/login.title').PHP_EOL;
"
```

Output yang diharapkan:

```
Asia/Jakarta | id
Sabtu, 08 Agustus 2026 22:40
Kolom nama wajib diisi.
Masuk
```

### Otorisasi & audit log

```bash
php artisan config:clear
php artisan tinker --execute="
\$u = App\Models\User::where('email','admin@admin.com')->first();
echo 'model-role: '.config('permission.models.role').PHP_EOL;
echo 'izin: '.App\Models\Permission::pluck('name')->implode(', ').PHP_EOL;
echo 'role: '.App\Models\Role::pluck('name')->implode(', ').PHP_EOL;
echo 'admin-role: '.\$u->getRoleNames()->implode(', ').PHP_EOL;
echo 'akses-panel: '.var_export(\$u->canAccessPanel(Filament\Facades\Filament::getPanel('admin')), true).PHP_EOL;
"
php artisan schedule:list
php artisan route:list --path=admin
```

Output yang diharapkan:

```
model-role: App\Models\Role
izin: akses-panel-admin, kelola-backup, kelola-pengguna, kelola-role, lihat-log-aktivitas, pulihkan-backup
role: admin, developer, guru, karyawan, murid, super-admin
admin-role: super-admin
akses-panel: true
```

Kalau `model-role` menunjuk `Spatie\Permission\Models\Role`, perubahan role tidak masuk audit log — cek `config/permission.php`.

### Broadcasting

Jalankan `php artisan reverb:start` di terminal lain, lalu tembak handshake WebSocket sungguhan — `curl` biasa ke URL itu memang mengembalikan 500 karena tidak meminta upgrade protokol, dan itu **bukan** tanda servernya rusak:

```bash
php artisan about --only=drivers | grep -i broadcast
source .env && curl -si --max-time 4 \
  -H "Connection: Upgrade" -H "Upgrade: websocket" \
  -H "Sec-WebSocket-Key: x3JJHMbDL1EzLkh9GBhXDw==" -H "Sec-WebSocket-Version: 13" \
  "http://localhost:${REVERB_PORT}/app/${REVERB_APP_KEY}?protocol=7&client=js&version=8.4.0" | head -6
```

Output yang diharapkan:

```
Broadcasting .. reverb
HTTP/1.1 101 Switching Protocols
Upgrade: websocket
Connection: Upgrade
Sec-WebSocket-Accept: HSmrc0sMlYUkAGmm5OPpG2HaGWk=
X-Powered-By: Laravel Reverb
```

Diikuti frame `pusher:connection_established` berisi `socket_id`. Kalau berhenti di `101` tanpa frame itu, `REVERB_APP_KEY` tidak cocok dengan yang dikenal server.
