<?php

namespace App\Filament\Pages;

use App\Enums\BackupFrequency;
use App\Enums\Permission;
use App\Jobs\RunBackup;
use App\Models\BackupSchedule;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backup archive browser for the admin panel.
 *
 * Not a Resource: backups are files on a disk, not Eloquent records, so there
 * is no model, no id, and nothing to query. Filament's table is fed by
 * `->records()` instead of `->query()`, which hands each row to the closures
 * below as a plain array rather than a Model.
 *
 * That difference is the source of the one real hazard here, and why
 * `resolveBackup()` exists — see its docblock.
 */
class Backups extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 80;

    protected string $view = 'filament.pages.backups';

    public static function getNavigationLabel(): string
    {
        return __('Backup');
    }

    public function getTitle(): string
    {
        return __('Backup');
    }

    /**
     * Gated on its own permission, separate from `akses-panel-admin`. Reading
     * this page means seeing when the database was last captured and being one
     * click from downloading all of it, which is not something every panel
     * user should inherit just by being let into the panel.
     */
    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can(Permission::KelolaBackup->value);
    }

    public function getSubheading(): ?string
    {
        $destination = $this->destination();

        if (! $destination->isReachable()) {
            return __('Disk :disk tidak bisa dibaca.', ['disk' => $destination->diskName()]);
        }

        $jadwal = BackupSchedule::current()->describe();

        $backups = $destination->backups();
        $newest = $backups->newest();

        if (! $newest) {
            return __('Belum ada arsip. Tekan "Backup Sekarang" untuk membuat yang pertama.').' '.$jadwal;
        }

        return __(':jumlah arsip, :ukuran terpakai. Terbaru :waktu.', [
            'jumlah' => $backups->count(),
            'ukuran' => Number::fileSize($destination->usedStorage(), precision: 2),
            'waktu' => $newest->date()->diffForHumans(),
        ]).' '.$jadwal;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ubahJadwal')
                ->label(__('Ubah Jadwal'))
                ->icon(Heroicon::OutlinedClock)
                ->modalHeading(__('Jadwal backup otomatis'))
                ->modalSubmitActionLabel(__('Simpan'))
                ->fillForm(fn (): array => $this->scheduleFormData())
                ->schema([
                    Toggle::make('is_enabled')
                        ->label(__('Backup otomatis aktif'))
                        ->helperText(__('Kalau dimatikan, arsip hanya dibuat lewat tombol "Backup Sekarang".'))
                        ->live(),

                    Select::make('frequency')
                        ->label(__('Frekuensi'))
                        ->options(BackupFrequency::options())
                        ->required()
                        ->live()
                        ->visible(fn (Get $get): bool => (bool) $get('is_enabled')),

                    Select::make('day_of_week')
                        ->label(__('Hari'))
                        ->options(BackupSchedule::dayOfWeekOptions())
                        ->required()
                        ->visible(fn (Get $get): bool => (bool) $get('is_enabled')
                            && $get('frequency') === BackupFrequency::Mingguan->value),

                    Select::make('day_of_month')
                        ->label(__('Tanggal'))
                        // Stops at 28 on purpose: a backup set for the 30th
                        // would skip February every year, and the symptom is a
                        // month with no archive rather than an error.
                        ->options(array_combine(range(1, 28), range(1, 28)))
                        ->helperText(__('Maksimal tanggal 28 supaya tidak terlewat di bulan Februari.'))
                        ->required()
                        ->visible(fn (Get $get): bool => (bool) $get('is_enabled')
                            && $get('frequency') === BackupFrequency::Bulanan->value),

                    TimePicker::make('time')
                        ->label(__('Jam'))
                        ->seconds(false)
                        ->required()
                        ->helperText(__('Zona waktu :zona.', ['zona' => config('app.timezone')]))
                        ->visible(fn (Get $get): bool => (bool) $get('is_enabled')),
                ])
                ->action(fn (array $data) => $this->saveSchedule($data)),

            Action::make('backupSekarang')
                ->label(__('Backup Sekarang'))
                ->icon(Heroicon::OutlinedPlusCircle)
                ->requiresConfirmation()
                ->modalHeading(__('Jalankan backup sekarang?'))
                ->modalDescription(__('Backup diproses di latar belakang. Arsip baru muncul di daftar setelah selesai.'))
                ->action(function (): void {
                    RunBackup::dispatch();

                    activity('backup')
                        ->causedBy(Filament::auth()->user())
                        ->event('backup-dijalankan')
                        ->log(__('Menjalankan backup manual dari panel'));

                    Notification::make()
                        ->title(__('Backup sedang diproses'))
                        ->body(__('Muat ulang halaman ini sebentar lagi untuk melihat arsipnya.'))
                        ->success()
                        ->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => $this->records())
            ->emptyStateHeading(__('Belum ada arsip backup'))
            ->emptyStateDescription(__('Arsip dibuat otomatis tiap hari pukul 01:30, atau lewat tombol "Backup Sekarang".'))
            ->emptyStateIcon(Heroicon::OutlinedArchiveBox)
            ->columns([
                TextColumn::make('nama')
                    ->label(__('Berkas'))
                    ->description(fn (array $record): string => $record['tanggal']->translatedFormat('l, d F Y H:i')),
                TextColumn::make('umur')
                    ->label(__('Umur'))
                    ->badge()
                    ->color(fn (array $record): string => $record['terbaru'] ? 'success' : 'gray'),
                TextColumn::make('ukuran')
                    ->label(__('Ukuran')),
            ])
            ->recordActions([
                Action::make('unduh')
                    ->label(__('Unduh'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->action(fn (array $record): StreamedResponse => $this->download($record)),

                Action::make('hapus')
                    ->label(__('Hapus'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(__('Hapus arsip backup?'))
                    ->modalDescription(__('Arsip yang dihapus tidak bisa dikembalikan.'))
                    // Guarding the button itself, not just the page: Filament
                    // actions carry no automatic authorization, so an unguarded
                    // button really would delete the record. Same trap the user
                    // and role resources document at length.
                    ->disabled(fn (array $record): bool => $record['terbaru'])
                    ->tooltip(fn (array $record): ?string => $record['terbaru']
                        ? __('Arsip terbaru tidak bisa dihapus — ini titik pemulihan terakhir yang tersisa.')
                        : null)
                    ->action(fn (array $record) => $this->delete($record)),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function scheduleFormData(): array
    {
        $schedule = BackupSchedule::current();

        return [
            'is_enabled' => $schedule->is_enabled,
            'frequency' => $schedule->frequency->value,
            'day_of_week' => $schedule->day_of_week ?? 0,
            'day_of_month' => $schedule->day_of_month ?? 1,
            'time' => $schedule->time(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function saveSchedule(array $data): void
    {
        $frequency = BackupFrequency::from($data['frequency'] ?? BackupFrequency::Mingguan->value);

        [$hour, $minute] = array_pad(explode(':', (string) ($data['time'] ?? '01:30')), 2, '0');

        BackupSchedule::current()->update([
            'is_enabled' => (bool) ($data['is_enabled'] ?? false),
            'frequency' => $frequency,
            // Cleared rather than kept when the frequency does not use them.
            // A stale day_of_week left behind on a monthly schedule is a value
            // that shows the wrong thing the next time the form is opened.
            'day_of_week' => $frequency->usesDayOfWeek() ? (int) $data['day_of_week'] : null,
            'day_of_month' => $frequency->usesDayOfMonth() ? (int) $data['day_of_month'] : null,
            'hour' => (int) $hour,
            'minute' => (int) $minute,
        ]);

        Notification::make()
            ->title(__('Jadwal disimpan'))
            ->body(BackupSchedule::current()->refresh()->describe())
            ->success()
            ->send();
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    protected function records(): Collection
    {
        $destination = $this->destination();

        if (! $destination->isReachable()) {
            return collect();
        }

        $newestPath = $destination->newestBackup()?->path();

        return collect($destination->backups()->all())
            ->sortByDesc(fn (Backup $backup): int => $backup->date()->getTimestamp())
            ->mapWithKeys(fn (Backup $backup): array => [
                $backup->path() => [
                    'nama' => basename($backup->path()),
                    'tanggal' => $backup->date(),
                    'umur' => $backup->date()->diffForHumans(),
                    'ukuran' => Number::fileSize($backup->sizeInBytes(), precision: 2),
                    'terbaru' => $backup->path() === $newestPath,
                ],
            ]);
    }

    /**
     * Turn a table row back into a real backup file.
     *
     * The array key is the archive path, and Filament round-trips that key
     * through the browser on every action call — meaning it arrives back as
     * user input. Treating it as a path directly would be a traversal hole:
     * `../../../.env` would download the credentials this backup config goes
     * out of its way to keep out of the archives.
     *
     * So the key is never used as a path. It is compared against the paths the
     * disk actually reports, and only a match is acted on. A forged key
     * matches nothing and 404s.
     */
    protected function resolveBackup(array $record): Backup
    {
        $path = $this->getTableRecordKey($record);

        return collect($this->destination()->backups()->all())
            ->first(fn (Backup $backup): bool => $backup->path() === $path)
            ?? abort(404);
    }

    protected function download(array $record): StreamedResponse
    {
        $backup = $this->resolveBackup($record);

        activity('backup')
            ->causedBy(Filament::auth()->user())
            ->withProperties([
                'berkas' => basename($backup->path()),
                'ukuran' => $backup->sizeInBytes(),
            ])
            ->event('backup-diunduh')
            ->log(__('Mengunduh arsip backup :berkas', ['berkas' => basename($backup->path())]));

        return response()->streamDownload(
            function () use ($backup): void {
                $stream = $backup->stream();
                fpassthru($stream);

                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            basename($backup->path()),
        );
    }

    protected function delete(array $record): void
    {
        $backup = $this->resolveBackup($record);

        // The disabled() guard above stops the button, but this method is
        // reachable on its own, and "the newest archive" can change between
        // page render and click. Re-checked here against live state.
        if ($backup->path() === $this->destination()->newestBackup()?->path()) {
            Notification::make()
                ->title(__('Arsip terbaru tidak bisa dihapus'))
                ->danger()
                ->send();

            return;
        }

        $name = basename($backup->path());

        $backup->delete();

        activity('backup')
            ->causedBy(Filament::auth()->user())
            ->withProperties(['berkas' => $name])
            ->event('backup-dihapus')
            ->log(__('Menghapus arsip backup :berkas', ['berkas' => $name]));

        Notification::make()
            ->title(__('Arsip dihapus'))
            ->success()
            ->send();
    }

    /**
     * Only the first configured disk is browsed. Extra disks (an S3 copy, for
     * instance) exist so a backup survives this machine dying — reading them
     * back through the panel would mean a slow remote listing on every page
     * load for a case that is handled off-panel anyway.
     */
    protected function destination(): BackupDestination
    {
        return BackupDestination::create(
            config('backup.backup.destination.disks')[0],
            config('backup.backup.name'),
        )->fresh();
    }
}
