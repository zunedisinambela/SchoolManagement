<?php

namespace Tests\Feature;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Laravel\Facades\Image;
use Spatie\Image\Image as SpatieImage;
use Tests\TestCase;

/**
 * Menjaga dua tumpukan pengolah gambar tetap sepakat.
 *
 * Repo ini punya dua: intervention/image (dipakai langsung) dan spatie/image
 * (dibawa spatie/laravel-medialibrary untuk konversi). Keduanya membaca env
 * `IMAGE_DRIVER` yang sama tapi dengan format nilai berbeda — intervention
 * mengharapkan nama class, medialibrary mengharapkan `gd`/`imagick`.
 *
 * Kalau keduanya berbeda driver, tidak ada error yang muncul. Yang berubah
 * diam-diam adalah dukungan format dan perlakuan metadata EXIF, dan itu baru
 * ketahuan dari isi berkas yang sudah tersimpan.
 */
class ImageDriverConfigurationTest extends TestCase
{
    /**
     * Nilai `media-library.image_driver` dipakai apa adanya oleh
     * `Spatie\Image\Image::useImageDriver()`. Nama class di sana melempar
     * InvalidImageDriver pada tiap konversi — bukan saat boot, jadi lolos
     * sampai ada gambar pertama yang diunggah.
     */
    public function test_the_media_library_driver_is_a_name_spatie_image_accepts(): void
    {
        $driver = config('media-library.image_driver');

        $this->assertContains($driver, ['gd', 'imagick', 'vips']);

        // Membuktikan nilainya benar-benar diterima, bukan cuma cocok daftar.
        SpatieImage::useImageDriver($driver);
    }

    /**
     * Intervention menerima nama class, dan `env('IMAGE_DRIVER')` mentah tidak
     * pernah berisi itu selama env-nya menyimpan nama pendek untuk
     * medialibrary. Pemetaannya ada di config/intervention-image.php — tes ini
     * menangkap kalau ia dikembalikan jadi env(...) langsung.
     */
    public function test_the_intervention_driver_is_an_existing_class(): void
    {
        $driver = config('intervention-image.driver');

        $this->assertTrue(
            class_exists($driver),
            "Driver intervention '{$driver}' bukan class yang ada. ".
            'config/intervention-image.php harus memetakan IMAGE_DRIVER, bukan memakainya mentah.',
        );

        $this->assertInstanceOf($driver, Image::createImage(1, 1)->driver());
    }

    /**
     * Inti tes ini. Dua paket, satu env, dan tidak ada apa pun di runtime yang
     * memprotes kalau mereka menunjuk driver berbeda.
     */
    public function test_both_image_stacks_resolve_to_the_same_driver(): void
    {
        $expected = match (config('media-library.image_driver')) {
            'imagick' => ImagickDriver::class,
            'gd' => GdDriver::class,
            default => $this->fail('Driver medialibrary tidak dikenal: '.config('media-library.image_driver')),
        };

        $this->assertSame(
            $expected,
            config('intervention-image.driver'),
            'intervention/image dan spatie/image memakai driver berbeda. '.
            'Dukungan format dan perlakuan EXIF ikut berbeda, tanpa error.',
        );
    }

    /**
     * Foto dari HP membawa EXIF berisi koordinat GPS. Encoder GD mengabaikan
     * opsi ini sepenuhnya, jadi nilainya hanya terasa saat driver Imagick —
     * dan justru itu alasannya harus benar sekarang, bukan nanti saat
     * seseorang mengganti driver karena alasan lain.
     */
    public function test_exif_metadata_is_stripped_on_encode(): void
    {
        $this->assertTrue(config('intervention-image.options.strip'));
    }

    /**
     * Berkas media mendarat di disk `public` yaitu storage/app/public, dan
     * itulah satu-satunya direktori yang ikut arsip backup. Memindahkannya ke
     * disk lain membuat unggahan berhenti terbackup tanpa peringatan apa pun.
     */
    public function test_media_lands_on_a_disk_that_is_backed_up(): void
    {
        $disk = config('media-library.disk_name');

        $this->assertSame('public', $disk);
        $this->assertContains(
            config("filesystems.disks.{$disk}.root"),
            config('backup.backup.source.files.include'),
        );
    }
}
