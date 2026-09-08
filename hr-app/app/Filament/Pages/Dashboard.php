<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\HrOverview;
use App\Filament\Widgets\LingeringAccess;
use App\Filament\Widgets\UpcomingCelebrations;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getTitle(): string
    {
        return 'HR dashboard';
    }

    public function getWidgets(): array
    {
        return [
            HrOverview::class,
            LingeringAccess::class,
            UpcomingCelebrations::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
