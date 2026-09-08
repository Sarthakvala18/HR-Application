<?php

namespace App\Filament\Resources\FormSubmissions;

use App\Filament\Resources\FormSubmissions\Pages\ListFormSubmissions;
use App\Filament\Resources\FormSubmissions\Tables\FormSubmissionsTable;
use App\Models\FormSubmission;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class FormSubmissionResource extends Resource
{
    protected static ?string $model = FormSubmission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Import review';

    protected static ?string $modelLabel = 'submission';

    protected static string|\UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return FormSubmissionsTable::configure($table);
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && ($user->canRunProcesses() || $user->canSeeFinancials());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Bank submissions carry account numbers, so anyone without financial
     * access simply never sees those rows, not even in a count.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if ($user instanceof User && ! $user->canSeeFinancials()) {
            $query->where('form_key', '!=', FormSubmission::FORM_BANK);
        }

        return $query;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('review_status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Submissions awaiting review';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFormSubmissions::route('/'),
        ];
    }
}
