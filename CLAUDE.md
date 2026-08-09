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
| Debug | barryvdh/laravel-debugbar (dev only) |
| Output tes | laravel/pao (dev only) |

Belum ada modul aplikasi (siswa, guru, kelas, dst). Yang sudah jadi baru fondasinya: panel admin, otorisasi berbasis role/permission, audit log, plus UI kelola pengguna & role.

Tabel yang ada: bawaan Laravel (`users`, `cache`, `jobs`), `activity_log`, lima tabel role/permission, dan `backup_schedules`.

Halaman panel yang sudah ada: `/admin/users`, `/admin/roles`, `/admin/permissions`, `/admin/activities`, `/admin/backups`.

## Perintah

```bash
composer run dev      # serve + queue:listen + pail (log) + vite, sekaligus
composer run test     # config:clear lalu artisan test
composer run setup    # install deps, generate key, migrate, build asset
./vendor/bin/pint     # format kode PHP
php artisan backup:run    # buat arsip backup sekarang
php artisan backup:list   # daftar arsip + status sehat/tidak
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

- **Cek permission, bukan `hasRole('super-admin')`.** Kalau nanti role `guru` perlu masuk panel, cukup beri permission-nya — tidak perlu menyentuh model User lagi.
- **Cabang `default` = `false`.** Panel kedua (guru, siswa, wali murid) **tertutup sampai ditambahkan eksplisit** ke `match`, tidak diam-diam ikut aturan admin.

## Otorisasi (spatie/laravel-permission)

Role dan permission disimpan di tabel `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`. Guard-nya `web`, sama dengan guard panel admin.

Modelnya `App\Models\Role` dan `App\Models\Permission` — subclass model Spatie yang menambah pencatatan audit. **Jangan import dari `Spatie\Permission\Models\`**, penjelasannya di bagian Audit log.

### Nama role & permission ada di enum

Jangan tulis string mentah. Sumber kebenarannya:

| File | Isi |
|---|---|
| `app/Enums/Role.php` | `SuperAdmin = 'super-admin'` |
| `app/Enums/Permission.php` | `AksesPanelAdmin`, `LihatLogAktivitas`, `KelolaPengguna`, `KelolaRole`, `KelolaBackup` |

```php
$user->can(Permission::LihatLogAktivitas->value);
$user->assignRole(Role::SuperAdmin->value);
```

**Menambah permission baru = dua langkah.** Tambah case di enum, lalu jalankan `php artisan db:seed --class=RolePermissionSeeder`. Case enum tanpa baris di tabel `permissions` akan selalu `false`. Tes `test_every_enum_permission_is_seeded` menangkap kalau langkah kedua terlewat.

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

Di luar grup itu: **Log Aktivitas** (`/admin/activities`, izin `lihat-log-aktivitas`, read-only) dan **Backup** (`/admin/backups`, izin `kelola-backup`) — lihat bagian *Audit log* dan *Backup*.

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
php artisan permission:create-role guru
php artisan tinker --execute="App\Models\User::where('email','x@y.com')->first()->assignRole('super-admin');"
```

Setelah mengubah role/permission langsung lewat SQL (bukan lewat model), bersihkan cache:

```bash
php artisan permission:cache-reset
```

Jangan pakai `permission:create-permission` — izin harus lahir dari enum, lihat bagian di atas.

### Kolom `is_admin` sudah tidak ada

Dulu akses panel ditentukan kolom boolean `users.is_admin`. Migrasi `2026_08_09_062000_move_is_admin_to_super_admin_role` memindahkan setiap user `is_admin = true` ke role `super-admin` lalu membuang kolomnya. Kalau menemukan referensi `is_admin` di kode atau dokumen, itu sisa yang terlewat.

## Seeder

`php artisan migrate:fresh --seed` menghasilkan satu user admin siap pakai:

| Field | Nilai |
|---|---|
| Email | `admin@admin.com` |
| Password | `admin` |
| Role | `super-admin` |

Urutannya penting: `RolePermissionSeeder` membuat role dan permission, baru `AdminUserSeeder` memberikan rolenya. Keduanya idempoten (`findOrCreate` / `updateOrCreate`), aman dijalankan berulang tanpa `migrate:fresh`.

**Kredensial dev saja.** Jangan pernah jalankan `AdminUserSeeder` di produksi. `RolePermissionSeeder` aman — isinya cuma role dan permission, tanpa user.

## Audit log (spatie/laravel-activitylog)

Tabel `activity_log`, config di `config/activitylog.php`. Dilihat lewat menu **Log Aktivitas** di panel admin (`/admin/activities`).

### Apa yang tercatat

| Kanal (`log_name`) | Sumber | Event |
|---|---|---|
| `user` | trait `LogsActivity` di `App\Models\User` | `created`, `updated`, `deleted` |
| `auth` | `App\Listeners\LogAuthenticationActivity` | `login`, `logout`, `failed`, `lockout` |
| `otorisasi` | `App\Listeners\LogAuthorizationChanges` | `role-diberikan`, `role-dicabut`, `izin-diberikan`, `izin-dicabut` |
| `otorisasi` | trait `LogsActivity` di `App\Models\Role` dan `App\Models\Permission` | `created`, `updated`, `deleted` |
| `backup` | aksi di `App\Filament\Pages\Backups` | `backup-dijalankan`, `backup-diunduh`, `backup-dihapus` |
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

Isinya: ringkasan status di subheading (jumlah arsip, total ukuran, umur arsip terbaru), tabel arsip, dan tiga aksi.

| Aksi | Perilaku |
|---|---|
| **Ubah Jadwal** | Form frekuensi/hari/jam, disimpan ke `backup_schedules` |
| **Backup Sekarang** | Dispatch `App\Jobs\RunBackup` ke queue, bukan dijalankan di request |
| **Unduh** | Streaming download, dicatat ke `activity_log` |
| **Hapus** | Konfirmasi wajib, arsip terbaru dikunci, dicatat ke `activity_log` |

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
| `BACKUP_ARCHIVE_PASSWORD` | Password AES-256. Hilang = seluruh arsip tidak bisa dibuka |
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

Konsekuensi yang harus disadari: **arsip ini tidak cukup untuk memulihkan aplikasi sendirian.** Restore = `git clone` + `composer install` + `.env` (dari brankas terpisah) + bongkar arsip + import dump.

### Enkripsi

Arsip dienkripsi AES-256 dengan `BACKUP_ARCHIVE_PASSWORD` dari `.env`.

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

### Restore database

```bash
gunzip -c db-dumps/sqlite-sqlite-database.sql.gz | sqlite3 database/database.sqlite
```

Dump-nya berisi `CREATE TABLE IF NOT EXISTS`, jadi restore ke database yang sudah berisi tabel **tidak** menimpa apa pun — malah menghasilkan campuran data lama dan baru. Kosongkan dulu file databasenya kalau ingin benar-benar mengembalikan keadaan.

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

## Tes

`composer run test` — semuanya harus hijau sebelum commit. Database tes SQLite `:memory:` (`phpunit.xml`), jadi tidak menyentuh `database/database.sqlite`.

Kalau yang menjalankan adalah AI agent, outputnya berupa satu baris JSON, bukan tampilan PHPUnit biasa. Itu ulah `laravel/pao` — lihat bagian *Tooling dev*.

| File | Yang dijaga |
|---|---|
| `AdminPanelAccessTest` | Siapa boleh membuka `/admin`, dan panel id asing tidak mewarisi aturan admin |
| `RolePermissionTest` | Guard `web` cocok, `Gate::before` super-admin, izin panel vs izin log terpisah, seeder idempoten |
| `ActivityLogTest` | Login/gagal login tercatat, password tidak bocor, halaman log render, filter pelaku |
| `AccessManagementUiTest` | Form pengguna & role, pengaman anti-terkunci, perubahan otorisasi masuk log |
| `TableActionAuthorizationTest` | Tombol Ubah/Hapus benar-benar menolak, bukan cuma `can*()` yang bilang `false` |
| `BackupConfigurationTest` | Tujuan backup, `.env` tidak ikut terarsip, nama pantauan cocok, jadwal terdaftar |
| `BackupPageTest` | Izin halaman backup, tombol unduh/hapus, arsip terbaru terlindungi, kunci baris palsu ditolak |
| `BackupScheduleTest` | Default mingguan, cron tiap frekuensi, scheduler pakai jadwal user, tabel hilang tidak bikin crash, ambang monitor ikut frekuensi |

`tests/Feature/ExampleTest.php` dan `tests/Unit/ExampleTest.php` masih bawaan Laravel. Yang Feature menjaga halaman `/` (view `welcome`) tetap 200 — kalau `routes/web.php` diganti, tes itu ikut diganti atau dihapus, jangan dibiarkan merah.

Beberapa tes menjaga hal yang **tidak kelihatan dari kode** — jangan dihapus karena terlihat sepele:

- `assertCount(1, ...)` pada tes log: menangkap listener yang terdaftar dua kali (lihat bagian `recordX`).
- `test_every_enum_permission_is_seeded`: menangkap case enum yang lupa di-seed.
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
6. `php artisan db:seed --class=RolePermissionSeeder` — role dan permission harus ada, kalau tidak semua pengecekan akses `false` dan tidak ada yang bisa masuk panel. Seeder ini tidak membuat user, jadi aman di produksi.
7. **Jangan jalankan `db:seed` polos** — itu ikut menjalankan `AdminUserSeeder` yang memakai password `admin`. Buat akun produksi lewat `php artisan make:filament-user`, lalu `assignRole('super-admin')` manual. Tanpa role, akun barunya kena 403.
8. Pastikan cron scheduler aktif, kalau tidak `activitylog:clean` dan seluruh perintah backup tidak pernah jalan — `activity_log` tumbuh tanpa batas dan tidak ada arsip yang pernah dibuat.
9. `sudo apt install sqlite3` — `backup:run` butuh binernya, ekstensi PHP saja tidak cukup.
10. Isi `BACKUP_ARCHIVE_PASSWORD` di `.env` **dan simpan salinannya di luar server**. Kosong = arsip tidak terenkripsi, tanpa peringatan apa pun.
11. Setel SMTP sungguhan. Dengan `MAIL_MAILER=log`, notifikasi backup gagal hanya masuk file log dan tidak dibaca siapa pun.
12. Jalankan `php artisan backup:run` sekali secara manual, lalu `php artisan backup:list`. Kegagalan PATH `sqlite3` paling enak ketahuan sekarang, bukan jam 01:30 saat tidak ada yang melihat.
13. Tambahkan disk luar (S3/rsync) ke `backup.destination.disks`. Arsip yang hanya duduk di disk yang sama dengan aplikasinya tidak menolong saat disknya yang mati.

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
izin: akses-panel-admin, kelola-pengguna, kelola-role, lihat-log-aktivitas
role: super-admin
admin-role: super-admin
akses-panel: true
```

Kalau `model-role` menunjuk `Spatie\Permission\Models\Role`, perubahan role tidak masuk audit log — cek `config/permission.php`.
