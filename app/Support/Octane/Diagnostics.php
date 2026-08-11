<?php

namespace App\Support\Octane;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Octane\Contracts\OperationTerminated;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Listeners\CreateConfigurationSandbox;
use Laravel\Octane\Listeners\DisconnectFromDatabases;

/**
 * Read-only view of how Octane is configured and whether it is actually up.
 *
 * Deliberately free of Filament: every method here is a plain question with a
 * plain answer, so the page below is only a rendering of it and the test suite
 * never has to boot Livewire to assert on the interesting parts.
 *
 * Nothing here mutates anything. The one action that does — reloading workers —
 * lives on the page, because it needs the panel's authorization and audit log.
 *
 * Severity vocabulary used by checks():
 *
 *   ok    the value is what this repo needs
 *   bad   the value silently breaks correctness — see CLAUDE.md, "Dua default
 *         paket yang diubah". Never used for runtime state.
 *   info  runtime state, not a verdict. "Server tidak jalan" is the normal
 *         answer in development, where `composer run dev` runs `artisan serve`.
 *   warn  works, but a documented trap is waiting
 *
 * That split is the whole point: a page that reported "unhealthy" every time
 * someone opened it on their laptop would be the same false-alarm pathology
 * that MaximumAgeMatchingSchedule exists to avoid for backups.
 */
class Diagnostics
{
    /**
     * Memoized so one page render probes the admin API at most once. Not cached
     * across requests on purpose — a stale "server is up" is worse than a
     * second socket connect, and this page has no polling.
     */
    private ?bool $serverRunning = null;

    public function serverName(): string
    {
        return (string) config('octane.server');
    }

    public function isFrankenPhp(): bool
    {
        return $this->serverName() === 'frankenphp';
    }

    /**
     * Whether the request rendering this page is itself being served by an
     * Octane worker.
     *
     * The start commands inject LARAVEL_OCTANE=1 into the server process, so
     * workers inherit it. Read straight from the superglobals rather than only
     * through env(): with a cached config Laravel skips loading .env entirely,
     * and a real process variable is still there when it does.
     */
    public function servedByOctane(): bool
    {
        return (bool) ($_SERVER['LARAVEL_OCTANE'] ?? $_ENV['LARAVEL_OCTANE'] ?? env('LARAVEL_OCTANE'));
    }

    public function binaryPath(): string
    {
        return base_path('frankenphp');
    }

    public function binaryPresent(): bool
    {
        return is_executable($this->binaryPath());
    }

    public function stateFilePath(): string
    {
        return (string) config('octane.state_file', storage_path('logs/octane-server-state.json'));
    }

    /**
     * @return array{exists: bool, masterProcessId: int|null, adminHost: string, adminPort: int}
     */
    public function state(): array
    {
        $path = $this->stateFilePath();

        $raw = is_readable($path)
            ? (json_decode((string) file_get_contents($path), true) ?: [])
            : [];

        return [
            'exists' => is_readable($path),
            'masterProcessId' => isset($raw['masterProcessId']) ? (int) $raw['masterProcessId'] : null,
            // Same fallbacks the package uses when the state file predates
            // these keys, so the URL we probe is the URL octane:status probes.
            'adminHost' => (string) ($raw['state']['adminHost'] ?? 'localhost'),
            'adminPort' => (int) ($raw['state']['adminPort'] ?? 2019),
        ];
    }

    public function serverRunning(): bool
    {
        return $this->serverRunning ??= $this->probe();
    }

    /**
     * True when the state file names a master process but the admin API refuses
     * the connection: the server died without cleaning up after itself.
     *
     * Worth separating from a plain "not running" because the fix differs — a
     * leftover state file makes octane:start report an already-running server.
     */
    public function stateFileIsStale(): bool
    {
        return $this->state()['masterProcessId'] !== null && ! $this->serverRunning();
    }

    /**
     * Reimplements ServerProcessInspector::serverIsRunning() for one reason:
     * the package calls Http::get() with no timeout, so a firewalled admin port
     * would hang this page for the default 30 seconds. Everything else about
     * the check is identical, including the URL.
     */
    private function probe(): bool
    {
        $state = $this->state();

        if ($state['masterProcessId'] === null) {
            return false;
        }

        // Cheap gate before any HTTP client work: a closed port answers in
        // microseconds, and a dropped packet gives up after 0.3s instead of 30.
        $socket = @fsockopen($state['adminHost'], $state['adminPort'], $errno, $errstr, 0.3);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        try {
            return Http::connectTimeout(1)
                ->timeout(2)
                ->get($this->adminConfigUrl())
                ->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function adminConfigUrl(): string
    {
        $state = $this->state();

        return "http://{$state['adminHost']}:{$state['adminPort']}/config/apps/frankenphp";
    }

    public function resetsPermissionCache(): bool
    {
        return (bool) config('permission.register_octane_reset_listener');
    }

    public function disconnectsFromDatabases(): bool
    {
        return in_array(
            DisconnectFromDatabases::class,
            (array) config('octane.listeners.'.OperationTerminated::class, []),
            strict: true,
        );
    }

    public function sandboxesConfiguration(): bool
    {
        return in_array(
            CreateConfigurationSandbox::class,
            (array) config('octane.listeners.'.RequestReceived::class, []),
            strict: true,
        );
    }

    /**
     * chokidar is what `octane:start --watch` shells out to. Without it, saved
     * files are not picked up until workers are reloaded by hand — a symptom
     * that reads like a caching bug rather than a missing npm package.
     */
    public function watcherInstalled(): bool
    {
        return is_dir(base_path('node_modules/chokidar'));
    }

    /**
     * One row per diagnostic, keyed so the Filament table has stable row keys.
     *
     * @return array<string, array{nama: string, nilai: string, status: string, catatan: string|null}>
     */
    public function checks(): array
    {
        $state = $this->state();

        return [
            'server' => [
                'nama' => __('Server aplikasi'),
                'nilai' => $this->serverName(),
                'status' => $this->isFrankenPhp() ? 'ok' : 'warn',
                'catatan' => $this->isFrankenPhp()
                    ? null
                    : __('Bawaan config-nya roadrunner, dan binernya tidak terpasang di repo ini. Setel OCTANE_SERVER=frankenphp di .env.'),
            ],

            'biner' => [
                'nama' => __('Biner FrankenPHP'),
                'nilai' => $this->binaryPresent() ? __('ada') : __('tidak ada'),
                'status' => $this->binaryPresent() ? 'ok' : 'warn',
                'catatan' => $this->binaryPresent()
                    ? $this->binaryPath()
                    : __('Binernya di-gitignore, jadi git pull tidak membawanya. Jalankan php artisan octane:install --server=frankenphp.'),
            ],

            'jalan' => [
                'nama' => __('Server sedang jalan'),
                'nilai' => $this->serverRunning() ? __('ya') : __('tidak'),
                // Runtime state, never 'bad': in development this repo is
                // served by `php artisan serve`, so "tidak" is the right answer
                // and must not read as a failure.
                'status' => $this->serverRunning() ? 'ok' : ($this->stateFileIsStale() ? 'warn' : 'info'),
                'catatan' => match (true) {
                    $this->serverRunning() => __('Dicek lewat admin API di :url', ['url' => $this->adminConfigUrl()]),
                    $this->stateFileIsStale() => __('State file menyebut PID :pid tapi admin API menolak koneksi — server mati tanpa membersihkan state file. Hapus :berkas sebelum start ulang.', [
                        'pid' => $state['masterProcessId'],
                        'berkas' => $this->stateFilePath(),
                    ]),
                    default => __('Tidak ada state file. Normal saat aplikasi dijalankan dengan php artisan serve.'),
                },
            ],

            'dilayani' => [
                'nama' => __('Request ini dilayani Octane'),
                'nilai' => $this->servedByOctane() ? __('ya') : __('tidak'),
                'status' => 'info',
                'catatan' => $this->servedByOctane()
                    ? __('Worker menahan aplikasi di memori. Kode baru butuh muat ulang worker.')
                    : __('Dilayani php artisan serve atau php-fpm. Tiap request boot ulang, jadi tidak ada state yang bertahan.'),
            ],

            'reset-izin' => [
                'nama' => __('Reset cache izin tiap operasi'),
                'nilai' => $this->resetsPermissionCache() ? 'true' : 'false',
                'status' => $this->resetsPermissionCache() ? 'ok' : 'bad',
                'catatan' => $this->resetsPermissionCache()
                    ? __('permission.register_octane_reset_listener')
                    : __('Izin yang dicabut lewat panel tetap berlaku di worker yang sudah memuatnya, sampai worker didaur ulang max_requests. Tanpa error apa pun.'),
            ],

            'putus-db' => [
                'nama' => __('Putus koneksi database tiap operasi'),
                'nilai' => $this->disconnectsFromDatabases() ? 'true' : 'false',
                'status' => $this->disconnectsFromDatabases() ? 'ok' : 'bad',
                'catatan' => $this->disconnectsFromDatabases()
                    ? __('DisconnectFromDatabases pada OperationTerminated')
                    : __('Tombol Pulihkan menukar database lewat rename(). Koneksi yang bertahan tetap memegang inode lama, jadi worker menyajikan database sebelum-restore.'),
            ],

            'sandbox-config' => [
                'nama' => __('Sandbox config tiap request'),
                'nilai' => $this->sandboxesConfiguration() ? 'true' : 'false',
                'status' => $this->sandboxesConfiguration() ? 'ok' : 'bad',
                'catatan' => $this->sandboxesConfiguration()
                    ? __('CreateConfigurationSandbox pada RequestReceived')
                    : __('config([...]) saat runtime akan bocor ke request berikutnya. BackupSchedule::applyArchivePassword() menyetel password arsip lewat jalur itu.'),
            ],

            'watcher' => [
                'nama' => __('Watcher pengembangan (chokidar)'),
                'nilai' => $this->watcherInstalled() ? __('terpasang') : __('tidak terpasang'),
                'status' => 'info',
                'catatan' => $this->watcherInstalled()
                    ? __('octane:start --watch bisa dipakai.')
                    : __('Tanpa ini, --watch tidak membaca perubahan berkas dan browser menampilkan kode lama. npm install chokidar.'),
            ],

            'garbage' => [
                'nama' => __('Ambang garbage collection'),
                'nilai' => __(':mb MB', ['mb' => (int) config('octane.garbage')]),
                'status' => 'info',
                'catatan' => __('Memori worker yang tumbuh terus melewati ambang ini hampir selalu state yang menumpuk di singleton, bukan Octane-nya.'),
            ],

            'max-exec' => [
                'nama' => __('Batas waktu eksekusi'),
                'nilai' => __(':detik detik', ['detik' => (int) config('octane.max_execution_time')]),
                'status' => 'info',
                'catatan' => __('Berlaku per request di dalam worker.'),
            ],
        ];
    }

    /**
     * One-line summary shown as the page subheading.
     *
     * Aggregation only: severity per row is decided in checks(). Kept separate
     * so the rule for "is this installation in trouble" can change without
     * touching a single check.
     *
     * @return array{tone: string, message: string}
     */
    public function verdict(): array
    {
        $checks = $this->checks();

        $broken = array_filter($checks, fn (array $check): bool => $check['status'] === 'bad');

        if ($broken !== []) {
            return [
                'tone' => 'danger',
                'message' => __(':jumlah baris config mengembalikan Octane ke bawaan paket yang tidak aman di repo ini.', [
                    'jumlah' => count($broken),
                ]),
            ];
        }

        // TODO(kamu): escalation berbasis environment — lihat catatan di bawah.
        return [
            'tone' => 'success',
            'message' => $this->serverRunning()
                ? __('Config aman, server jalan.')
                : __('Config aman. Server tidak jalan — normal di pengembangan.'),
        ];
    }
}
