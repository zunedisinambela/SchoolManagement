<?php

namespace App\Filament\Resources\ActionMonitorings;

use App\Filament\Resources\ActionMonitorings\Pages\ListActionMonitorings;
use App\Filament\Resources\ActionMonitorings\Tables\ActionMonitoringsTable;
use BackedEnum;
use Binafy\LaravelUserMonitoring\Models\ActionMonitoring;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Aksi CRUD per model, dan **halaman ini kosong sampai ada yang mengisinya**.
 *
 * Tidak seperti kunjungan dan sesi masuk, aksi tidak dicatat otomatis. Ia
 * butuh trait `Binafy\LaravelUserMonitoring\Traits\Actionable` dipasang di
 * model yang mau dipantau, dan saat ini **tidak ada satu pun model yang
 * memakainya** — jadi tabelnya ada, halamannya ada, isinya nol.
 *
 * Sebelum memasang trait itu di mana pun, sadari ia bertindih hampir sempurna
 * dengan `LogsActivity` milik spatie yang sudah dipakai `User`, `Role`,
 * `Permission`, dan `BackupSchedule`. Bedanya:
 *
 *   LogsActivity   menyimpan NILAI yang berubah (attributes + old), jadi bisa
 *                  menjawab "apa yang diubah".
 *   Actionable     menyimpan nama tabel, IP, peramban, dan perangkat, jadi
 *                  bisa menjawab "dari mana perubahannya datang".
 *
 * Memasang keduanya di satu model berarti dua baris di dua tabel untuk tiap
 * simpan. Itu sah kalau memang butuh kedua jawaban — tapi jangan dilakukan
 * karena mengira yang satu menggantikan yang lain.
 */
class ActionMonitoringResource extends Resource
{
    protected static ?string $model = ActionMonitoring::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Pemantauan';

    protected static ?int $navigationSort = 92;

    public static function getModelLabel(): string
    {
        return __('Aksi Data');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Aksi Data');
    }

    public static function getNavigationLabel(): string
    {
        return __('Aksi Data');
    }

    public static function table(Table $table): Table
    {
        return ActionMonitoringsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('ViewAny:ActionMonitoring');
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
            'index' => ListActionMonitorings::route('/'),
        ];
    }
}
