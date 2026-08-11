<?php

namespace App\Filament\Resources\AuthenticationMonitorings\Tables;

use App\Models\User;
use Binafy\LaravelUserMonitoring\Models\AuthenticationMonitoring;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AuthenticationMonitoringsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Waktu'))
                    ->dateTime('d M Y H:i:s')
                    ->description(fn (AuthenticationMonitoring $record) => $record->created_at?->diffForHumans())
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('Pengguna'))
                    ->placeholder(__('Tidak diketahui'))
                    ->description(fn (AuthenticationMonitoring $record) => $record->user?->email)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'user',
                        fn (Builder $query) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"),
                    )),
                TextColumn::make('action_type')
                    ->label(__('Aksi'))
                    ->badge()
                    // Only two values exist: the package listens to exactly
                    // Login and Logout. `failed` and `lockout` live in the
                    // activity log and have no counterpart here.
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'login' => __('Masuk'),
                        'logout' => __('Keluar'),
                        default => $state,
                    })
                    ->color(fn (?string $state) => match ($state) {
                        'login' => 'info',
                        'logout' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('ip')
                    ->label(__('IP'))
                    ->searchable(),
                TextColumn::make('browser_name')
                    ->label(__('Peramban'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('device')
                    ->label(__('Perangkat'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('user_guard')
                    ->label(__('Guard'))
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
                    // Hardcoded on purpose, same reasoning as the activity
                    // log's event filter: the set is fixed by what the package
                    // listens to, so reading it from the table would only hide
                    // `logout` until the first person logs out.
                    ->options([
                        'login' => __('Masuk'),
                        'logout' => __('Keluar'),
                    ]),
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
