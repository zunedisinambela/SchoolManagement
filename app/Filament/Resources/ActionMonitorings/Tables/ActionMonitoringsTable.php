<?php

namespace App\Filament\Resources\ActionMonitorings\Tables;

use App\Models\User;
use Binafy\LaravelUserMonitoring\Models\ActionMonitoring;
use Binafy\LaravelUserMonitoring\Utills\ActionType;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ActionMonitoringsTable
{
    /**
     * Indonesian labels for the package's six action types.
     *
     * Keyed off ActionType's constants rather than the literal strings so a
     * rename upstream turns into a missing label, which is visible, instead of
     * a filter option that silently matches nothing.
     *
     * @return array<string, string>
     */
    protected static function actionLabels(): array
    {
        return [
            ActionType::ACTION_READ => __('Dibaca'),
            ActionType::ACTION_STORE => __('Dibuat'),
            ActionType::ACTION_UPDATE => __('Diubah'),
            ActionType::ACTION_DELETE => __('Dihapus'),
            ActionType::ACTION_RESTORED => __('Dipulihkan'),
            ActionType::ACTION_REPLICATE => __('Diduplikasi'),
        ];
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            // Reached only when a model carries the Actionable trait, and none
            // does yet. Saying so beats an empty table that reads like a bug.
            ->emptyStateHeading(__('Belum ada aksi yang terpantau'))
            ->emptyStateDescription(__('Aksi baru tercatat setelah trait Actionable dipasang pada sebuah model.'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Waktu'))
                    ->dateTime('d M Y H:i:s')
                    ->description(fn (ActionMonitoring $record) => $record->created_at?->diffForHumans())
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('Pengguna'))
                    ->placeholder(__('Tamu'))
                    ->description(fn (ActionMonitoring $record) => $record->user?->email)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'user',
                        fn (Builder $query) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"),
                    )),
                TextColumn::make('action_type')
                    ->label(__('Aksi'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => static::actionLabels()[$state] ?? $state)
                    // The package model already maps each type to a colour, so
                    // the mapping stays in one place rather than being copied
                    // here and drifting from it.
                    ->color(fn (ActionMonitoring $record) => match ($record->getTypeColor()) {
                        'green' => 'success',
                        'purple', 'pink' => 'info',
                        'red' => 'danger',
                        'yellow' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('table_name')
                    ->label(__('Tabel'))
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('page')
                    ->label(__('Halaman'))
                    ->formatStateUsing(fn (?string $state) => $state ? '/'.ltrim((string) parse_url($state, PHP_URL_PATH), '/') : null)
                    ->tooltip(fn (?string $state) => $state)
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('ip')
                    ->label(__('IP'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('device')
                    ->label(__('Perangkat'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label(__('Pengguna'))
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                SelectFilter::make('action_type')
                    ->label(__('Aksi'))
                    ->multiple()
                    ->options(static::actionLabels()),
                SelectFilter::make('table_name')
                    ->label(__('Tabel'))
                    ->multiple()
                    ->options(fn () => ActionMonitoring::query()
                        ->distinct()
                        ->orderBy('table_name')
                        ->pluck('table_name', 'table_name')
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
            ]);
    }
}
