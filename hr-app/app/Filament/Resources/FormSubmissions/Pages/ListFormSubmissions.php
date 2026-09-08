<?php

namespace App\Filament\Resources\FormSubmissions\Pages;

use App\Filament\Resources\FormSubmissions\FormSubmissionResource;
use App\Models\FormSubmission;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListFormSubmissions extends ListRecords
{
    protected static string $resource = FormSubmissionResource::class;

    public function getTitle(): string
    {
        return 'Import review queue';
    }

    public function getSubheading(): ?string
    {
        return 'Imported form submissions awaiting confirmation. Nothing here has been applied to a person yet.';
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pending')
                ->badge(fn () => static::getResource()::getEloquentQuery()
                    ->where('review_status', 'pending')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query->where('review_status', 'pending')),

            'flagged' => Tab::make('Flagged')
                ->badge(fn () => static::getResource()::getEloquentQuery()
                    ->where('review_status', 'pending')->whereNotNull('issues')->count())
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('review_status', 'pending')
                    ->whereNotNull('issues')),

            'bank' => Tab::make('Payout details')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('form_key', FormSubmission::FORM_BANK)),

            'quarantined' => Tab::make('Quarantined')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('review_status', 'quarantined')),

            'accepted' => Tab::make('Accepted')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('review_status', 'accepted')),

            'all' => Tab::make('All'),
        ];
    }
}
