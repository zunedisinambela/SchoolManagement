<?php

namespace App\Filament\Resources\VisitMonitorings\Pages;

use App\Filament\Resources\VisitMonitorings\VisitMonitoringResource;
use Filament\Resources\Pages\ListRecords;

class ListVisitMonitorings extends ListRecords
{
    protected static string $resource = VisitMonitoringResource::class;

    /**
     * No header actions: rows are written by the visit middleware, never by
     * hand, and never removed from here.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
