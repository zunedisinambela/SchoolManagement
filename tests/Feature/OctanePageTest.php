<?php

namespace Tests\Feature;

use App\Filament\Pages\Octane;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Models\User;
use App\Support\Octane\Diagnostics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\FrankenPhp\ServerProcessInspector;
use Livewire\Livewire;
use Mockery;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Menjaga halaman /admin/octane.
 *
 * Berbeda tugas dari OctaneConfigurationTest: yang itu menjaga nilai config-nya
 * benar, yang ini menjaga halaman yang melaporkannya benar-benar melaporkan —
 * dan menjaga tombol Muat Ulang Worker tidak bisa dipanggil orang yang tidak
 * berhak. Kelas bug yang sama dengan Pulihkan di halaman Backup: aksi Filament
 * tidak punya otorisasi otomatis.
 */
class OctanePageTest extends TestCase
{
    use RefreshDatabase;

    private string $stateFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Wajib. Bawaannya storage/logs/octane-server-state.json, dan di mesin
        // yang pernah menjalankan `octane:start` berkas itu ADA — kadang dengan
        // server yang benar-benar hidup di belakangnya. Tanpa isolasi ini,
        // tesnya lulus atau gagal tergantung apa yang sedang jalan di laptop
        // orang yang menjalankannya.
        $this->stateFile = storage_path('framework/testing/octane-state-'.getmypid().'.json');

        @mkdir(dirname($this->stateFile), recursive: true);
        @unlink($this->stateFile);

        config(['octane.state_file' => $this->stateFile]);
    }

    protected function tearDown(): void
    {
        @unlink($this->stateFile);

        parent::tearDown();
    }

    private function viewer(): User
    {
        $user = User::factory()->withPermissions(['Access:AdminPanel', 'View:Octane'])->create();

        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<int, string>  $extra
     */
    private function reloader(array $extra = []): User
    {
        $user = User::factory()
            ->withPermissions([...['Access:AdminPanel', 'View:Octane', 'Reload:Octane'], ...$extra])
            ->create();

        $this->actingAs($user);

        return $user;
    }

    public function test_panel_access_alone_does_not_open_the_octane_page(): void
    {
        $this->actingAs(User::factory()->withPermissions(['Access:AdminPanel'])->create());

        $this->get(Octane::getUrl())->assertForbidden();
    }

    public function test_the_permission_opens_the_octane_page(): void
    {
        $this->viewer();

        $this->get(Octane::getUrl())->assertSuccessful();
    }

    public function test_the_page_renders_every_diagnostic(): void
    {
        $this->viewer();

        Livewire::test(Octane::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(array_keys((new Diagnostics)->checks()));
    }

    /**
     * Membaca status server tidak berbahaya; memuat ulang worker menyentuh
     * proses yang menyajikan panel itu sendiri. Karena itu izinnya dua.
     */
    public function test_the_reload_action_is_hidden_without_its_own_permission(): void
    {
        $this->viewer();

        Livewire::test(Octane::class)->assertActionHidden('muatUlang');
    }

    public function test_the_reload_action_is_visible_with_its_own_permission(): void
    {
        $this->reloader();

        Livewire::test(Octane::class)->assertActionVisible('muatUlang');
    }

    /**
     * Tidak ada state file di setUp, jadi tidak ada server yang jalan — dan
     * tombolnya harus mati alih-alih mengirim PATCH ke port yang tidak ada.
     */
    public function test_the_reload_action_is_disabled_while_the_server_is_not_running(): void
    {
        $this->reloader();

        Livewire::test(Octane::class)->assertActionDisabled('muatUlang');
    }

    /**
     * Memanggil tombolnya sungguhan, bukan cuma memeriksa `disabled`.
     *
     * Kalau pengamannya cuma di tampilan, aksinya akan tetap jalan dan menulis
     * baris audit untuk muat ulang yang tidak pernah terjadi. Tes yang hanya
     * memanggil assertActionDisabled tidak menangkap itu.
     */
    public function test_calling_reload_while_the_server_is_down_records_nothing(): void
    {
        $this->reloader();

        Livewire::test(Octane::class)->callAction('muatUlang');

        $this->assertSame(0, Activity::query()->where('log_name', 'octane')->count());
    }

    /**
     * Jalur pencatatannya, dengan server yang berpura-pura hidup.
     *
     * Tanpa menukar Diagnostics lewat container, tes ini mustahil: pengembangan
     * dilayani `php artisan serve`, jadi serverRunning() selalu false dan
     * method reload() selalu berhenti di gerbang sebelum menulis apa pun.
     * Itulah kenapa reload() memakai app(Diagnostics::class), bukan `new`.
     */
    public function test_reloading_the_workers_reaches_the_audit_log(): void
    {
        $user = $this->reloader();

        $this->pretendTheServerIsRunning();

        $inspector = Mockery::mock(ServerProcessInspector::class);
        $inspector->shouldReceive('reloadServer')->once();
        $this->instance(ServerProcessInspector::class, $inspector);

        Livewire::test(Octane::class)
            ->assertActionEnabled('muatUlang')
            ->callAction('muatUlang');

        $activity = Activity::query()->where('log_name', 'octane')->sole();

        $this->assertSame('worker-dimuat-ulang', $activity->event);
        $this->assertSame($user->getKey(), $activity->causer_id);
    }

    /**
     * Event yang tersimpan tapi tidak ada di opsi filter tidak bisa disaring di
     * panel — jejaknya ada, dan tidak ada yang bisa menemukannya.
     */
    public function test_the_reload_event_can_be_filtered_in_the_panel(): void
    {
        // ViewAny:Activity is a separate permission from the Octane page, so
        // without it ListActivities aborts and the Livewire component never
        // mounts — a confusing "parseTableFilterName() on null".
        $this->reloader(['ViewAny:Activity']);

        $this->pretendTheServerIsRunning();

        $inspector = Mockery::mock(ServerProcessInspector::class);
        $inspector->shouldReceive('reloadServer')->once();
        $this->instance(ServerProcessInspector::class, $inspector);

        Livewire::test(Octane::class)->callAction('muatUlang');

        Livewire::test(ListActivities::class)
            ->filterTable('event', ['worker-dimuat-ulang'])
            ->assertCanSeeTableRecords(Activity::query()->where('log_name', 'octane')->get());
    }

    private function pretendTheServerIsRunning(): void
    {
        $this->app->instance(Diagnostics::class, new class extends Diagnostics
        {
            public function serverRunning(): bool
            {
                return true;
            }

            public function isFrankenPhp(): bool
            {
                return true;
            }
        });
    }

    /**
     * Kedua baris config ini **berbeda dari bawaan paket**, dan kembali ke
     * bawaan tidak menghasilkan error apa pun. Halaman ini satu-satunya tempat
     * di panel yang memperlihatkannya, jadi laporannya harus benar-benar
     * berubah — kalau tidak, halamannya melapor hijau sambil aplikasinya rusak.
     */
    public function test_the_config_defaults_that_break_correctness_are_reported_as_bad(): void
    {
        config([
            'permission.register_octane_reset_listener' => false,
            'octane.listeners.'.OperationTerminated::class => [],
            'octane.listeners.'.RequestReceived::class => [],
        ]);

        $checks = (new Diagnostics)->checks();

        $this->assertSame('bad', $checks['reset-izin']['status']);
        $this->assertSame('bad', $checks['putus-db']['status']);
        $this->assertSame('bad', $checks['sandbox-config']['status']);
        $this->assertSame('danger', (new Diagnostics)->verdict()['tone']);
    }

    public function test_the_current_configuration_is_reported_as_safe(): void
    {
        $checks = (new Diagnostics)->checks();

        $this->assertSame('ok', $checks['reset-izin']['status']);
        $this->assertSame('ok', $checks['putus-db']['status']);
        $this->assertSame('ok', $checks['sandbox-config']['status']);
    }

    /**
     * Tanpa state file, server tidak jalan — dan itu **keadaan normal** di
     * pengembangan, karena `composer run dev` memakai `php artisan serve`.
     *
     * Assert-nya `info`, bukan `bad`, dengan sengaja. Menandainya sebagai
     * kegagalan akan membuat halaman ini merah permanen di tiap laptop, dan
     * alarm palsu harian persis yang membuat orang berhenti membacanya —
     * kelas masalah yang sama dengan MaximumAgeMatchingSchedule.
     */
    public function test_a_stopped_server_is_not_reported_as_a_failure(): void
    {
        $diagnostics = new Diagnostics;

        $this->assertFalse($diagnostics->serverRunning());
        $this->assertFalse($diagnostics->stateFileIsStale());
        $this->assertSame('info', $diagnostics->checks()['jalan']['status']);
        $this->assertSame('success', $diagnostics->verdict()['tone']);
    }

    /**
     * State file yang menyebut master process sementara admin API menolak
     * koneksi berarti server mati tanpa membersihkan state file — keadaan yang
     * membuat `octane:start` melapor server sudah jalan.
     *
     * Boolean milik ServerProcessInspector melebur ini dengan "tidak jalan".
     * Memisahkannya adalah alasan utama halaman ini ada.
     */
    public function test_a_stale_state_file_is_told_apart_from_a_stopped_server(): void
    {
        [$host, $port] = $this->closedPort();

        file_put_contents($this->stateFile, json_encode([
            'masterProcessId' => 999999,
            'state' => ['adminHost' => $host, 'adminPort' => $port],
        ]));

        $diagnostics = new Diagnostics;

        $this->assertFalse($diagnostics->serverRunning());
        $this->assertTrue($diagnostics->stateFileIsStale());
        $this->assertSame('warn', $diagnostics->checks()['jalan']['status']);
        $this->assertStringContainsString($this->stateFile, $diagnostics->checks()['jalan']['catatan']);
    }

    /**
     * Port yang dijamin tertutup: bind ke port 0 supaya kernel memilih port
     * bebas, catat nomornya, lalu lepaskan. Menebak nomor port akan membuat
     * tesnya gagal di mesin yang kebetulan memakainya.
     *
     * @return array{0: string, 1: int}
     */
    private function closedPort(): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        $this->assertNotFalse($socket, "Tidak bisa membuka socket sementara: {$errstr}");

        $name = stream_socket_get_name($socket, false);
        $port = (int) substr((string) $name, strrpos((string) $name, ':') + 1);

        fclose($socket);

        return ['127.0.0.1', $port];
    }
}
