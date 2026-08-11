<?php

/*
|--------------------------------------------------------------------------
| Rute binafy/laravel-user-monitoring — sengaja kosong
|--------------------------------------------------------------------------
|
| Berkas ini ADA supaya isinya tidak ada.
|
| LaravelUserMonitoringRouteServiceProvider memuat berkas yang ditunjuk
| `user-monitoring.config.routes.file_path`, dan hanya jatuh ke milik paket
| kalau berkas itu tidak ditemukan. Menghapus berkas ini akan mengembalikan
| enam rute bawaannya:
|
|   GET    /user-monitoring/visits-monitoring
|   GET    /user-monitoring/actions-monitoring
|   GET    /user-monitoring/authentications-monitoring
|   DELETE /user-monitoring/{visits,actions,authentications}-monitoring/{id}
|
| Keenamnya berjalan dengan middleware `web` saja. `BaseController` milik
| paket tidak memanggil satu pun `authorize()`, dan controllernya tidak
| mengecek apa pun. Artinya bawaannya:
|
|   - Siapa pun, TANPA LOGIN, bisa membaca IP, browser, dan setiap halaman
|     yang pernah dibuka tiap pengguna.
|   - Siapa pun, TANPA LOGIN, bisa MENGHAPUS baris pemantauan — yaitu
|     menghapus jejak kunjungannya sendiri.
|
| UI-nya dibangun ulang sebagai resource Filament di dalam panel, dijaga izin
| shield seperti resource lain. Lihat bagian *Pemantauan Pengguna* di
| CLAUDE.md. Dikunci `UserMonitoringAccessTest`.
|
*/
