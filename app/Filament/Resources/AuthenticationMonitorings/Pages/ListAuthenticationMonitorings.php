<?php

namespace App\Filament\Resources\AuthenticationMonitorings\Pages;

use App\Filament\Resources\AuthenticationMonitorings\AuthenticationMonitoringResource;
use Filament\Resources\Pages\ListRecords;

class ListAuthenticationMonitorings extends ListRecords
{
    protected static string $resource = AuthenticationMonitoringResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
