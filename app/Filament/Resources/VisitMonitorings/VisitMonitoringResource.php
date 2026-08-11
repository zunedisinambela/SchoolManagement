<?php

namespace App\Filament\Resources\VisitMonitorings;

use App\Filament\Resources\VisitMonitorings\Pages\ListVisitMonitorings;
use App\Filament\Resources\VisitMonitorings\Tables\VisitMonitoringsTable;
use BackedEnum;
use Binafy\LaravelUserMonitoring\Models\VisitMonitoring;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Halaman kunjungan, menggantikan UI bawaan paket.
 *
 * binafy/laravel-user-monitoring membawa halamannya sendiri di
 * /user-monitoring/visits-monitoring, dan halaman itu berjalan dengan
 * middleware `web` saja — tanpa login, tanpa izin, dengan tombol hapus yang
 * sama terbukanya. Rutenya dimatikan lewat routes/user-monitoring.php dan
 * digantikan resource ini, yang tunduk pada izin shield seperti resource lain.
 *
 * Read-only, alasannya sama dengan ActivityResource: catatan kunjungan yang
 * bisa dihapus dari dalam panel adalah catatan yang bisa dihapus oleh orang
 * yang kunjungannya sedang dicatat. Pembersihannya lewat perintah terjadwal,
 * bukan tombol.
 */
class VisitMonitoringResource extends Resource
{
    protected static ?string $model = VisitMonitoring::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCursorArrowRays;

    protected static string|UnitEnum|null $navigationGroup = 'Pemantauan';

    protected static ?int $navigationSort = 91;

    public static function getModelLabel(): string
    {
        return __('Kunjungan');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Kunjungan');
    }

    public static function getNavigationLabel(): string
    {
        return __('Kunjungan');
    }

    public static function table(Table $table): Table
    {
        return VisitMonitoringsTable::configure($table);
    }

    /**
     * Eager load the user so the table does not fire a query per row. The
     * relation is nullable — `guest_mode` is on, so a hit on the login page
     * before anyone signs in is recorded with no user at all.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('ViewAny:VisitMonitoring');
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
            'index' => ListVisitMonitorings::route('/'),
        ];
    }
}
