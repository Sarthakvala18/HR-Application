<?php

namespace App\Filament\Resources\ProcessRuns;

use App\Filament\Resources\ProcessRuns\Pages\ListProcessRuns;
use App\Filament\Resources\ProcessRuns\Pages\ViewProcessRun;
use App\Filament\Resources\ProcessRuns\RelationManagers\TasksRelationManager;
use App\Models\ProcessRun;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ProcessRunResource extends Resource
{
    protected static ?string $model = ProcessRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected static ?string $navigationLabel = 'Onboarding & exits';

    protected static ?string $modelLabel = 'run';

    protected static string|\UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 4;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->canRunProcesses();
    }

    public static function canCreate(): bool
    {
        // Runs are started from a person, so the employee is never ambiguous.
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('employee.full_name')->label('Person'),
                // Filament injects closure arguments by parameter name, so this
                // must be $state rather than any shorter alias.
                TextEntry::make('type')->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextEntry::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'completed' => 'success',
                        'blocked' => 'danger',
                        'cancelled' => 'gray',
                        default => 'warning',
                    }),
                TextEntry::make('progress')
                    ->label('Progress')
                    ->state(fn (ProcessRun $record) => $record->progressPercent().'%'),
                TextEntry::make('initiatedBy.name')->label('Started by')->placeholder('—'),
                TextEntry::make('started_at')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('completed_at')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('is_urgent')
                    ->label('Urgent')
                    ->badge()
                    ->state(fn (ProcessRun $record) => $record->is_urgent ? 'Immediate termination' : 'Planned')
                    ->color(fn (ProcessRun $record) => $record->is_urgent ? 'danger' : 'gray'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('employee.full_name')
                    ->label('Person')
                    ->searchable()
                    ->description(fn (ProcessRun $record) => $record->employee?->position),

                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => $state === ProcessRun::TYPE_OFFBOARDING ? 'danger' : 'info'),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'completed' => 'success',
                        'blocked' => 'danger',
                        'cancelled' => 'gray',
                        default => 'warning',
                    }),

                TextColumn::make('progress')
                    ->label('Progress')
                    ->state(fn (ProcessRun $record) => $record->progressPercent().'%')
                    ->badge()
                    ->color(fn (ProcessRun $record) => $record->progressPercent() === 100 ? 'success' : 'gray'),

                TextColumn::make('outstanding')
                    ->label('Left to do')
                    ->state(fn (ProcessRun $record) => $record->outstandingTasks()->count()),

                TextColumn::make('is_urgent')
                    ->label('')
                    ->badge()
                    ->color('danger')
                    ->state(fn (ProcessRun $record) => $record->is_urgent ? 'URGENT' : null)
                    ->placeholder(''),

                TextColumn::make('started_at')->dateTime('d M Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->options([
                    ProcessRun::TYPE_ONBOARDING => 'Onboarding',
                    ProcessRun::TYPE_OFFBOARDING => 'Offboarding',
                ]),
                SelectFilter::make('status')->options([
                    'in_progress' => 'In progress',
                    'blocked' => 'Blocked',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                ]),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading('No runs yet')
            ->emptyStateDescription('Start one from a person in the directory.');
    }

    public static function getRelations(): array
    {
        return [TasksRelationManager::class];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->open()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProcessRuns::route('/'),
            'view' => ViewProcessRun::route('/{record}'),
        ];
    }
}
