<?php

namespace App\Filament\Resources\ProcessRuns\Pages;

use App\Filament\Resources\ProcessRuns\ProcessRunResource;
use App\Models\ProcessRun;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProcessRuns extends ListRecords
{
    protected static string $resource = ProcessRunResource::class;

    public function getTitle(): string
    {
        return 'Onboarding and exits';
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Open')
                ->badge(fn () => ProcessRun::query()->open()->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->open()),

            'onboarding' => Tab::make('Onboarding')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('type', ProcessRun::TYPE_ONBOARDING)),

            'offboarding' => Tab::make('Exits')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('type', ProcessRun::TYPE_OFFBOARDING)),

            'completed' => Tab::make('Completed')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'completed')),

            'all' => Tab::make('All'),
        ];
    }
}
