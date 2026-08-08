<?php

namespace App\Filament\Resources\Activities\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

class ActivityInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('Ringkasan'))
                    ->columns(3)
                    ->schema([
                        TextEntry::make('created_at')
                            ->label(__('Waktu'))
                            ->formatStateUsing(fn ($state) => $state?->translatedFormat('l, d F Y H:i:s')),
                        TextEntry::make('causer.name')
                            ->label(__('Pelaku'))
                            ->placeholder(__('Sistem / tamu'))
                            ->helperText(fn (Activity $record) => $record->causer?->email),
                        TextEntry::make('event')
                            ->label(__('Aksi'))
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('description')
                            ->label(__('Keterangan'))
                            ->columnSpanFull(),
                        TextEntry::make('subject_type')
                            ->label(__('Objek'))
                            ->placeholder('-')
                            ->formatStateUsing(fn (?string $state, Activity $record) => $state
                                ? Str::afterLast($state, '\\')." #{$record->subject_id}"
                                : null),
                        TextEntry::make('log_name')
                            ->label(__('Kanal'))
                            ->badge()
                            ->placeholder('-'),
                        TextEntry::make('batch_uuid')
                            ->label(__('Batch'))
                            ->placeholder('-')
                            ->copyable(),
                    ]),

                Section::make(__('Perubahan Data'))
                    ->visible(fn (Activity $record) => filled(static::changes($record)))
                    ->schema([
                        RepeatableEntry::make('perubahan')
                            ->hiddenLabel()
                            ->state(fn (Activity $record) => static::changes($record))
                            ->columns(3)
                            ->schema([
                                TextEntry::make('field')->label(__('Kolom')),
                                TextEntry::make('old')->label(__('Sebelum'))->placeholder('-'),
                                TextEntry::make('new')->label(__('Sesudah'))->placeholder('-'),
                            ]),
                    ]),

                Section::make(__('Properti Mentah'))
                    ->collapsed()
                    ->visible(fn (Activity $record) => filled($record->properties?->toArray()))
                    ->schema([
                        // CodeEntry would need the phiki syntax highlighter, which
                        // is not installed; pretty-printed JSON reads fine here.
                        TextEntry::make('properties')
                            ->hiddenLabel()
                            ->fontFamily(FontFamily::Mono)
                            ->formatStateUsing(fn ($state) => json_encode(
                                $state,
                                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                            ))
                            ->copyable(),
                    ]),
            ]);
    }

    /**
     * Flatten the stored `attributes` / `old` maps into one row per changed
     * field. Keys present on only one side (a create, or a delete) still get a
     * row, with the missing side left null.
     *
     * @return array<int, array{field: string, old: string|null, new: string|null}>
     */
    protected static function changes(Activity $record): array
    {
        $changes = $record->attribute_changes?->toArray() ?? [];

        $new = $changes['attributes'] ?? [];
        $old = $changes['old'] ?? [];

        $fields = array_unique([...array_keys($new), ...array_keys($old)]);
        sort($fields);

        return array_map(fn (string $field) => [
            'field' => $field,
            'old' => static::stringify($old[$field] ?? null),
            'new' => static::stringify($new[$field] ?? null),
        ], $fields);
    }

    protected static function stringify(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        };
    }
}
