<?php

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Image Driver
    |--------------------------------------------------------------------------
    |
    | Paket ini mengharapkan nama class, sementara spatie/laravel-medialibrary
    | membaca env yang SAMA (`IMAGE_DRIVER`) dan mengharapkan nama pendek
    | `gd`/`imagick`. Satu env tidak bisa memenuhi keduanya, dan nilai class
    | membuat medialibrary melempar InvalidImageDriver di tiap konversi.
    |
    | Karena itu env-nya menyimpan nama pendek — bentuk yang dipakai
    | medialibrary apa adanya — dan pemetaan ke class dilakukan di sini.
    | Jangan kembalikan ke env(...) langsung: kedua paket akan berbeda driver
    | tanpa error, dan itu berarti dukungan format serta kebijakan EXIF ikut
    | berbeda. Dikunci `ImageDriverConfigurationTest`.
    |
    | Vips butuh paket terpisah `intervention/image-driver-vips` yang belum
    | terpasang, jadi sengaja tidak ada di sini walau komentar bawaan paket
    | mencantumkannya.
    */

    'driver' => match (env('IMAGE_DRIVER', 'gd')) {
        'imagick' => ImagickDriver::class,
        default => GdDriver::class,
    },

    /*
    |--------------------------------------------------------------------------
    | Configuration Options
    |--------------------------------------------------------------------------
    |
    | These options control the behavior of Intervention Image.
    |
    | - "autoOrientation" controls whether imported images should be
    |    automatically rotated according to any existing Exif data.
    |
    | - "decodeAnimation" determines whether animated images are decoded
    |    with their animation intact or if the animation is discarded.
    |
    | - "backgroundColor" defines the default background and blending color.
    |
    | - "strip" controls whether metadata like Exif tags should be removed
    |    automatically when encoding images.
    */

    'options' => [
        'autoOrientation' => true,
        'decodeAnimation' => true,
        'backgroundColor' => 'ffffff',

        // Sengaja `true`, beda dengan bawaan paket. Foto siswa dari HP membawa
        // EXIF berisi koordinat GPS dan identitas perangkat. Encoder GD
        // mengabaikan opsi ini (GD memang tidak pernah menulis metadata), jadi
        // dengan driver bawaan nilainya tidak terasa — tapi begitu
        // IMAGE_DRIVER=imagick, `false` membuat koordinat itu ikut tersimpan
        // ke berkas yang disajikan publik. Lihat bagian Gambar di CLAUDE.md.
        'strip' => true,
    ],
];
