<?php

namespace App\Filament\Resources\VisitMonitorings\Tables;

use App\Models\User;
use Binafy\LaravelUserMonitoring\Models\VisitMonitoring;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VisitMonitoringsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('Waktu'))
                    ->dateTime('d M Y H:i:s')
                    ->description(fn (VisitMonitoring $record) => $record->created_at?->diffForHumans())
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label(__('Pengguna'))
                    // guest_mode is on, so a hit on /admin/login before anyone
                    // signs in lands here with no user attached. That row is
                    // the interesting one, not a defect.
                    ->placeholder(__('Tamu'))
                    ->description(fn (VisitMonitoring $record) => $record->user?->email)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas(
                        'user',
                        fn (Builder $query) => $query
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"),
                    )),
                TextColumn::make('page')
                    ->label(__('Halaman'))
                    // Stored as a full URL, and the host is the same on every
                    // row. Trimming it is what makes the column readable.
                    ->formatStateUsing(fn (?string $state) => $state ? '/'.ltrim((string) parse_url($state, PHP_URL_PATH), '/') : null)
                    ->tooltip(fn (?string $state) => $state)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('ip')
                    ->label(__('IP'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('browser_name')
                    ->label(__('Peramban'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                // `platform` deliberately absent. The package writes
                // getDevice() into both `device` and `platform`, so the two
                // columns always hold the same string — a second copy of the
                // same fact reads like extra information and is not.
                TextColumn::make('device')
                    ->label(__('Perangkat'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('user_id')
                    ->label(__('Pengguna'))
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                SelectFilter::make('browser_name')
                    ->label(__('Peramban'))
                    ->multiple()
                    // Read from the table rather than hardcoded, unlike the
                    // activity log's event filter: this list comes from
                    // whatever browsers actually showed up, and there is no
                    // fixed set the application itself writes.
                    ->options(fn () => VisitMonitoring::query()
                        ->distinct()
                        ->orderBy('browser_name')
                        ->pluck('browser_name', 'browser_name')
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
