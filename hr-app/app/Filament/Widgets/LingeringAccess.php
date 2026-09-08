<?php

namespace App\Filament\Widgets;

use App\Enums\AccessStatus;
use App\Enums\EmployeeStatus;
use App\Models\AppAccess;
use App\Models\User;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Access still live for people who have already left.
 *
 * Deliberately placed above the fold: this is the list that costs money and
 * creates exposure, and the one manual offboarding forgets.
 */
class LingeringAccess extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Access held by people who have left';

    public static function canView(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->canRunProcesses()) {
            return false;
        }

        return static::baseQuery()->exists();
    }

    private static function baseQuery(): Builder
    {
        return AppAccess::query()
            ->with(['employee', 'app'])
            ->live()
            ->whereHas('employee', fn (Builder $q) => $q->where('status', EmployeeStatus::Exited));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => static::baseQuery())
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('employee.full_name')
                    ->label('Person')
                    ->description(fn (AppAccess $record) => $record->employee?->date_of_exit
                        ? 'Left '.$record->employee->date_of_exit->diffForHumans()
                        : null),

                TextColumn::make('app.name')->label('App')->badge()->color('danger'),

                TextColumn::make('license_tier')
                    ->label('Paid seat')
                    ->badge()
                    ->color(fn (AppAccess $record) => $record->isBillableSeat() ? 'warning' : 'gray')
                    ->placeholder('—'),

                TextColumn::make('granted_at')->dateTime('d M Y')->label('Granted')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Mark revoked')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (AppAccess $record) => $record->app?->isManual()
                        ? 'Revoke it in the console first, then record it here.'
                        : 'Records the revocation in this system.')
                    ->visible(fn (AppAccess $record) => Auth::user()?->can('revoke', $record) ?? false)
                    ->action(function (AppAccess $record) {
                        $record->update([
                            'status' => AccessStatus::Revoked,
                            'revoked_at' => now(),
                            'revoked_by' => Auth::id(),
                        ]);

                        app(AuditLogger::class)->log(
                            AuditLogger::ACCESS_REVOKED,
                            $record,
                            ['app' => $record->app?->key, 'via' => 'dashboard'],
                        );

                        Notification::make()->title('Recorded as revoked')->success()->send();
                    }),
            ])
            ->emptyStateHeading('Nothing outstanding');
    }
}
