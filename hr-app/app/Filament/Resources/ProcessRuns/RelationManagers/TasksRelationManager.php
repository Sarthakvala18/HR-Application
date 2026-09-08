<?php

namespace App\Filament\Resources\ProcessRuns\RelationManagers;

use App\Enums\TaskStatus;
use App\Models\ProcessTask;
use App\Services\Process\ProcessTaskRunner;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Steps';

    /**
     * Rendered with the page rather than deferred: the steps are the whole
     * point of this screen, so they should not arrive after a loading flash.
     */
    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('sort_order')
            ->paginated(false)
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->formatStateUsing(fn ($state) => (int) ($state / 10))
                    ->size('sm')
                    ->color('gray'),

                TextColumn::make('title')
                    ->wrap()
                    ->description(fn (ProcessTask $record) => $record->app?->name)
                    ->weight(fn (ProcessTask $record) => $record->status === TaskStatus::Pending
                        ? 'semibold'
                        : 'normal'),

                TextColumn::make('mode')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        ProcessTask::MODE_MANUAL => 'warning',
                        ProcessTask::MODE_APPROVAL => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        ProcessTask::MODE_MANUAL => 'Manual',
                        ProcessTask::MODE_APPROVAL => 'Gate',
                        default => 'Auto',
                    }),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (TaskStatus $state) => $state->label())
                    ->color(fn (TaskStatus $state) => $state->color()),

                TextColumn::make('evidence')
                    ->label('Evidence')
                    ->placeholder('—')
                    ->limit(30)
                    ->toggleable(),

                TextColumn::make('completed_at')
                    ->dateTime('d M H:i')
                    ->label('Done')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                $this->instructionsAction(),
                $this->completeAction(),
                $this->skipAction(),
            ]);
    }

    /** The guided card: real click paths plus the values to paste. */
    private function instructionsAction(): Action
    {
        return Action::make('instructions')
            ->label('Details')
            ->icon(Heroicon::OutlinedBookOpen)
            ->color('gray')
            ->visible(fn (ProcessTask $record) => filled($record->description_md))
            ->modalHeading(fn (ProcessTask $record) => $record->title)
            ->modalContent(fn (ProcessTask $record) => view('filament.partials.process-task-card', [
                'task' => $record,
                'employee' => $this->getOwnerRecord()->employee,
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close');
    }

    private function completeAction(): Action
    {
        return Action::make('complete')
            ->label(fn (ProcessTask $record) => $record->mode === ProcessTask::MODE_APPROVAL
                ? 'Confirm'
                : 'Mark done')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (ProcessTask $record) => ! $record->status->isFinished()
                && $record->status !== TaskStatus::Blocked
                && (Auth::user()?->canRunProcesses() ?? false))
            ->schema(fn (ProcessTask $record) => $record->requiresEvidence()
                ? [
                    TextInput::make('evidence')
                        ->label($record->app !== null
                            ? 'Account ID or email created'
                            : 'What was done')
                        ->required()
                        ->helperText('Manual steps must record what actually happened.'),
                ]
                : [
                    Textarea::make('evidence')
                        ->label('Note (optional)')
                        ->rows(2),
                ])
            ->action(function (ProcessTask $record, array $data) {
                try {
                    app(ProcessTaskRunner::class)->complete(
                        $record,
                        Auth::user(),
                        $data['evidence'] ?? null,
                    );
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Step completed')->success()->send();
            });
    }

    private function skipAction(): Action
    {
        return Action::make('skip')
            ->label('Skip')
            ->icon(Heroicon::OutlinedForward)
            ->color('gray')
            ->visible(fn (ProcessTask $record) => ! $record->status->isFinished()
                && (Auth::user()?->canRunProcesses() ?? false))
            ->requiresConfirmation()
            ->modalDescription('Skipped steps are recorded with your reason, not silently dropped.')
            ->schema([
                Textarea::make('reason')->label('Why is this not needed?')->required()->rows(2),
            ])
            ->action(function (ProcessTask $record, array $data) {
                app(ProcessTaskRunner::class)->skip($record, Auth::user(), $data['reason']);

                Notification::make()->title('Step skipped')->success()->send();
            });
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
