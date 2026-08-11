<?php

namespace App\Filament\Resources\ActionMonitorings\Pages;

use App\Filament\Resources\ActionMonitorings\ActionMonitoringResource;
use Filament\Resources\Pages\ListRecords;

class ListActionMonitorings extends ListRecords
{
    protected static string $resource = ActionMonitoringResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
