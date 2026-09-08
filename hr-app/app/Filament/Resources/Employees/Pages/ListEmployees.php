<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Enums\EmployeeStatus;
use App\Filament\Resources\Employees\EmployeeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add person'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'current' => Tab::make('Current')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [
                    EmployeeStatus::Active->value,
                    EmployeeStatus::OnNotice->value,
                ])),

            'joining' => Tab::make('Joining')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', EmployeeStatus::PreOnboarding)),

            'leaving' => Tab::make('Leaving')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', EmployeeStatus::Offboarding)),

            'exited' => Tab::make('Exited')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', EmployeeStatus::Exited)),

            'all' => Tab::make('All'),
        ];
    }
}
