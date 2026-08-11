<?php

namespace App\Filament\Resources\AuthenticationMonitorings;

use App\Filament\Resources\AuthenticationMonitorings\Pages\ListAuthenticationMonitorings;
use App\Filament\Resources\AuthenticationMonitorings\Tables\AuthenticationMonitoringsTable;
use BackedEnum;
use Binafy\LaravelUserMonitoring\Models\AuthenticationMonitoring;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Login dan logout, dengan peramban dan perangkatnya.
 *
 * **Tumpang tindih dengan Log Aktivitas, dan itu disengaja.** Kanal `auth` di
 * `activity_log` sudah mencatat `login`/`logout` — plus `failed` dan `lockout`
 * yang paket ini tidak punya sama sekali. Yang ditambahkan tabel ini cuma satu
 * hal: peramban dan perangkat yang sudah diurai, sementara `activity_log`
 * menyimpan user agent mentah di `properties`.
 *
 * Jadi tiap login sekarang menulis dua baris di dua tabel. Kalau itu tidak
 * sepadan, matikan lewat `authentication_monitoring.on_login`/`on_logout` di
 * config — Log Aktivitas tetap utuh, dan halaman ini tinggal kosong.
 * Kebalikannya tidak berlaku: mematikan kanal `auth` akan menghilangkan
 * `failed` dan `lockout` yang tidak ada penggantinya di sini.
 */
class AuthenticationMonitoringResource extends Resource
{
    protected static ?string $model = AuthenticationMonitoring::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'Pemantauan';

    protected static ?int $navigationSort = 93;

    public static function getModelLabel(): string
    {
        return __('Sesi Masuk');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Sesi Masuk');
    }

    public static function getNavigationLabel(): string
    {
        return __('Sesi Masuk');
    }

    public static function table(Table $table): Table
    {
        return AuthenticationMonitoringsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('ViewAny:AuthenticationMonitoring');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuthenticationMonitorings::route('/'),
        ];
    }
}
