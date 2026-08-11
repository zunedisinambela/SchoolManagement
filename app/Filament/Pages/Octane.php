<?php

namespace App\Filament\Pages;

use App\Support\Octane\Diagnostics;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Laravel\Octane\FrankenPhp\ServerProcessInspector;

/**
 * Octane status board for the admin panel.
 *
 * Read-only apart from one action. Three things a page like this cannot do, and
 * the reasons are structural rather than missing work:
 *
 *   octane:start  A web request cannot spawn a process that outlives it. The
 *                 server has to come up under a supervisor.
 *   octane:stop   Would kill the server serving this very page, and nothing in
 *                 the panel could bring it back. Deliberately absent.
 *   octane:reload Safe, and the one action here: FrankenPHP reloads by PATCHing
 *                 Caddy's config onto itself, so requests in flight finish.
 *
 * Same shape as the Backups page and for the same reason: workers and config
 * are not Eloquent records, so the table is fed by `->records()` and every row
 * closure receives a plain array. Unlike Backups the row keys are ours, not
 * user input, so there is no resolve step to guard against — the keys never
 * reach the filesystem or a query.
 */
class Octane extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    /**
     * Between Backup (80) and Log Aktivitas (90), outside Manajemen Akses: this
     * is infrastructure, not something anyone is granted access *to*.
     */
    protected static ?int $navigationSort = 85;

    protected string $view = 'filament.pages.octane';

    /**
     * Not public, so Livewire never serializes it: the probe result must not
     * survive into the next request pretending to still be true.
     */
    protected ?Diagnostics $diagnostics = null;

    public static function getNavigationLabel(): string
    {
        return __('Octane');
    }

    public function getTitle(): string
    {
        return __('Octane');
    }

    /**
     * Gated on its own permission, like View:Backups. This page names the admin
     * API host and port, the state file path, and the master process id — the
     * shape of the server, which is not something every panel user inherits by
     * being let into the panel.
     */
    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('View:Octane');
    }

    public function getSubheading(): ?string
    {
        return $this->diagnostics()->verdict()['message'];
    }

    protected function getHeaderActions(): array
    {
        $diagnostics = $this->diagnostics();

        return [
            Action::make('muatUlang')
                ->label(__('Muat Ulang Worker'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalHeading(__('Muat ulang worker Octane?'))
                ->modalDescription(__('Worker menahan kode lama di memori. Muat ulang membuat request berikutnya memakai kode terbaru; request yang sedang berjalan tetap diselesaikan. Ini TIDAK memuat ulang queue worker — untuk itu jalankan php artisan queue:restart.'))
                ->modalSubmitActionLabel(__('Muat ulang'))
                // Hidden rather than disabled: there is no state in which a
                // viewer without the permission could earn it by doing
                // something else first. Same reasoning as Pulihkan.
                ->visible(fn (): bool => (bool) Filament::auth()->user()?->can('Reload:Octane'))
                // Guarding the button itself, not just the page. Filament
                // actions carry no automatic authorization — an unguarded
                // action really does run for anyone who can name it.
                ->disabled(fn (): bool => ! $diagnostics->serverRunning())
                ->tooltip(fn (): ?string => $diagnostics->serverRunning()
                    ? null
                    : __('Server Octane tidak jalan, jadi tidak ada worker untuk dimuat ulang.'))
                ->action(fn () => $this->reload()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => collect($this->diagnostics()->checks()))
            ->paginated(false)
            ->columns([
                TextColumn::make('nama')
                    ->label(__('Pemeriksaan'))
                    ->description(fn (array $record): ?string => $record['catatan']),
                TextColumn::make('nilai')
                    ->label(__('Nilai'))
                    ->badge()
                    ->color(fn (array $record): string => static::toneFor($record['status'])),
            ]);
    }

    /**
     * Runs the reload, re-checking everything it was already told at render
     * time. The disabled() above reflects a probe from when the page was drawn;
     * the server can go down between drawing and clicking.
     */
    protected function reload(): void
    {
        abort_unless((bool) Filament::auth()->user()?->can('Reload:Octane'), 403);

        // Resolved from the container, not `new`, so a test can swap in a
        // stub that reports a running server. Without that seam the logging
        // path below is unreachable in tests: development runs `artisan serve`,
        // so serverRunning() is always false and the guard always returns.
        // Not a singleton, so this is still a fresh probe — see the note above.
        $diagnostics = app(Diagnostics::class);

        if (! $diagnostics->isFrankenPhp()) {
            Notification::make()
                ->title(__('Server bukan FrankenPHP'))
                ->body(__('Muat ulang lewat panel hanya didukung untuk FrankenPHP. Server sekarang: :server.', ['server' => $diagnostics->serverName()]))
                ->danger()
                ->send();

            return;
        }

        if (! $diagnostics->serverRunning()) {
            Notification::make()
                ->title(__('Server Octane tidak jalan'))
                ->body(__('Tidak ada worker untuk dimuat ulang. Jalankan php artisan octane:start lewat supervisor.'))
                ->warning()
                ->send();

            return;
        }

        app(ServerProcessInspector::class)->reloadServer();

        activity('octane')
            ->causedBy(Filament::auth()->user())
            ->event('worker-dimuat-ulang')
            ->log(__('Memuat ulang worker Octane dari panel'));

        // "Dikirim", not "berhasil": reloadServer() swallows connection errors
        // and returns void, so the panel genuinely does not know the outcome.
        Notification::make()
            ->title(__('Perintah muat ulang dikirim'))
            ->body(__('Request berikutnya dilayani worker baru. Queue worker tidak ikut — jalankan php artisan queue:restart sendiri.'))
            ->success()
            ->send();
    }

    protected function diagnostics(): Diagnostics
    {
        // One instance per render so the admin API is probed once, not once per
        // closure that happens to ask whether the server is up.
        return $this->diagnostics ??= app(Diagnostics::class);
    }

    protected static function toneFor(string $status): string
    {
        return match ($status) {
            'ok' => 'success',
            'bad' => 'danger',
            'warn' => 'warning',
            default => 'gray',
        };
    }
}
