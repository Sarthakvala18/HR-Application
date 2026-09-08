<?php

namespace App\Filament\Resources\RoleTemplates\Pages;

use App\Filament\Resources\RoleTemplates\RoleTemplateResource;
use Filament\Resources\Pages\ListRecords;

class ListRoleTemplates extends ListRecords
{
    protected static string $resource = RoleTemplateResource::class;

    public function getSubheading(): ?string
    {
        return 'What each role receives by default. Editing a template changes what future hires get, without a code change.';
    }
}
