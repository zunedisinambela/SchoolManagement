<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Listeners\DisconnectFromDatabases;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Menjaga dua baris config yang membuat Octane aman di repo ini.
 *
 * Octane menahan aplikasi tetap ter-boot di memori dan memakai ulang worker
 * yang sama untuk banyak request. Itu mengubah dua asumsi yang sebelumnya
 * selalu benar: bahwa state dalam memori mati di akhir request, dan bahwa
 * koneksi database dibuka ulang setiap kali.
 *
 * Keduanya di bawah ini **berbeda dari bawaan paket**. Kembali ke bawaan tidak
 * menghasilkan error apa pun — yang berubah cuma siapa yang bisa apa, dan
 * database mana yang sebenarnya dibaca.
 */
class OctaneConfigurationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `PermissionRegistrar` adalah singleton yang menyimpan koleksi izin di
     * memori, dan `loadPermissions()` berhenti lebih awal begitu koleksi itu
     * terisi — ia tidak pernah menengok lagi ke cache bersama.
     *
     * Tanpa listener ini, izin yang dicabut lewat panel tetap berlaku di
     * setiap worker yang sudah memuatnya, sampai worker itu didaur ulang oleh
     * `max_requests`. Tidak ada error, tidak ada gejala — hanya otorisasi yang
     * salah pada sebagian request.
     */
    public function test_the_permission_cache_is_reset_between_octane_operations(): void
    {
        $this->assertTrue(
            config('permission.register_octane_reset_listener'),
            'Tanpa ini, izin yang dicabut tetap berlaku sampai worker Octane didaur ulang.',
        );
    }

    /**
     * Membuktikan mekanismenya, bukan cuma nilai config-nya.
     *
     * Meniru satu worker Octane yang melayani dua request dengan pencabutan
     * izin di antaranya — pencabutan yang terjadi di proses lain, sehingga
     * memori worker ini tidak ikut tersentuh.
     *
     * User yang dipakai **wajib** non-super-admin: super-admin lolos lewat
     * `Gate::before` milik shield dan akan `true` apa pun keadaan cachenya,
     * jadi ia tidak membuktikan apa-apa. Jebakan yang sama dengan
     * `test_seeding_through_the_database_seeder_grants_role_permissions`.
     */
    public function test_a_stale_permission_collection_still_grants_a_revoked_permission(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('admin');

        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        // Request pertama: izin termuat ke memori singleton milik worker.
        $this->assertTrue($user->can('ViewAny:User'));

        // Proses lain mencabut izinnya dan membatalkan cache bersama.
        DB::table('role_has_permissions')
            ->whereIn('role_id', DB::table('roles')->where('name', 'admin')->pluck('id'))
            ->where('permission_id', DB::table('permissions')->where('name', 'ViewAny:User')->value('id'))
            ->delete();
        Cache::forget(config('permission.cache.key'));
        $user->unsetRelation('roles')->unsetRelation('permissions');

        // Request kedua di worker yang sama: masih mengizinkan. Inilah bug-nya.
        $this->assertTrue(
            $user->can('ViewAny:User'),
            'Kalau ini gagal, spatie sudah berhenti menyimpan koleksi izin di memori '.
            'dan listener Octane-nya mungkin tidak lagi dibutuhkan — periksa ulang.',
        );

        // Yang dilakukan listener Octane di akhir tiap operasi.
        $registrar->clearPermissionsCollection();
        $user->unsetRelation('roles')->unsetRelation('permissions');

        $this->assertFalse(
            $user->can('ViewAny:User'),
            'clearPermissionsCollection() harus membuat izin yang dicabut benar-benar dicabut.',
        );
    }

    /**
     * Tombol Pulihkan di /admin/backups menukar `database/database.sqlite`
     * lewat `rename()`. Koneksi yang bertahan melintasi request tetap memegang
     * inode LAMA setelah swap itu — worker yang sudah terhubung menyajikan
     * database sebelum-restore sampai didaur ulang.
     *
     * Bawaan Octane mengomentari listener ini. Di repo ini ia diaktifkan.
     */
    public function test_database_connections_are_dropped_between_octane_operations(): void
    {
        // Kuncinya kontrak, bukan event konkret: RequestTerminated,
        // TaskTerminated, dan TickTerminated semuanya mengimplementasikannya.
        // `Events\OperationTerminated` tidak ada — dan `::class` tetap
        // menghasilkan string untuk class yang tidak ada, jadi salah tulis di
        // sini berujung config() yang mengembalikan null tanpa error apa pun.
        $this->assertTrue(interface_exists(OperationTerminated::class));

        $listeners = config('octane.listeners.'.OperationTerminated::class, []);

        $this->assertContains(
            DisconnectFromDatabases::class,
            $listeners,
            'Tanpa ini, restore backup tidak terlihat oleh worker Octane yang sudah terhubung.',
        );
    }

    /**
     * Membuktikan kenapa listener di atas dibutuhkan, tanpa menyentuh database
     * aplikasi: koneksi yang dipakai ulang tetap membaca berkas lama setelah
     * `rename()`, dan `DB::purge()` — persis yang dilakukan listener itu —
     * yang membuatnya melihat berkas baru.
     */
    public function test_a_reused_connection_keeps_reading_the_pre_restore_file(): void
    {
        $hidup = tempnam(sys_get_temp_dir(), 'oct').'.sqlite';
        $arsip = tempnam(sys_get_temp_dir(), 'oct').'.sqlite';

        foreach ([$hidup => 'DATA-LAMA', $arsip => 'DATA-BARU'] as $path => $isi) {
            $pdo = new \PDO('sqlite:'.$path);
            $pdo->exec('create table t (v text)');
            $pdo->exec("insert into t values ('{$isi}')");
        }

        config(['database.connections.octane_uji' => [
            'driver' => 'sqlite', 'database' => $hidup, 'prefix' => '',
        ]]);

        $baca = fn () => DB::connection('octane_uji')->table('t')->value('v');

        $this->assertSame('DATA-LAMA', $baca());

        rename($arsip, $hidup);   // persis yang dilakukan RestoreArchive

        $this->assertSame('DATA-LAMA', $baca(), 'Koneksi yang dipakai ulang memegang inode lama.');

        DB::purge('octane_uji');

        $this->assertSame('DATA-BARU', $baca());

        DB::purge('octane_uji');
        @unlink($hidup);
    }
}
