<?php

namespace App\Filament\Resources\Activities\Tables;

use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class ActivitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Waktu'))
                    ->dateTime('d M Y H:i:s')
                    ->description(fn ($record) => $record->created_at?->diffForHumans())
                    ->sortable(),
                TextColumn::make('causer.name')
                    ->label(__('Pelaku'))
                    ->placeholder(__('Sistem / tamu'))
                    ->description(fn ($record) => $record->causer?->email)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHasMorph(
                        'causer',
                        [User::class],
                        fn (Builder $query) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"),
                    )),
                TextColumn::make('event')
                    ->label(__('Aksi'))
                    ->badge()
                    ->placeholder('-')
                    ->color(fn (?string $state) => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        'login' => 'info',
                        'logout' => 'gray',
                        'failed', 'lockout' => 'danger',
                        'role-diberikan', 'izin-diberikan' => 'success',
                        'role-dicabut', 'izin-dicabut' => 'warning',
                        'backup-dijalankan' => 'info',
                        'backup-diunduh' => 'warning',
                        'backup-dihapus' => 'danger',
                        'password-arsip-diubah' => 'warning',
                        // Replaces the whole database with an archived copy.
                        // The only event here that cannot be undone.
                        'backup-dipulihkan' => 'danger',
                        'worker-dimuat-ulang' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('description')
                    ->label(__('Keterangan'))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('subject_type')
                    ->label(__('Objek'))
                    ->placeholder('-')
                    // Full class names are noise in a table; show Siswa, not App\Models\Siswa.
                    ->formatStateUsing(fn (?string $state, $record) => $state
                        ? Str::afterLast($state, '\\')." #{$record->subject_id}"
                        : null)
                    ->toggleable(),
                TextColumn::make('log_name')
                    ->label(__('Kanal'))
                    ->badge()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('causer_id')
                    ->label(__('Pelaku'))
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->query(fn (Builder $query, array $data) => $query->when(
                        $data['value'] ?? null,
                        fn (Builder $query, $causerId) => $query
                            ->where('causer_type', (new User)->getMorphClass())
                            ->where('causer_id', $causerId),
                    )),
                // Hardcoded rather than read from the table, so an event that
                // has never happened yet is still filterable. The cost is that
                // this list drifts: an event written by new code is stored but
                // cannot be filtered for, silently. Locked by
                // `test_every_event_the_app_writes_can_be_filtered`.
                SelectFilter::make('event')
                    ->label(__('Aksi'))
                    ->multiple()
                    ->options([
                        'created' => __('Dibuat'),
                        'updated' => __('Diubah'),
                        'deleted' => __('Dihapus'),
                        'login' => __('Masuk'),
                        'logout' => __('Keluar'),
                        'failed' => __('Gagal masuk'),
                        'lockout' => __('Terkunci'),
                        'role-diberikan' => __('Role diberikan'),
                        'role-dicabut' => __('Role dicabut'),
                        'izin-diberikan' => __('Izin diberikan'),
                        'izin-dicabut' => __('Izin dicabut'),
                        'backup-dijalankan' => __('Backup dijalankan'),
                        'backup-diunduh' => __('Backup diunduh'),
                        'backup-dihapus' => __('Backup dihapus'),
                        'password-arsip-diubah' => __('Password arsip diubah'),
                        'backup-dipulihkan' => __('Backup dipulihkan'),
                        'worker-dimuat-ulang' => __('Worker Octane dimuat ulang'),
                    ]),
                SelectFilter::make('log_name')
                    ->label(__('Kanal'))
                    ->multiple()
                    ->options(fn () => Activity::query()
                        ->distinct()
                        ->orderBy('log_name')
                        ->pluck('log_name', 'log_name')
                        ->filter()),
                Filter::make('created_at')
                    ->label(__('Rentang tanggal'))
                    ->schema([
                        DatePicker::make('from')->label(__('Dari tanggal')),
                        DatePicker::make('until')->label(__('Sampai tanggal')),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
