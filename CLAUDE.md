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
| Role & permission | spatie/laravel-permission 8 + bezhansalleh/filament-shield 4 |
| Backup | spatie/laravel-backup 10 |
| WebSocket | laravel/reverb 1 + laravel-echo & pusher-js |
| Gambar | intervention/image 4.2 lewat intervention/image-laravel 4.1 |
| Media & unggahan | spatie/laravel-medialibrary 11 (membawa spatie/image 3) |
| Spreadsheet | maatwebsite/excel 3.1 (membawa phpoffice/phpspreadsheet 1.30) |
| Debug | barryvdh/laravel-debugbar (dev only) |
| Output tes | laravel/pao (dev only) |

Belum ada modul aplikasi (siswa, kelas, nilai, dst). Yang sudah jadi baru fondasinya: panel admin, otorisasi berbasis role/permission, audit log, UI kelola pengguna & role, backup terjadwal lengkap dengan password arsip dan restore dari panel, serta empat fondasi yang terpasang tapi belum dipakai siapa pun: broadcasting WebSocket yang belum menyiarkan satu event, pengolah gambar yang belum menyentuh satu berkas, media library yang belum dilampirkan ke satu model, dan spreadsheet yang belum punya satu kelas export maupun import.

Role `guru`, `karyawan`, dan `murid` sudah ada di enum tapi belum punya modul apa pun — dua yang pertama masuk panel dan melihatnya kosong, yang terakhir tidak masuk sama sekali.

Tabel yang ada: bawaan Laravel (`users`, `cache`, `jobs`), `activity_log`, lima tabel role/permission, `backup_schedules`, dan `media`.

Halaman panel yang sudah ada: `/admin/users`, `/admin/roles`, `/admin/activities`, `/admin/backups`. (`/admin/permissions` sudah tidak ada — izin kini dicentang di dalam editor Role milik filament-shield.)

## Perintah

```bash
composer run dev      # serve + queue:listen + reverb + pail (log) + vite, sekaligus
composer run test     # config:clear lalu artisan test
composer run setup    # install deps, generate key, migrate, build asset
./vendor/bin/pint     # format kode PHP
php artisan reverb:start  # server WebSocket saja, tanpa sisa proses dev
php artisan storage:link  # symlink public/storage, wajib sekali per instalasi
php artisan shield:generate --all --panel=admin   # izin + policy dari isi panel
php artisan shield:super-admin --user=1           # jadikan user tertentu super-admin
php artisan media-library:clean --dry-run   # daftar berkas media yatim
php artisan make:export SiswaExport --model=Siswa   # kelas export spreadsheet
php artisan make:import SiswaImport --model=Siswa   # kelas import spreadsheet
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

Resource read-only (Log Aktivitas) sengaja **tidak punya** `Pages/Create*`, `Pages/Edit*`, dan `Schemas/*Form.php` — filenya dihapus, bukan sekadar disembunyikan.

**`app/Filament/Resources/Roles/` tidak mengikuti pola ini** dan memang tidak seharusnya. Isinya hasil `php artisan shield:publish` — form, tabel, dan halaman digabung dalam satu `RoleResource.php` sesuai bentuk stub Shield. Menyusun ulang ke pola repo akan hilang begitu `shield:publish` dijalankan lagi. Yang ditambahkan di sana cuma penguncian super-admin dan perbaikan hook halaman; lihat *Berkas hasil `shield:publish` bukan milikmu*.

Navigasi dikelompokkan lewat `$navigationGroup`. Yang ada sekarang: grup **Manajemen Akses** (`$navigationSort` 10/20) dan Log Aktivitas tanpa grup (`$navigationSort` 90).

**Asset panel di-gitignore.** `public/css/filament`, `public/js/filament`, `public/fonts/filament` adalah hasil generate, bukan source. Setelah `composer update` atau saat deploy **wajib** jalankan:

```bash
php artisan filament:assets
```

Kalau dilewat, panel tampil tanpa CSS.

### Akses panel

`App\Models\User` implement `Filament\Models\Contracts\FilamentUser`. Yang menentukan akses adalah **permission** `Access:AdminPanel`, bukan role. Tanpa itu → HTTP 403.

```php
public function canAccessPanel(Panel $panel): bool
{
    return match ($panel->getId()) {
        'admin' => $this->can('Access:AdminPanel'),
        default => false,
    };
}
```

`Access:AdminPanel` adalah izin **kustom** — dideklarasikan di `custom_permissions` pada `config/filament-shield.php`, bukan digenerate dari sebuah resource, karena tidak ada model di balik "panel itu sendiri".

Tiga hal yang sengaja begini, jangan "disederhanakan":

- **Cek permission, bukan `hasRole('super-admin')`.** Ini yang membuat role `guru` dan `karyawan` bisa masuk panel hanya dengan diberi `Access:AdminPanel`, tanpa menyentuh model User sama sekali.
- **Role `panel_user` milik Shield dimatikan.** Kalau tidak, ia jadi jalur kedua ke dalam panel yang tidak muncul di pengecekan izin mana pun — lihat *`panel_user` dimatikan*.
- **Cabang `default` = `false`.** Panel kedua (guru, siswa, wali murid) **tertutup sampai ditambahkan eksplisit** ke `match`, tidak diam-diam ikut aturan admin.

## Otorisasi (spatie/laravel-permission + filament-shield)

Role dan permission disimpan di tabel `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`. Guard-nya `web`, sama dengan guard panel admin.

Modelnya `App\Models\Role` dan `App\Models\Permission` — subclass model Spatie yang menambah pencatatan audit. **Jangan import dari `Spatie\Permission\Models\`**, penjelasannya di bagian Audit log. Shield ikut memakai keduanya, karena ia meminta modelnya lewat `PermissionRegistrar` yang membaca `config/permission.php`.

### Nama izin digenerate, bukan ditulis

`bezhansalleh/filament-shield` menurunkan nama izin dari isi panel: tiap resource, page, dan widget. Dulu nama izin adalah case di `App\Enums\Permission` — enum itu **sudah dihapus**, karena daftar yang ditulis tangan pasti melenceng begitu ada resource baru.

```bash
php artisan shield:generate --all --panel=admin   # bikin/perbarui izin + policy
php artisan shield:generate --resource=SiswaResource --option=permissions
php artisan shield:super-admin --user=1
```

Bentuk namanya `Aksi:Subjek` — pascal case dengan pemisah `:`, disetel di `permissions` pada `config/filament-shield.php`. Lima belas izin yang ada sekarang:

| Sumber | Izin |
|---|---|
| `UserResource` | `ViewAny:User`, `View:User`, `Create:User`, `Update:User`, `Delete:User` |
| `RoleResource` | `ViewAny:Role`, `View:Role`, `Create:Role`, `Update:Role`, `Delete:Role` |
| `ActivityResource` | `ViewAny:Activity`, `View:Activity` |
| `Backups` (page) | `View:Backups` |
| kustom | `Access:AdminPanel`, `Restore:Backup` |

**Dua izin kustom, dan keduanya bukan kelalaian.** Tidak ada model di balik "panel itu sendiri" maupun di balik "memulihkan arsip", jadi keduanya dideklarasikan di `custom_permissions` pada config. Agar bisa dicentang lewat UI, `shield_resource.tabs.custom_permissions` harus `true` — bawaan paketnya `false`, dan dengan itu `Restore:Backup` tidak akan pernah bisa diberikan lewat panel.

**Daftar method dipangkas per resource.** Bawaan Shield membuat 13 izin per resource, termasuk `Restore`, `ForceDelete`, `Replicate`, dan `Reorder` yang tidak punya tombol di panel ini. `resources.manage` di config memangkasnya — tapi hanya kalau **`policies.merge` `false`**. Dengan `true` (bawaan paket) daftar itu di-`array_merge` dengan daftar default, jadi ia menambah alih-alih mengganti. Tidak ada error; satu-satunya gejalanya jumlah izin yang tidak turun.

**Menambah modul baru = jalankan generatornya**, lalu tentukan role mana yang mendapat izin barunya di `Role::permissions()`. Izin yang tidak pernah digenerate selalu `false`.

### Policy hasil generate, dan siapa yang menang

`shield:generate` juga menulis policy ke `app/Policies`. Isinya tipis — tiap method cuma menerjemahkan nama izin:

```php
public function delete(AuthUser $authUser, Role $role): bool
{
    return $authUser->can('Delete:Role');
}
```

Yang ada sekarang: `UserPolicy`, `RolePolicy`, `ActivityPolicy`. Ketiganya **kode, bukan data** — masuk git, dan bukan urusan seeder (karena itu seedernya memakai `--option=permissions`).

**Sekarang ada dua lapis otorisasi, dan yang di resource menang.** Filament menanyakan `can*()` statis di resource lebih dulu; kalau resource tidak mengoverride, barulah policy dipakai. Pembagiannya sekarang:

| Resource | Lewat policy | Dioverride di resource |
|---|---|---|
| `RoleResource` | `canAccess` (`ViewAny:Role`) | `canEdit`, `canDelete`, `canDeleteAny` — penguncian super-admin |
| `UserResource` | *(tidak ada)* | `canAccess`, `canDelete`, `canDeleteAny` — anti hapus diri & super-admin terakhir |
| `ActivityResource` | *(tidak ada)* | semuanya — log audit read-only |

**Ini jebakan yang menunggu.** `RolePolicy::delete()` mengizinkan siapa pun yang punya `Delete:Role` menghapus role apa pun — **termasuk `super-admin`**. Satu-satunya yang menahannya adalah override `canDelete()` di `RoleResource`. Menghapus override itu karena "kan sudah ada policy-nya" akan mengembalikan izin menghapus role yang memegang gate. Sama untuk `UserPolicy::delete()` versus larangan menghapus akun sendiri. Dikunci `test_the_super_admin_role_is_locked` dan `TableActionAuthorizationTest`.

**`ActivityPolicy` tidak ditemukan otomatis.** Laravel mencocokkan policy lewat konvensi nama: `App\Models\Foo` → `App\Policies\FooPolicy`. Model Activity ada di paket activitylog, bukan di `App\Models`, jadi tidak ada yang menemukannya. Karena itu `AppServiceProvider::boot()` memanggil:

```php
FilamentShield::enforcePolicies();
```

`shield:generate` mencetak `(requires registration)` di sebelah policy yang butuh ini. Kalau nanti ada model paket lain yang dijadikan resource, gejalanya sama: policy-nya ada, isinya benar, dan tidak pernah dipanggil.

### Role yang ada dan izin bawaannya

`Role::permissions()` di enum adalah sumber kebenarannya, dan itulah yang dibaca `RolePermissionSeeder`:

| Role | Izin bawaan | Catatan |
|---|---|---|
| `developer` | **semua** (dibaca dari tabel `permissions`) | Eksplisit, bukan lewat gate — lihat di bawah |
| `super-admin` | *tidak ada* | Lolos semua lewat gate milik shield |
| `admin` | `Access:AdminPanel`, kelima izin `:User`, `ViewAny:Activity`, `View:Activity` | Sengaja tanpa izin `:Role`, `View:Backups`, dan `Restore:Backup` |
| `guru` | `Access:AdminPanel` | Belum ada modul untuknya |
| `karyawan` | `Access:AdminPanel` | Belum ada modul untuknya |
| `murid` | *tidak ada* | Tidak masuk panel admin |

**Nama izinnya string, dan itu risiko baru.** Sejak enum `Permission` dihapus, isi `Role::permissions()` tidak lagi diperiksa tipe. Salah ketik, atau izin yang berubah nama karena `shield:generate` berikutnya, tidak menghasilkan error — ia cuma memberi izin yang tidak cocok dengan pengecekan mana pun. `test_every_baseline_permission_exists` menutup celah itu dengan mencocokkan tiap string ke baris yang benar-benar digenerate.

**`developer` bukan super-admin kedua.** Dia memegang tiap izin sebagai baris nyata di `role_has_permissions`, tidak lewat gate. Bedanya baru terasa saat modul bertambah: policy modul baru **tetap berlaku** untuk developer. Daftarnya dibaca dari tabel (`Role::allGeneratedPermissions()`), bukan dari literal, supaya izin hasil `shield:generate` berikutnya ikut tanpa menyunting enum. Satu-satunya role yang melewati semuanya tetap `super-admin`. Dikunci `test_developer_holds_every_permission_without_bypassing_the_gate`.

**`admin` sengaja tidak dapat izin `:Role`.** Siapa pun yang memegangnya bisa membuat role berisi izin apa pun lalu memberikannya ke dirinya sendiri — praktis setara developer. `View:Backups` juga ditahan karena berjarak satu klik dari mengunduh seluruh isi database, dan `Restore:Backup` karena ia mengganti tabel `users` dengan versi arsip — lihat *Restore*.

**Seeder menambah, tidak pernah sync.** `givePermissionTo` dipakai, bukan `syncPermissions`. Ini disengaja: langkah 6 checklist deploy menjalankan seeder ini di **setiap** rilis, dan sync akan diam-diam membatalkan setiap perubahan izin yang dibuat lewat panel sejak deploy terakhir. Konsekuensinya, menghapus izin dari `permissions()` **tidak** mencabutnya di instalasi yang sudah jalan — itu harus dilakukan manual lewat panel. Dikunci `test_reseeding_does_not_revoke_a_permission_granted_by_hand`.

Role selain `super-admin` **tidak dikunci** dari edit/hapus di panel. Namanya tidak dirujuk dari kode saat runtime, jadi mengubahnya tidak memecahkan apa pun — tapi user yang sudah memegang role itu ikut kehilangannya, dan seeder hanya akan membuat ulang role kosong tanpa mengembalikan penggunanya.

### super-admin

Role `super-admin` **tidak memegang permission apa pun secara eksplisit**. Dia lolos semua pengecekan lewat gate. `Gate::before` di `AppServiceProvider` **sudah dihapus** — sekarang shield yang memasangnya, dari `FilamentShieldServiceProvider`:

```php
Gate::before(fn ($user, $ability) => $user->hasRole(Utils::getSuperAdminName()) ? true : null);
```

Wajib `null`, bukan `false`, untuk user non-super-admin. `false` akan menghentikan gate lebih awal dan menolak permission yang sebenarnya dimiliki user itu. Sifat itu terbawa dari implementasi lama dan tetap berlaku — `intercept_gate => 'after'` di config justru mengembalikan `false` dan punya bug itu secara bawaan.

Tiga baris config yang menentukan semuanya, dan **dua di antaranya berbeda dari bawaan paket**:

| Key | Repo ini | Bawaan Shield | Kalau dibiarkan bawaan |
|---|---|---|---|
| `super_admin.name` | `super-admin` | `super_admin` | Role kedua yang kosong; pemegang role lama kehilangan bypass |
| `super_admin.define_via_gate` | `true` | `false` | Shield **tidak memasang gate sama sekali** dan malah memberi super-admin tiap izin sebagai baris — persis peran `developer`, jadi kedua role itu kehilangan bedanya |
| `super_admin.intercept_gate` | `before` | `before` | — |

Yang kedua paling halus: dengan `false` semuanya tetap tampak jalan, sampai ada modul baru yang izinnya belum digenerate ulang dan super-admin diam-diam tidak bisa membukanya. Dikunci `test_shield_grants_super_admin_through_the_gate`.

Nama role juga hidup di dua tempat — `App\Enums\Role::SuperAdmin` dan config shield. Seeder dan migrasi `is_admin` membaca enum, gate membaca config. Kalau melenceng, role yang di-seed dan role yang punya bypass jadi dua baris berbeda. Dikunci `test_the_super_admin_name_matches_shield`.

Konsekuensinya: jangan pernah memberi `super-admin` ke user biasa. Role itu melewati **semua** policy, bukan cuma permission yang terdaftar.

### `panel_user` dimatikan

Shield membuat role `panel_user` secara bawaan untuk "pengguna yang boleh masuk panel tanpa izin khusus". Di repo ini `panel_user.enabled` disetel `false`.

Alasannya sama dengan alasan `canAccessPanel()` mengecek izin dan bukan role: role itu akan jadi **jalur kedua ke dalam panel yang tidak muncul di pengecekan izin mana pun**. Seseorang memberikannya karena namanya terdengar tidak berbahaya, lalu tidak ada `can()` di kode yang menjelaskan kenapa akun itu bisa masuk. Dikunci `test_the_shield_panel_user_role_is_disabled`.

### Berkas hasil `shield:publish` bukan milikmu

`app/Filament/Resources/Roles/` adalah salinan resource milik Shield, dihasilkan `php artisan shield:publish`. Perintah itu **menimpa tanpa bertanya**. Dua hal yang ditambahkan di sana akan hilang kalau ia dijalankan ulang:

**1. Hook halaman salah nama di stub aslinya.** `ListRoles`, `EditRole`, dan `ViewRole` bawaan Shield mendefinisikan `getActions()` — hook Filament versi lama. Filament 5 memanggil `getHeaderActions()`. Akibatnya tombol **Buat**, **Hapus**, dan **Ubah** di header halaman **tidak dirender sama sekali**: bukan error, bukan 403, cuma halaman tanpa aksi. Ketiganya sudah diganti namanya di repo ini, dan `test_the_role_pages_still_render_their_header_actions` menangkap kalau ia kembali.

**2. Penguncian super-admin dan bulk delete.** Shield tidak membawa keduanya. `canEdit()`, `canDelete()`, `canDeleteAny()`, `isSuperAdmin()`, `->disabled()` pada tiap action, dan `toolbarActions([])` semuanya tambahan repo ini. Stub aslinya memasang `DeleteBulkAction` yang akan melewati penguncian itu — lihat *Action Filament TIDAK ikut `canEdit()`/`canDelete()`*.

Kalau `shield:publish` memang perlu dijalankan lagi (misalnya setelah upgrade paket), jalankan `composer run test` sesudahnya. Tesnya menangkap kedua regresi di atas.

### UI di panel admin

Grup navigasi **Manajemen Akses**:

| Menu | URL | Izin | Sifat |
|---|---|---|---|
| Pengguna | `/admin/users` | `ViewAny:User` | CRUD + centang role |
| Role | `/admin/roles` | `ViewAny:Role` (lewat `RolePolicy`) | Resource milik Shield, izin dicentang per tab |

Menu **Izin** sudah tidak ada. Shield tidak punya resource untuk permission — daftarnya muncul sebagai checkbox bertab di dalam editor Role, dikelompokkan per resource/page/widget/kustom. Efeknya sama dengan resource read-only yang dulu ada, dengan cara yang lebih baik: izin tidak bisa dibuat maupun dihapus lewat UI sama sekali. Dijaga `test_no_panel_resource_exposes_permissions_directly`.

URL-nya sengaja tetap `/admin/roles`. Bawaan Shield `/admin/shield/roles`; diubah lewat `shield_resource.slug` supaya tautan lama tidak patah.

Di luar grup itu: **Log Aktivitas** (`/admin/activities`, izin `ViewAny:Activity`, read-only) dan **Backup** (`/admin/backups`, izin `View:Backups`, plus `Restore:Backup` khusus untuk tombol Pulihkan) — lihat bagian *Audit log* dan *Backup*.

Pengaman yang sengaja dipasang — jangan dilonggarkan tanpa alasan:

- **Nama izin bukan data bebas.** Tiap nama digenerate Shield dari isi panel dan dirujuk dari `canAccess()` atau `can()`. Izin yang lahir di luar generator tidak cocok dengan pengecekan mana pun, dan menghapus izin diam-diam mencabut akses.
- **Role `super-admin` terkunci** dari edit dan hapus. Namanya dirujuk dari `App\Enums\Role`, dari config shield, dari gate yang dipasang shield, dan dari sebuah migrasi. Daftar izinnya juga tidak berarti karena gate memberi semuanya. **Pengaman ini tidak dibawa Shield** — ia diporting ke `RoleResource` hasil `shield:publish`, dan `shield:publish` yang dijalankan ulang akan menimpanya.
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

- **Izin tidak boleh lahir dari CLI.** Jangan pakai `permission:create-permission` — nama izin digenerate `shield:generate` dari isi panel dan dirujuk dari `canAccess()` atau `can()`. Izin yang dibuat manual tidak cocok dengan pengecekan mana pun. Kalau butuh izin yang tidak punya resource di baliknya, tempatnya `custom_permissions` di `config/filament-shield.php`, lalu generate ulang.
- **Role tambahan boleh.** Enam role di enum adalah bawaan, bukan daftar tertutup. Role tambahan seperti `wali-kelas` sah dibuat lewat CLI atau panel. Yang perlu diingat: role di luar enum tidak dibuat ulang oleh seeder, jadi kalau terhapus ia hilang beserta penugasannya.

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

**`RolePermissionSeeder` memanggil `shield:generate`.** Izin tidak lagi ditulis di seeder — ia memanggil generator Shield lewat `Artisan::call`, lalu membagikannya ke role sesuai `Role::permissions()`. Ini yang membuat `migrate:fresh --seed` tetap cukup dengan satu perintah.

Argumen `--option=permissions` bukan hiasan: tanpa itu generator juga **menulis ulang berkas policy** ke `app/Policies`. Policy adalah kode, tempatnya di git, dan seeder yang menulis berkas PHP akan gagal di deploy yang filesystem-nya read-only. Yang dijalankan seeder hanya bagian datanya.

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

Shield ikut memakai model ini: ia meminta class-nya lewat `PermissionRegistrar`, yang membaca config yang sama. Jadi role yang dibuat lewat panel tetap tercatat.

#### Editor Role milik Shield mencatat set penuh, bukan selisih

`EditRole::afterSave()` bawaan Shield memanggil `syncPermissions()`. Sync artinya **cabut semua, lalu berikan set yang baru** — termasuk izin yang sebenarnya tidak berubah.

Satu kali simpan karena itu menghasilkan **dua** baris di `activity_log`:

| Event | Isi `izin` |
|---|---|
| `izin-dicabut` | seluruh izin **lama** |
| `izin-diberikan` | seluruh izin **baru** |

Menambahkan satu centang ke role `guru` menghasilkan `izin-dicabut: ["Access:AdminPanel"]` disusul `izin-diberikan: ["Access:AdminPanel","ViewAny:Activity"]`. Membaca baris pertama sendirian akan terbaca seperti pencabutan akses yang tidak pernah terjadi — **selalu baca berpasangan**.

Ini juga berarti jejak auditnya bergantung pada kode vendor. Kalau versi Shield berikutnya menulis pivot langsung ketimbang lewat model, panelnya tetap jalan dan perubahan otorisasi diam-diam berhenti tercatat. Dikunci `test_editing_a_role_through_shield_reaches_the_audit_log`.

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

Akses dibatasi lewat `canAccess()` yang mengecek permission `ViewAny:Activity`. Terpisah dari `Access:AdminPanel`, jadi user bisa dimasukkan ke panel tanpa sekalian diberi jejak audit.

### Pembersihan

`activitylog:clean --force` terjadwal harian jam 02:00 di `routes/console.php`, dengan `onOneServer()` supaya tidak jalan dobel kalau nanti ada lebih dari satu server. Batas umurnya `clean_after_days` (default 365) di `config/activitylog.php`. Scheduler perlu cron aktif di server (`php artisan schedule:run` tiap menit).

`--force` khusus untuk produksi. `CleanActivitylogCommand` memakai `ConfirmableTrait`, yang **hanya** meminta konfirmasi kalau `APP_ENV=production`. Di cron tidak ada yang menjawab, jadi tanpa `--force` perintahnya berhenti diam-diam (`return 1`, tanpa error mencolok) dan `activity_log` tumbuh terus. Di lokal flag ini tidak berpengaruh — jangan dilepas hanya karena "di dev jalan-jalan saja".

### Mematikan sementara

`ACTIVITYLOG_ENABLED=false` di `.env`. Berguna saat impor data massal supaya tidak membanjiri tabel.

## Backup (spatie/laravel-backup)

### UI di panel — `/admin/backups`

Menu **Backup** (`$navigationSort` 80, tanpa grup, bertetangga dengan Log Aktivitas). Izinnya `View:Backups`, **terpisah dari `Access:AdminPanel`** — halaman ini memperlihatkan kapan database terakhir ditangkap dan berjarak satu klik dari menyerahkan seluruh isinya, jadi tidak ikut terbawa hanya karena seseorang boleh masuk panel.

Satu aksi di halaman ini dijaga izin **kedua**: tombol **Pulihkan** butuh `Restore:Backup`. Pemegang `View:Backups` melihat halamannya tanpa tombol itu. Alasannya di bagian *Restore*.

Isinya: ringkasan status di subheading (jumlah arsip, total ukuran, umur arsip terbaru), tabel arsip, dan tiga aksi.

| Aksi | Perilaku |
|---|---|
| **Ubah Jadwal** | Form frekuensi/hari/jam, disimpan ke `backup_schedules` |
| **Password Arsip** | Password enkripsi AES-256, disimpan terenkripsi di `backup_schedules.archive_password` |
| **Backup Sekarang** | Dispatch `App\Jobs\RunBackup` ke queue, bukan dijalankan di request |
| **Unduh** | Streaming download, dicatat ke `activity_log` |
| **Hapus** | Konfirmasi wajib, arsip terbaru dikunci, dicatat ke `activity_log` |
| **Pulihkan** | Izin terpisah `Restore:Backup`, konfirmasi ketik nama berkas, lihat *Restore* |

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

**Password tidak boleh masuk `activity_log`.** `LogsActivity` di `BackupSchedule` memakai `logFillable()`, yang akan menyalin nilainya mentah-mentah ke tabel yang bisa dibaca siapa pun pemegang `ViewAny:Activity` — kelompok yang jauh lebih luas daripada `View:Backups`. Karena itu ada `->logExcept(['archive_password'])`, dan perubahannya dicatat manual di `Backups::savePassword()` sebagai event `password-arsip-diubah` **tanpa nilainya**. Dikunci `test_the_password_never_reaches_the_activity_log`.

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

Aksi baris **Pulihkan** di `/admin/backups`, dijalankan `App\Support\Backup\RestoreArchive`. Izinnya `Restore:Backup`, **bukan** `View:Backups`: restore mengganti tabel `users` dengan versi arsip, jadi pemegangnya bisa menghidupkan akun lama yang passwordnya ia tahu atau membatalkan pencabutan role. Itu kekuasaan lain dari "boleh mengunduh arsip".

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

**Yang belum ditangani: `RestoreArchive` tidak menjalankan `RolePermissionSeeder`.** Migrasi mengembalikan struktur, bukan baris. Memulihkan arsip yang dibuat sebelum sebuah resource ada meninggalkan izin resource itu tanpa baris di tabel `permissions` — dan izin yang tidak ada di tabel selalu `false`, jadi menunya hilang diam-diam untuk semua orang kecuali `super-admin` yang lolos lewat gate. Sudah terjadi saat memulihkan arsip 14:50 di repo ini. Sampai `settleApplication()` ikut memanggil seeder, **jalankan manual setelah restore lewat panel**:

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

**Restore memundurkan skema *dan* isi seeder** — dan keduanya butuh langkah berbeda. `migrate --force` mengembalikan strukturnya (arsip membawa tabel `migrations` sendiri, jadi hanya migrasi yang lebih baru yang jalan). Tapi migrasi tidak menambah **baris**: role dan permission yang lahir setelah arsip dibuat tetap hilang sampai `RolePermissionSeeder` dijalankan. Terjadi persis begitu saat memulihkan arsip 14:50 di repo ini — `Restore:Backup` hilang, dan tombol Pulihkan mati untuk semua orang kecuali `super-admin` yang lolos lewat `Gate::before`.

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

Jumlah izin harus sama dengan yang dihasilkan `shield:generate --all` untuk panel ini. Kalau kurang, langkah seeder terlewat.

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
Broadcast::channel('backup', fn (User $user) => $user->can('View:Backups'));
```

Nama izinnya salin dari keluaran `shield:generate` — lihat *Nama izin digenerate, bukan ditulis*.

**`super-admin` otomatis lolos** lewat gate yang dipasang shield, karena `can()` melewati gate yang sama. Tidak perlu dikecualikan manual.

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

## Gambar (intervention/image)

Pengolahan gambar sisi server: resize, crop, watermark, konversi format. Belum dipakai modul mana pun — terpasang sebagai fondasi, sama seperti Reverb.

Yang dipasang adalah `intervention/image-laravel` (bridge), bukan `intervention/image` telanjang. Bridge menarik core-nya sebagai dependensi lalu menambahkan tiga hal: facade `Image`, config `config/intervention-image.php`, dan macro `response()->image($img)`. Memakai core langsung tetap sah — bridge cuma menyediakan jalan pintas, bukan pembungkus yang menyembunyikan API.

| Paket | Versi |
|---|---|
| `intervention/image` | 4.2 |
| `intervention/image-laravel` | 4.1 |

### API 4.2 berbeda dari hampir semua contoh yang beredar

Jebakan pertama dan yang paling banyak membuang waktu. Versi 4.2 mengganti nama method inti, **tanpa meninggalkan alias**, sementara situs dokumentasi dan tiap tutorial yang beredar masih memakai nama lama. Gejalanya `Call to undefined method`, bukan deprecation warning.

| Contoh yang beredar | Yang benar di 4.2 |
|---|---|
| `Image::read($path)` | `Image::decodePath($path)` |
| `Image::read($binary)` | `Image::decodeBinary($binary)` — atau `decode()` yang menebak sendiri |
| `Image::create(400, 300)` | `Image::createImage(400, 300)` |
| `$img->toJpeg(80)` | `$img->encodeUsingFormat(Format::JPEG, quality: 80)` |
| `$img->toWebp(80)` | `$img->encodeUsingFormat(Format::WEBP, quality: 80)` |
| `ImageManager::gd()` / `::imagick()` | `new ImageManager(new Gd\Driver)` atau `ImageManager::usingDriver(...)` |

`decode()` menerima path, binary, base64, data URI, stream, atau `SplFileInfo` dan memilih decoder sendiri. Varian eksplisit (`decodePath`, `decodeBinary`, …) lebih baik dipakai kalau sumbernya sudah pasti — input yang tidak sesuai dugaan gagal lebih cepat dan pesannya lebih jelas.

Untuk kontrol penuh, lewatkan objek encoder:

```php
use Intervention\Image\Encoders\JpegEncoder;

$img->encode(new JpegEncoder(quality: 60, progressive: true, strip: true));
```

`$img->save($path)` masih ada dan menyimpulkan format dari ekstensi berkas, dengan fallback ke media type sumber kalau ekstensinya tidak dikenali — jadi `simpan.dat` tidak gagal, ia diam-diam tersimpan dalam format aslinya.

**`save()` tanpa argumen menimpa berkas sumbernya.** Ia jatuh ke `origin()->filePath()`, yaitu path yang tadi dibaca `decodePath()`. Untuk thumbnail, itu berarti foto asli tergantikan versi kecilnya dan tidak ada jalan pulang. Selalu berikan path tujuan secara eksplisit kecuali menimpa memang yang dimaksud.

### Driver — satu env untuk dua paket

`IMAGE_DRIVER` di `.env`, isinya **nama pendek**:

```
IMAGE_DRIVER=gd
```

Bentuk pendek itu **bukan** yang diminta intervention. Paketnya mengharapkan nama class, dan config bawaannya memang `env('IMAGE_DRIVER', Gd\Driver::class)`. Yang memaksa berubah adalah `spatie/laravel-medialibrary`: ia membaca env **yang sama persis** lewat `env('IMAGE_DRIVER', 'gd')` dan meneruskannya mentah-mentah ke `Spatie\Image\Image::useImageDriver()`, yang cuma menerima `gd`/`imagick`/`vips`.

Satu env, dua format nilai yang saling menolak. Nama class membuat medialibrary melempar `InvalidImageDriver` di **tiap konversi**, dan itu terjadi saat unggah gambar pertama — bukan saat boot, jadi lolos sampai jauh.

Jalan keluarnya: env menyimpan bentuk yang dipakai medialibrary apa adanya, dan `config/intervention-image.php` yang memetakannya ke class.

```php
'driver' => match (env('IMAGE_DRIVER', 'gd')) {
    'imagick' => ImagickDriver::class,
    default => GdDriver::class,
},
```

**Jangan kembalikan ke `env(...)` langsung.** Kedua paket akan menunjuk driver berbeda tanpa error apa pun — dan yang ikut berbeda adalah dukungan format serta perlakuan EXIF. Dikunci `ImageDriverConfigurationTest`, lihat *Dua tumpukan gambar* di bagian Media.

`default` di `match` itu memang memaafkan salah ketik: `IMAGE_DRIVER=imagik` menghasilkan GD untuk intervention sementara medialibrary meneruskan `imagik` dan melempar saat konversi. Sengaja tidak dibuat melempar di config — file config dimuat pada **setiap** invokasi artisan, jadi exception di sana mengunci perintah yang justru dibutuhkan untuk memperbaikinya. Yang menangkapnya tes, bukan boot.

GD dipilih sebagai default bukan karena lebih baik, tapi karena lebih pasti ada. Imagick lebih kaya format tapi jauh lebih sering absen di shared hosting.

**Komentar bawaan di `config/intervention-image.php` menyesatkan** dan sudah diganti di repo ini. Ia mendaftar tiga "Included options", padahal paketnya cuma membawa dua: `src/Drivers/` isinya hanya `Gd/` dan `Imagick/`. `Vips\Driver` ada di paket terpisah `intervention/image-driver-vips` yang belum terpasang.

Dukungan format di mesin ini (dua-duanya terpasang):

| Format | GD | Imagick 7.1.1 |
|---|---|---|
| JPEG, PNG, GIF, WebP | ya | ya |
| TIFF, PDF, SVG | tidak | ya |
| AVIF | tidak | tidak |
| **HEIC** | **tidak** | **tidak** |

**HEIC itu masalah nyata, bukan catatan kaki.** iPhone memotret HEIC secara default, jadi unggahan foto siswa dari iPhone akan gagal didekode di kedua driver. Yang tersedia cuma dua jalan: tolak di validasi dengan pesan yang jelas (bukan error 500), atau pasang `libheif` di server dan bangun ulang Imagick. Jangan biarkan kegagalannya muncul sebagai exception saat simpan.

### `strip` sengaja `true`, beda dari bawaan paket

`config/intervention-image.php` di repo ini menyetel `'strip' => true`; bawaan paket `false`. Ini keputusan privasi, bukan preferensi.

Foto dari HP membawa EXIF berisi **koordinat GPS**, merek/model perangkat, dan waktu pengambilan. Dalam konteks sekolah itu berarti lokasi rumah anak ikut tersimpan di berkas yang disajikan lewat URL publik.

Yang membuat ini halus: **encoder GD tidak pernah membaca opsi `strip`.** GD memang tidak menulis metadata apa pun, jadi dengan driver bawaan nilainya tidak berpengaruh sama sekali. Encoder Imagick membacanya. Artinya dengan `false`, mengganti `IMAGE_DRIVER` ke Imagick — demi TIFF atau PDF, alasan yang sama sekali tidak berhubungan — diam-diam mulai menyimpan koordinat GPS ke tiap foto. Tidak ada error, tidak ada peringatan, dan tidak ada tes yang gagal.

`true` membuat perilakunya sama di kedua driver, jadi pilihan driver berhenti menjadi keputusan privasi. Kalau suatu saat memang butuh EXIF tersimpan (misalnya modul dokumentasi foto yang perlu tanggal pengambilan), matikan **per pemanggilan** lewat `new JpegEncoder(strip: false)`, jangan balikkan defaultnya.

`autoOrientation` tetap `true` dan tidak bentrok: rotasi dibaca dari EXIF saat **decode**, pembuangan metadata terjadi saat **encode**. Foto tetap tegak, koordinatnya tetap hilang.

### `composer install` tidak menjamin drivernya ada

`intervention/image` hanya mensyaratkan `php`, `ext-mbstring`, dan `intervention/gif` — **`ext-gd` dan `ext-imagick` tidak ada di `require`**. Konsekuensinya `composer install` sukses sempurna di server yang tidak punya keduanya, dan kegagalannya baru muncul saat gambar pertama diproses, di produksi, kepada pengguna sungguhan.

```bash
sudo apt install php8.4-gd    # atau php8.4-imagick
php -m | grep -E '^(gd|imagick|exif)$'
```

`ext-exif` disarankan paketnya sendiri dan dibutuhkan `autoOrientation` untuk membaca orientasi. Tanpa itu foto potret dari HP tersimpan miring.

**Sejak `maatwebsite/excel` masuk, `ext-gd` sebenarnya sudah dipaksa composer** — bukan oleh intervention, melainkan oleh `phpoffice/phpspreadsheet` yang mencantumkannya di `require`. Jadi `composer install` sekarang **gagal keras** di server tanpa GD, dan celah di atas tertutup secara kebetulan.

Jangan bersandar padanya. Yang menutup celah itu paket yang sama sekali tidak berhubungan dengan pengolahan gambar; mencopot laravel-excel suatu saat akan membukanya kembali tanpa ada yang menghubungkan kedua hal itu. **`ext-imagick` dan `ext-exif` tetap tidak dipaksa siapa pun** — keduanya masih murni tanggung jawab checklist deploy.

### Hubungannya dengan Filament

`FileUpload` di Filament punya `->imageEditor()`, dan itu **bukan pengganti** paket ini: editor Filament berjalan di **browser** (crop/rotate sebelum unggah, bisa dilewati siapa pun yang mengunggah lewat cara lain), sedangkan Intervention berjalan di **server** setelah berkas mendarat. Thumbnail, normalisasi ukuran, dan pembuangan EXIF hanya bisa dijamin di sisi server.

Pola yang wajar nanti: `->imageEditor()` untuk kenyamanan, ditambah pemrosesan server-side di model event atau job untuk yang benar-benar dijamin.

Kalau perlu menyajikan gambar hasil olahan langsung sebagai response, bridge menyediakan macro:

```php
return response()->image($img, Format::WEBP, quality: 80);
```

Untuk gambar yang mahal diolah, dahulukan menyimpan hasilnya ke disk ketimbang mengolah ulang tiap request.

## Media (spatie/laravel-medialibrary)

Melampirkan berkas ke model Eloquent: unggahan, koleksi, dan konversi (thumbnail, versi WebP). Versi 11.23. Belum ada model yang memakainya — `User` **tidak** dipasangi `InteractsWithMedia`, karena itu keputusan modul, bukan bagian instalasi.

| Berkas | Isi |
|---|---|
| `config/media-library.php` | Disk, ukuran maksimum, queue, optimizer, driver gambar |
| `database/migrations/*_create_media_table.php` | Tabel `media`, polimorfik ke model mana pun |

Tabel `media` sudah dimigrasi. Tambahkan ke daftar tabel di bagian *Stack* kalau nanti ada tabel lain menyusul.

Memasangnya ke model nanti:

```php
class Siswa extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Crop, 120, 120);
    }
}

$siswa->addMedia($request->file('foto'))->toMediaCollection('foto');
```

### Perintah bawaan

```bash
php artisan media-library:regenerate            # bangun ulang konversi (semua model)
php artisan media-library:regenerate App\\Models\\Siswa --ids=1,2,3
php artisan media-library:clean --dry-run       # daftar berkas yatim, tanpa menghapus
php artisan media-library:clean                 # hapus konversi usang & berkas tanpa model
php artisan media-library:clear App\\Models\\Siswa foto   # kosongkan satu koleksi
```

`regenerate` dibutuhkan setiap kali definisi konversi berubah — mengganti ukuran `thumb` **tidak** menyentuh berkas yang sudah ada. Konversinya ikut queue seperti biasa, jadi untuk data banyak jalankan dengan worker hidup.

**`media-library:clear` tanpa argumen menghapus seluruh media milik semua model.** Kedua argumennya opsional, dan `ConfirmableTrait` yang dipakainya **hanya** bertanya kalau `APP_ENV=production` — sama persis dengan `activitylog:clean`, lihat *Pembersihan*. Di lokal dan staging ia langsung jalan tanpa konfirmasi, tanpa `--dry-run`, dan tanpa jalan pulang selain restore backup. Selalu sebut model dan koleksinya.

`clean` lebih jinak dan punya `--dry-run` — pakai itu lebih dulu, selalu. Keduanya belum dijadwalkan di `routes/console.php`; kalau nanti berkas yatim jadi masalah, `clean` yang layak dijadwalkan, bukan `clear`.

### Dua tumpukan gambar, dan itu disengaja

Repo ini sekarang punya **dua** pustaka pengolah gambar:

| Pustaka | Dipakai oleh | Untuk apa |
|---|---|---|
| `intervention/image` 4.2 | kode aplikasi, langsung | olahan ad-hoc |
| `spatie/image` 3.9 | `spatie/laravel-medialibrary`, internal | konversi media |

Bukan hasil kelalaian: medialibrary v11 memakai `spatie/image` dan tidak bisa diarahkan ke intervention. Menghapus intervention berarti kehilangan API yang lebih ekspresif untuk olahan di luar media library; menghapus spatie/image berarti kehilangan medialibrary. Keduanya menumpang ekstensi PHP yang sama (GD/Imagick), jadi biayanya kode di `vendor/`, bukan ekstensi tambahan di server.

Yang mahal bukan duplikasinya, tapi **kemungkinan keduanya berbeda driver** — lihat *Driver — satu env untuk dua paket*. Yang menjaga: `ImageDriverConfigurationTest`.

### Disk `public`, dan itu yang membuatnya ikut backup

`disk_name` default `public` → `storage/app/public`. Kebetulan yang menguntungkan: itu **satu-satunya direktori** yang masuk `backup.source.files.include`. Berkas media otomatis ikut arsip backup tanpa menyentuh config backup sama sekali.

**Memindahkannya ke disk lain membuat unggahan berhenti terbackup, tanpa peringatan.** `MEDIA_DISK=local` mengarah ke `storage/app/private` yang tidak ada di `include`; backup tetap "berhasil" dan tetap sehat, isinya saja yang kehilangan seluruh foto. Dikunci `test_media_lands_on_a_disk_that_is_backed_up`. Kalau disknya memang harus pindah, `backup.source.files.include` wajib ikut diperluas di commit yang sama.

**`php artisan storage:link` wajib**, kalau tidak `getUrl()` mengembalikan URL yang 404. Symlink `public/storage` tidak ada di git — ia dibuat per instalasi, sama seperti asset Filament.

### Konversi lewat queue

`queue_conversions_by_default` `true` dan `queue_connection_name` ikut `QUEUE_CONNECTION` (di sini `database`). Jadi thumbnail **tidak** jadi saat unggah selesai — ia menyusul lewat worker.

Konsekuensinya sama dengan tombol Backup Sekarang: **tanpa worker jalan, konversi tidak pernah ada.** Unggahan berhasil, berkas aslinya ada, `getUrl('thumb')` menunjuk berkas yang tidak pernah dibuat. `composer run dev` sudah membawa workernya.

Untuk konversi yang harus langsung ada (avatar kecil yang tampil di halaman yang sama), pakai `->nonQueued()` per konversi — jangan matikan queue global.

### Optimizer: tujuh dikonfigurasi, nol terpasang

`image_optimizers` mendaftar Jpegoptim, Pngquant, Optipng, Svgo, Gifsicle, Cwebp, dan Avifenc. **Tidak satu pun binernya ada di mesin ini.**

Yang membuat ini halus: `OptimizerChain` menangkap `ProcessFailedException` lalu **mencatatnya sebagai log error dan lanjut**. Konversi tetap jadi, ukurannya saja tidak pernah dioptimasi. Tidak ada exception, tidak ada notifikasi, dan satu-satunya jejaknya baris log yang tidak dibaca siapa pun.

```bash
sudo apt install jpegoptim optipng pngquant gifsicle webp
npm install -g svgo
```

Kelas jebakan yang sama dengan biner `sqlite3` untuk backup dan `ext-gd` untuk intervention: dependensi runtime yang tidak diwakili composer. Bedanya yang ini **tidak** gagal keras — ia cuma diam-diam berhenti bekerja.

### Batas ukuran

`max_file_size` 10 MB. Ini batas milik medialibrary, **bukan** pengganti validasi request dan bukan pengganti `upload_max_filesize`/`post_max_size` di PHP. Berkas yang lebih besar dari batas PHP tidak pernah sampai ke medialibrary untuk ditolak dengan rapi — ia mati lebih dulu di lapisan yang pesannya jauh lebih tidak ramah. Samakan ketiganya kalau batasnya diubah.

Foto dari HP modern rutin di atas 5 MB, jadi 10 MB tidak selega kedengarannya.

### Belum tercatat di audit log

`Media` memakai model bawaan paket (`config('media-library.media_model')`), jadi unggah dan hapus berkas **tidak** muncul di `activity_log`. Padahal menghapus foto siswa persis jenis perubahan yang perlu terlacak.

Polanya sudah ada di repo ini — sama dengan `App\Models\Role` dan `App\Models\Permission`: subclass model paketnya, pasang `LogsActivity`, lalu tunjuk dari config. Lihat *Model Role & Permission dioverride*. Belum dikerjakan karena belum ada modul yang mengunggah apa pun.

## Spreadsheet (maatwebsite/excel)

Export dan import XLSX/CSV. Versi 3.1.69, membawa `phpoffice/phpspreadsheet` 1.30.6. Belum ada satu pun kelas export maupun import — terpasang sebagai fondasi, sama seperti Reverb dan intervention.

| Berkas | Isi |
|---|---|
| `config/excel.php` | Chunk, cache sel, transaksi import, path berkas sementara, detektor ekstensi |

Provider dan alias **tidak** didaftarkan manual. Halaman instalasi 3.1 di dokumentasinya menyuruh menambahkan `ExcelServiceProvider` ke `config/app.php` dan alias `Excel` — itu instruksi untuk Laravel < 5.5. Laravel 13 tidak punya array `providers`/`aliases` di `config/app.php` lagi, jadi mengikuti langkah itu justru error. Auto-discovery sudah menanganinya.

```bash
php artisan make:export SiswaExport --model=Siswa
php artisan make:import SiswaImport --model=Siswa
```

```php
return Excel::download(new SiswaExport, 'siswa.xlsx');   // unduh langsung
Excel::store(new SiswaExport, 'siswa.xlsx', 'public');   // simpan ke disk
Excel::queue(new SiswaImport, $request->file('berkas')); // lewat queue
```

### Dokumentasinya 3.1, dan itu versi stabil terakhir

Bukan versi lama yang tertinggal — cabang `4.x-dev` ada di packagist tapi belum pernah rilis stabil, jadi `^3.1` memang pilihan yang benar sekarang. Yang perlu disadari cuma umurnya: halaman dokumen 3.1 berumur bertahun-tahun, dan sebagian langkahnya (lihat di atas) sudah tidak berlaku di Laravel 13.

### Dua plafon versi yang dibawa phpspreadsheet 1.x

`maatwebsite/excel` 3.1 mengunci `phpoffice/phpspreadsheet` di `^1.29`, sementara upstream sudah di 4.x. Dua konsekuensinya berbeda sifat:

**1. PHP 8.5 akan memblokir `composer update`.** phpspreadsheet 1.x menyatakan `"php": ">=7.4.0 <8.5.0"`. Repo ini sudah di PHP 8.4 — satu langkah dari plafonnya. Naik ke 8.5 berarti composer menolak resolusi sampai laravel-excel 4 rilis, dan gejalanya muncul saat upgrade PHP, bukan saat menulis kode.

**2. Fitur baru phpspreadsheet tidak akan datang.** 1.x masih menerima perbaikan keamanan tapi bukan fitur. `composer audit` bersih saat dipasang; ulangi pemeriksaan itu berkala, karena satu-satunya jalan naik adalah menunggu laravel-excel 4.

### Export PDF gagal keras — tidak ada driver terpasang

`extension_detector.pdf` di config berisi `Excel::DOMPDF`, dan itu **hanya nama pilihan** — pustakanya tidak ikut terpasang. Ketiga kandidatnya (`dompdf/dompdf`, `mpdf/mpdf`, `tecnickcom/tcpdf`) tidak ada di `vendor/`.

Akibatnya `Excel::download($export, 'siswa.pdf')` melempar **fatal `Error`, bukan exception yang bisa ditangkap dengan `catch (Exception)`**:

```
Error: Class "Dompdf\Dompdf" not found
```

Kalau nanti butuh PDF, pasang salah satunya dulu:

```bash
composer require dompdf/dompdf
```

Baris `'pdf' => Excel::DOMPDF` sudah cocok dengan itu; ganti nilainya kalau memilih mPDF atau TCPDF. Jangan berasumsi PDF sudah bisa hanya karena tercantum di config.

### Cache sel `memory` — dan itu batas ukuran yang sebenarnya

`cache.driver` bawaannya `memory`: **seluruh sel disimpan di RAM PHP**. `max_file_size` milik medialibrary tidak berlaku di sini, dan `chunk_size` 1000 hanya memotong query, bukan lembar yang dibaca.

Berkas import beberapa ribu baris cukup untuk menabrak `memory_limit`, dan matinya berupa fatal error kehabisan memori — bukan validasi yang rapi. Kalau itu terjadi, jangan naikkan `memory_limit`; ganti drivernya:

```php
'cache' => ['driver' => 'batch', 'batch' => ['memory_limit' => 60000]],
```

`batch` menahan sel di memori sampai ambangnya lalu menumpahkannya ke cache store. Lebih lambat, tapi ukuran berkas berhenti jadi taruhan. `illuminate` menulis tiap sel ke cache store — paling hemat memori, paling lambat.

### Import dibungkus transaksi — dan di SQLite itu mengunci seluruh database

`transactions.handler` bawaannya `db`, jadi satu import = satu transaksi. Bagus: import yang gagal di tengah ter-rollback utuh, tidak meninggalkan separuh baris.

Yang perlu disadari khusus repo ini: **SQLite mengunci seluruh berkas database, bukan per tabel**. Import besar karena itu memblokir tulisan lain selama ia berjalan — termasuk `backup:run`, yang justru membuka transaksinya sendiri lewat `BEGIN IMMEDIATE` (lihat *Butuh biner `sqlite3`, bukan cuma PHP*). Import panjang yang bertabrakan dengan jadwal backup akan membuat salah satunya gagal dengan `database is locked`.

Kalau nanti ada import massal terjadwal, jauhkan jamnya dari `backup:run` — sama seperti `backup:clean` sengaja dijauhkan dari `activitylog:clean`.

### Import melewati audit log kalau memakai insert massal

Jalur data masuk yang **tidak** lewat panel, jadi tidak otomatis tercatat. `ToModel` menyimpan per baris lewat model, jadi trait `LogsActivity` tetap jalan — tapi `ToCollection` yang diikuti `Model::insert()`, dan `WithBatchInserts`, **melewati model event sepenuhnya**. Ratusan siswa bisa masuk tanpa satu baris pun di `activity_log`.

Kelas masalah yang sama dengan `DatabaseSeeder` yang membungkam event — lihat *`DatabaseSeeder` membungkam model event*. Bedanya di sini yang hilang jejak auditnya, bukan cache izin.

Kalau import massal memang perlu, catat manual satu baris ringkasan (berapa baris, oleh siapa, dari berkas apa) seperti `Backups::savePassword()` melakukannya — bukan satu baris per siswa.

### Berkas sementara tidak ikut backup, dan itu benar

`temporary_files.local_path` menunjuk `storage/framework/cache/laravel-excel` — di luar `storage/app/public`, satu-satunya direktori yang masuk `backup.source.files.include`. Itu memang seharusnya: isinya sampah sementara.

Yang perlu diperhatikan justru **hasil export yang disimpan permanen**. `Excel::store($export, 'siswa.xlsx')` tanpa argumen disk memakai disk default (`local` → `storage/app/private`), yang **tidak ikut arsip backup**. Sebutkan disknya secara eksplisit kalau berkasnya harus bertahan:

```php
Excel::store(new SiswaExport, 'siswa.xlsx', 'public');
```

Latar belakangnya di *Disk `public`, dan itu yang membuatnya ikut backup*.

`remote_disk` sengaja dibiarkan `null` — ia hanya dibutuhkan kalau queue worker berjalan di mesin berbeda dari yang menerima unggahan. Repo ini satu mesin. Kalau nanti workernya dipisah, `null` membuat export/import terjadwal gagal menemukan berkas sementaranya, dan itu tidak terlihat sampai dijalankan di sana.

### Export besar lewat queue

`Excel::queue()` dan `ShouldQueue` memakai `QUEUE_CONNECTION` yang di sini `database`. Konsekuensinya identik dengan tombol Backup Sekarang dan konversi medialibrary: **tanpa worker jalan, tidak ada berkas yang pernah muncul.** Permintaannya sukses, notifikasinya keluar, hasilnya tidak ada. `composer run dev` sudah membawa workernya.

### Baris heading di-slug

`imports.heading_row.formatter` bawaannya `slug`, jadi `WithHeadingRow` mengubah `Nama Lengkap` di berkas jadi kunci `nama_lengkap`. Berguna, tapi berarti **kunci array tidak sama persis dengan teks di spreadsheet** — dan berkas dari pengguna sungguhan membawa spasi ganda, huruf besar, serta tanda baca yang ikut ternormalisasi. Jangan mencocokkan kunci dengan menebak; `dd()` satu baris pertama saat menulis importernya.

## Tes

`composer run test` — semuanya harus hijau sebelum commit. Database tes SQLite `:memory:` (`phpunit.xml`), jadi tidak menyentuh `database/database.sqlite`.

Kalau yang menjalankan adalah AI agent, outputnya berupa satu baris JSON, bukan tampilan PHPUnit biasa. Itu ulah `laravel/pao` — lihat bagian *Tooling dev*.

| File | Yang dijaga |
|---|---|
| `AdminPanelAccessTest` | Siapa boleh membuka `/admin`, dan panel id asing tidak mewarisi aturan admin |
| `RolePermissionTest` | Guard `web` cocok, gate super-admin, developer tidak ikut bypass, tiap role dapat izin bawaannya dan tidak lebih, tiap izin bawaan benar-benar ada, tiga baris config shield yang beda dari bawaan, seeder idempoten & tidak mencabut, dan seeding lewat `DatabaseSeeder` benar-benar memberikan izinnya |
| `ActivityLogTest` | Login/gagal login tercatat, password tidak bocor, halaman log render, filter pelaku |
| `AccessManagementUiTest` | Form pengguna & role, centang izin di tab Shield (termasuk tab kustom), pengaman anti-terkunci, tidak ada resource yang membuka model Permission, perubahan otorisasi masuk log — termasuk yang lewat editor Role milik Shield |
| `TableActionAuthorizationTest` | Tombol Ubah/Hapus benar-benar menolak, bukan cuma `can*()` yang bilang `false`; aksi header halaman Role masih dirender |
| `BackupConfigurationTest` | Tujuan backup, `.env` tidak ikut terarsip, nama pantauan cocok, jadwal terdaftar |
| `BackupPageTest` | Izin halaman backup, tombol unduh/hapus, arsip terbaru terlindungi, kunci baris palsu ditolak |
| `BackupScheduleTest` | Default mingguan, cron tiap frekuensi, scheduler pakai jadwal user, tabel hilang tidak bikin crash, ambang monitor ikut frekuensi |
| `BackupArchivePasswordTest` | Password panel menang atas `.env`, fallback ke `.env`, sampai ke kedua jalur backup, terenkripsi di DB, tidak bocor ke `activity_log` |
| `BackupRestoreTest` | Tombol Pulihkan butuh izinnya sendiri, salah ketik nama berkas menolak, swap berhasil, dan **tiap jalur gagal meninggalkan database hidup utuh**. Belum menjaga: izin baru yang hilang setelah memulihkan arsip lama |
| `ImageDriverConfigurationTest` | Dua tumpukan gambar memakai driver yang sama, nilainya diterima masing-masing paket, EXIF dibuang, dan berkas media mendarat di disk yang ikut backup |

**Broadcasting, pengolahan gambar, dan spreadsheet belum punya tes sama sekali.** Disengaja selama belum ada event yang disiarkan, belum ada berkas yang diolah, dan belum ada kelas export/import — tidak ada perilaku yang bisa dikunci. Begitu channel pertama lahir, yang wajib diuji adalah **closure otorisasinya**, bukan pengirimannya:

```php
$this->actingAs($tanpaIzin)->postJson('/broadcasting/auth', [
    'channel_name' => 'private-backup',
    'socket_id' => '1234.5678',
])->assertForbidden();
```

Menguji `Event::fake()` + `assertDispatched` cuma membuktikan event terkirim, dan itu bagian yang paling tidak mungkin salah. Yang benar-benar berisiko adalah channel yang lolos ke akun yang seharusnya tidak melihat datanya — kelas bug yang sama dengan *Action Filament TIDAK ikut `canEdit()`/`canDelete()`*: pengaman di satu lapis tidak otomatis berlaku di lapis lain.

Untuk gambar, prioritasnya sama-sama bukan bagian yang gampang: ukuran hasil resize akan ketahuan salah pada pandangan pertama, sedangkan **EXIF yang lolos ke berkas tersimpan tidak terlihat sama sekali**. Uji berkas hasilnya, bukan konfigurasinya — `config('intervention-image.options.strip')` bisa `true` sementara ada satu pemanggilan yang melewatkan `strip: false`, dan hanya berkas nyatanya yang membuktikan. Tesnya jalankan dengan driver Imagick; dengan GD tesnya selalu hijau karena GD tidak pernah menulis metadata, jadi ia tidak membuktikan apa pun.

Untuk spreadsheet, yang wajib diuji adalah **import yang gagal**, bukan yang berhasil. Export yang salah kolom ketahuan begitu berkasnya dibuka; import yang menerima baris cacat baru ketahuan berbulan-bulan kemudian sebagai data busuk. Yang layak dikunci: berkas dengan kolom kurang ditolak, baris tidak valid tidak menyisakan setengah data (transaksi benar-benar rollback), dan **siapa yang boleh mengimpor** — sama seperti channel broadcasting, itu jalur data masuk yang punya pengecekan izinnya sendiri. `Excel::fake()` menyediakan `assertDownloaded`/`assertStored`/`assertQueued` untuk sisi export.

`tests/Feature/ExampleTest.php` dan `tests/Unit/ExampleTest.php` masih bawaan Laravel. Yang Feature menjaga halaman `/` (view `welcome`) tetap 200 — kalau `routes/web.php` diganti, tes itu ikut diganti atau dihapus, jangan dibiarkan merah.

Beberapa tes menjaga hal yang **tidak kelihatan dari kode** — jangan dihapus karena terlihat sepele:

- `assertCount(1, ...)` pada tes log: menangkap listener yang terdaftar dua kali (lihat bagian `recordX`).
- `test_every_baseline_permission_exists`: menangkap string izin di `Role::permissions()` yang tidak cocok dengan apa pun yang digenerate Shield. Sejak enum `Permission` dihapus, nama izin tidak lagi diperiksa tipe — salah ketik memberi izin yang tidak menjaga apa pun, tanpa error. `test_every_enum_role_is_seeded` menjaga hal yang sama untuk role.
- `test_the_super_admin_name_matches_shield`, `test_shield_grants_super_admin_through_the_gate`, dan `test_the_shield_panel_user_role_is_disabled`: mengunci tiga baris `config/filament-shield.php` yang **berbeda dari bawaan paket**. Kembali ke bawaan tidak menimbulkan error apa pun — yang berubah cuma siapa yang bisa apa.
- `test_no_panel_resource_exposes_permissions_directly`: pengganti tes resource Izin read-only yang lama. Menjaga tidak ada resource panel yang menunjuk model Permission.
- `test_the_role_pages_still_render_their_header_actions`: menangkap `shield:publish` yang dijalankan ulang dan mengembalikan hook `getActions()`, yang membuat tombol di header halaman Role diam-diam berhenti dirender.
- `test_editing_a_role_through_shield_reaches_the_audit_log`: editor Role sekarang kode vendor. Kalau versi Shield berikutnya menulis pivot langsung ketimbang lewat model, panelnya tetap jalan dan perubahan otorisasi berhenti tercatat tanpa gejala apa pun.
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
User::factory()->withPermissions(['Access:AdminPanel', 'ViewAny:User'])->create();
```

Keduanya membuat role/permission-nya sendiri kalau seeder belum jalan. Konsekuensinya `withPermissions()` **tidak** memvalidasi namanya: salah ketik menghasilkan user yang memegang izin yang tidak dicek siapa pun, dan tesnya lolos karena alasan yang salah. Uji perilakunya (halaman render / 403), jangan grantnya.

## Menambah modul baru

Contoh: modul Siswa. Urutan ini menyentuh semua subsistem yang sudah ada — melewati satu langkah biasanya baru ketahuan jauh belakangan.

**1. Migrasi + model**

```bash
php artisan make:model Siswa -m
php artisan migrate
```

**2. Pasang audit log** di modelnya — lihat bagian *Menambah model baru ke audit log*. Kalau ada kolom sensitif (NIK, nomor HP wali), tambahkan `->logExcept([...])`.

**3. Generate permission**-nya dari resource yang baru dibuat (jalankan setelah langkah 4 kalau resourcenya belum ada), lalu seed:

```bash
php artisan shield:generate --resource=SiswaResource --panel=admin
php artisan db:seed --class=RolePermissionSeeder
```

Jangan lewat, izin yang tidak punya baris di tabel `permissions` **selalu** `false`.

Lalu tentukan role mana yang mendapatkannya di `Role::permissions()`. `developer` otomatis ikut (daftarnya dibaca dari tabel), sisanya tidak — role `guru` tidak akan bisa melihat modul guru sampai izinnya ditambahkan ke sana. Namanya string, jadi salinlah dari keluaran `shield:generate`; `test_every_baseline_permission_exists` akan merah kalau salah ketik.

**4. Buat resource Filament**

```bash
php artisan make:filament-resource Siswa --generate --view
```

**5. Pasang `canAccess()`** di resource — tanpa ini menunya terbuka untuk semua yang bisa masuk panel:

```php
public static function canAccess(): bool
{
    return (bool) Filament::auth()->user()?->can('ViewAny:Siswa');
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
6. `php artisan db:seed --class=RolePermissionSeeder` — role dan permission harus ada, kalau tidak semua pengecekan akses `false` dan tidak ada yang bisa masuk panel. Seeder ini memanggil `shield:generate` dalam mode data (`--option=permissions`), jadi resource yang baru ikut rilis ini langsung punya izinnya. Ia tidak membuat user, jadi aman di produksi, dan aman diulang tiap rilis: ia menambah, tidak pernah mencabut, sehingga penyesuaian izin yang dibuat lewat panel tetap utuh.
7. **Jangan jalankan `db:seed` polos** — itu ikut menjalankan `AdminUserSeeder` yang memakai password `admin`. Buat akun produksi lewat `php artisan make:filament-user`, lalu `assignRole('super-admin')` manual. Tanpa role, akun barunya kena 403.
8. Pastikan cron scheduler aktif, kalau tidak `activitylog:clean` dan seluruh perintah backup tidak pernah jalan — `activity_log` tumbuh tanpa batas dan tidak ada arsip yang pernah dibuat.
9. `sudo apt install sqlite3` — `backup:run` **dan** tombol Pulihkan sama-sama butuh binernya, ekstensi PHP saja tidak cukup.
10. Setel password arsip lewat tombol **Password Arsip** di `/admin/backups` (atau isi `BACKUP_ARCHIVE_PASSWORD` di `.env` sebagai cadangan), **lalu simpan salinannya di luar server**. Keduanya kosong = arsip tidak terenkripsi, tanpa peringatan apa pun. Password disimpan terenkripsi dengan `APP_KEY` — rotasi `APP_KEY` = password hilang. Password yang disetel lewat panel hidup di database yang ia backup: kehilangan database = kehilangan password = arsip mati. Lihat *Jebakan melingkar* di bagian Enkripsi.
11. Setel SMTP sungguhan. Dengan `MAIL_MAILER=log`, notifikasi backup gagal hanya masuk file log dan tidak dibaca siapa pun.
12. Jalankan `php artisan backup:run` sekali secara manual, lalu `php artisan backup:list`. Kegagalan PATH `sqlite3` paling enak ketahuan sekarang, bukan jam 01:30 saat tidak ada yang melihat.
13. Tambahkan disk luar (S3/rsync) ke `backup.destination.disks`. Arsip yang hanya duduk di disk yang sama dengan aplikasinya tidak menolong saat disknya yang mati.
14. Jangan berikan `Restore:Backup` ke siapa pun secara default. Pemegangnya bisa mengganti tabel `users` dengan versi arsip — lihat *Restore*. Berikan saat dibutuhkan, cabut setelahnya.
15. Uji restore-nya **sekali** ke instalasi lain sebelum mempercayainya. Backup yang belum pernah dipulihkan belum terbukti backup. Ingat menjalankan `db:seed --class=RolePermissionSeeder` sesudahnya — lihat *Restore*.
16. Setel `VITE_REVERB_HOST` ke domain publik dan `VITE_REVERB_SCHEME=https` **sebelum** langkah 3 — nilainya tertanam di bundel saat build, jadi mengubahnya sesudah `npm run build` tidak berpengaruh sampai di-build ulang. Biarkan `REVERB_HOST` sendiri menunjuk `127.0.0.1`; keduanya beda arah, lihat *Broadcasting*.
17. Jalankan `reverb:start` di bawah supervisor (systemd/supervisord), bukan dari SSH. Ia proses yang harus terus hidup, sama seperti queue worker.
18. Proxy `wss://` di Nginx ke port 8080 dengan header `Upgrade`/`Connection`. Tanpa itu browser di `https://` menolak menyambung, dan penolakannya tidak meninggalkan jejak di log server.
19. `sudo apt install php8.4-gd php8.4-exif` lalu pastikan `php -m` menampilkannya. `intervention/image` **tidak** mencantumkan `ext-gd` di `require`, jadi `composer install` tetap sukses tanpa itu dan kegagalannya baru muncul saat gambar pertama diproses. `ext-exif` dibutuhkan `autoOrientation`, kalau tidak foto potret tersimpan miring.
20. `php artisan storage:link`. Symlink `public/storage` tidak ada di git, dan tanpanya tiap `getUrl()` milik media library mengembalikan 404 padahal berkasnya ada.
21. `sudo apt install jpegoptim optipng pngquant gifsicle webp` (plus `npm install -g svgo`). Optimizer yang binernya hilang **tidak** menggagalkan konversi — ia dicatat sebagai log error lalu dilewati, jadi gambar tersimpan tanpa optimasi selamanya tanpa ada yang tahu.
22. Jangan ubah `MEDIA_DISK` tanpa ikut memperluas `backup.source.files.include`. Disk `public` kebetulan satu-satunya yang ikut arsip backup; memindahkannya membuat seluruh unggahan berhenti terbackup sementara backup tetap melapor sehat.
23. Pastikan `storage/framework/cache/laravel-excel` bisa ditulis. Di sanalah berkas sementara export/import mendarat; direktorinya tidak ada di git dan dibuat saat pemakaian pertama, jadi permission yang salah baru ketahuan saat export pertama diminta pengguna.
24. Samakan `memory_limit` PHP dengan ukuran berkas yang realistis, atau ganti `excel.cache.driver` ke `batch`. Bawaannya `memory` menahan **seluruh sel di RAM** — matinya berupa fatal error kehabisan memori, bukan pesan validasi. Lihat *Cache sel `memory`*.
25. Jangan janjikan export PDF sebelum `composer require dompdf/dompdf`. `config/excel.php` mencantumkan `Excel::DOMPDF`, tapi itu cuma nama pilihan — pustakanya tidak terpasang, dan kegagalannya berupa fatal `Error`, bukan exception yang tertangkap.

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
izin: Access:AdminPanel, Create:Role, Create:User, Delete:Role, Delete:User, Restore:Backup, Update:Role, Update:User, View:Activity, View:Backups, View:Role, View:User, ViewAny:Activity, ViewAny:Role, ViewAny:User
role: admin, developer, guru, karyawan, murid, super-admin
admin-role: super-admin
akses-panel: true
```

Kalau `model-role` menunjuk `Spatie\Permission\Models\Role`, perubahan role tidak masuk audit log — cek `config/permission.php`.

Kalau daftar izinnya kosong atau kurang, `shield:generate` belum pernah jalan di instalasi itu — jalankan `php artisan db:seed --class=RolePermissionSeeder`, yang memanggilnya.

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

### Gambar

```bash
php -m | grep -E '^(gd|imagick|exif)$'
php artisan config:clear
php artisan tinker --execute="
use Intervention\Image\Laravel\Facades\Image;
use Intervention\Image\Format;
echo 'intervention: '.config('intervention-image.driver').PHP_EOL;
echo 'medialibrary: '.config('media-library.image_driver').PHP_EOL;
echo 'disk media: '.config('media-library.disk_name').PHP_EOL;
echo 'strip: '.var_export(config('intervention-image.options.strip'), true).PHP_EOL;
\\\$t = Image::createImage(400, 300)->fill('336699')->scale(width: 120);
echo 'scaled: '.\\\$t->width().'x'.\\\$t->height().PHP_EOL;
echo 'webp: '.strlen((string) \\\$t->encodeUsingFormat(Format::WEBP, quality: 80)).' bytes'.PHP_EOL;
"
```

Output yang diharapkan:

```
exif
gd
imagick
intervention: Intervention\Image\Drivers\Gd\Driver
medialibrary: gd
disk media: public
strip: true
scaled: 120x90
webp: 98 bytes
```

Dua baris driver pertama **harus menunjuk driver yang sama** — satu nama class, satu nama pendek. Kalau berbeda, `ImageDriverConfigurationTest` sudah merah lebih dulu; kalau `medialibrary` berisi nama class, tiap konversi gambar akan melempar `InvalidImageDriver`.

`imagick` opsional — yang wajib cuma `gd` (atau Imagick kalau `IMAGE_DRIVER` diarahkan ke sana) dan `exif`. `strip: false` berarti `config/intervention-image.php` kembali ke bawaan paket — baca alasannya di *`strip` sengaja `true`* sebelum membiarkannya. `Call to undefined method` pada `createImage` atau `encodeUsingFormat` berarti versinya turun di bawah 4.2, lihat tabel rename API.

### Spreadsheet

```bash
php -m | grep -E '^(zip|xml|xmlwriter|xmlreader|SimpleXML|iconv|zlib)$' | sort
php artisan config:clear
php artisan tinker --execute='
echo "cache sel: ".config("excel.cache.driver")." | transaksi import: ".config("excel.transactions.handler").PHP_EOL;
echo "heading: ".config("excel.imports.heading_row.formatter")." | chunk: ".config("excel.exports.chunk_size").PHP_EOL;
echo "temp: ".str_replace(base_path()."/", "", config("excel.temporary_files.local_path")).PHP_EOL;
echo "driver pdf: ".(class_exists("Dompdf\\Dompdf") ? "ada" : "tidak ada").PHP_EOL;
'
```

Output yang diharapkan:

```
iconv
SimpleXML
xml
xmlreader
xmlwriter
zip
zlib
cache sel: memory | transaksi import: db
heading: slug | chunk: 1000
temp: storage/framework/cache/laravel-excel
driver pdf: tidak ada
```

`driver pdf: tidak ada` **memang keadaan sekarang** dan bukan tanda instalasi gagal — lihat *Export PDF gagal keras*. Ekstensi yang kurang di baris pertama berarti `composer install` seharusnya sudah menolak; kalau ia lolos, paketnya dipasang dengan `--ignore-platform-reqs` dan kegagalannya menunggu di export pertama.

`cache sel: memory` aman untuk berkas kecil; sebelum ada import massal, baca *Cache sel `memory`*.
