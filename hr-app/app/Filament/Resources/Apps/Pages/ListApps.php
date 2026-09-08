<?php

namespace App\Filament\Resources\Apps\Pages;

use App\Filament\Resources\Apps\AppResource;
use Filament\Resources\Pages\ListRecords;

class ListApps extends ListRecords
{
    protected static string $resource = AppResource::class;

    public function getSubheading(): ?string
    {
        return 'Systems we grant access to. Manual apps produce a guided task card; automated apps are provisioned by API.';
    }
}
