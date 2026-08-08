# CLAUDE.md

Panduan untuk Claude Code saat bekerja di repo ini.

## Stack

| Komponen | Versi |
|---|---|
| PHP | 8.4 |
| Laravel | 13 |
| Filament (admin panel) | 5 |
| Database | SQLite — `database/database.sqlite` |
| Frontend | Vite |
| Debug | barryvdh/laravel-debugbar (dev only) |

Belum ada modul aplikasi — baru skeleton + panel admin kosong. Tabel yang ada baru bawaan Laravel (`users`, `cache`, `jobs`).

## Perintah

```bash
composer run dev      # serve + queue:listen + pail (log) + vite, sekaligus
composer run test     # config:clear lalu artisan test
composer run setup    # install deps, generate key, migrate, build asset
./vendor/bin/pint     # format kode PHP
```

## Filament (admin panel)

Panel `admin` di `app/Providers/Filament/AdminPanelProvider.php`, terdaftar di `bootstrap/providers.php`.

- URL: `/admin`, login di `/admin/login`.
- Resource, page, dan widget auto-discovery dari `app/Filament/Resources`, `app/Filament/Pages`, `app/Filament/Widgets`. Cukup buat file di sana, tidak perlu registrasi manual.
- Generator: `php artisan make:filament-resource Siswa`, `make:filament-page`, `make:filament-widget`.
- Buat user admin: `php artisan make:filament-user`.
- UI panel sudah berbahasa Indonesia otomatis — Filament membawa locale `id` sendiri dan mengikuti `APP_LOCALE`. Tidak perlu menerjemahkan apa pun untuk teks bawaan panel.

**Asset panel di-gitignore.** `public/css/filament`, `public/js/filament`, `public/fonts/filament` adalah hasil generate, bukan source. Setelah `composer update` atau saat deploy **wajib** jalankan:

```bash
php artisan filament:assets
```

Kalau dilewat, panel tampil tanpa CSS.

**Belum ditangani — akses panel terbuka.** Secara default *semua* user di tabel `users` bisa login ke `/admin`. Sebelum produksi, `App\Models\User` harus implement `Filament\Models\Contracts\FilamentUser` dan mendefinisikan `canAccessPanel()`.

## Tooling dev

`barryvdh/laravel-debugbar` terpasang sebagai **dev dependency** (`require-dev`).

- Auto-discovery, tidak perlu daftar provider manual.
- Dikendalikan `DEBUGBAR_ENABLED` di `.env`. Kalau tidak diset, ikut `APP_DEBUG`.
- `.env.example` sengaja `DEBUGBAR_ENABLED=false` — aman kalau di-copy ke server.
- **Jangan pernah aktif di produksi.** Debugbar membocorkan query SQL, isi session, dan variabel env.
- Config bisa di-publish kalau perlu tuning collector: `php artisan vendor:publish --provider="Barryvdh\Debugbar\ServiceProvider"`.

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
6. Pastikan `canAccessPanel()` sudah dibuat (lihat bagian Filament).

## Verifikasi cepat

```bash
php artisan config:clear
php artisan tinker --execute="
echo config('app.timezone').' | '.app()->getLocale().PHP_EOL;
echo now()->translatedFormat('l, d F Y H:i').PHP_EOL;
echo __('validation.required', ['attribute' => 'nama']).PHP_EOL;
echo __('filament-panels::auth/pages/login.title').PHP_EOL;
"
php artisan route:list --path=admin
```

Output yang diharapkan:

```
Asia/Jakarta | id
Sabtu, 08 Agustus 2026 22:40
Kolom nama wajib diisi.
Masuk
```
