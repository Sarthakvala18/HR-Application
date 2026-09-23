<?php

namespace App\Filament\Resources\ProcessRuns\RelationManagers;

use App\Enums\TaskStatus;
use App\Models\ProcessTask;
use App\Services\Letters\ExitLetterDispatcher;
use App\Services\Process\ProcessTaskRunner;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
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
                $this->previewLettersAction(),
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

    /**
     * Downloads a letter exactly as the employee would receive it.
     *
     * Sending an exit letter cannot be undone, so being able to read the real
     * document first is the difference between a checklist and a review.
     */
    private function previewLettersAction(): Action
    {
        return Action::make('previewLetters')
            ->label('Preview')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->visible(fn (ProcessTask $record) => $record->key === ProcessTaskRunner::LETTERS_KEY
                && $record->run->employee !== null)
            ->modalHeading('Preview a letter')
            ->modalDescription('Downloads the finished PDF. Nothing is sent.')
            ->modalSubmitActionLabel('Download')
            ->schema(fn (ProcessTask $record) => [
                Select::make('type')
                    ->label('Letter')
                    ->options(array_combine(
                        ExitLetterDispatcher::TYPES,
                        app(ExitLetterDispatcher::class)->titlesFor($record->run->employee),
                    ))
                    ->default(ExitLetterDispatcher::TYPES[0])
                    ->selectablePlaceholder(false)
                    ->required(),
                ...$this->missingLetterFields($record, forPreview: true),
            ])
            ->action(function (ProcessTask $record, array $data) {
                $employee = $record->run->employee;

                // Preview values are not written to the record: someone
                // checking how a letter looks should not silently change data.
                $context = array_merge(
                    $record->payload['supplied'] ?? [],
                    array_filter($data['supplied'] ?? []),
                );

                try {
                    $letter = app(ExitLetterDispatcher::class)
                        ->renderOne($employee, $data['type'], $context);
                } catch (RuntimeException|InvalidArgumentException $e) {
                    Notification::make()
                        ->title('Could not build the preview')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return null;
                }

                return response()->streamDownload(
                    fn () => print $letter['pdf'],
                    $letter['name'],
                    ['Content-Type' => 'application/pdf'],
                );
            });
    }

    private function completeAction(): Action
    {
        return Action::make('complete')
            ->label(fn (ProcessTask $record) => match (true) {
                // This step really does dispatch the documents, so it must not
                // be labelled as if it were a checkbox.
                $record->key === ProcessTaskRunner::LETTERS_KEY => 'Send letters',
                $record->mode === ProcessTask::MODE_APPROVAL => 'Confirm',
                default => 'Mark done',
            })
            ->requiresConfirmation(fn (ProcessTask $record) => $record->key === ProcessTaskRunner::LETTERS_KEY)
            ->modalHeading(fn (ProcessTask $record) => $record->key === ProcessTaskRunner::LETTERS_KEY
                ? 'Send the exit letters'
                : null)
            ->modalDescription(fn (ProcessTask $record) => $record->key === ProcessTaskRunner::LETTERS_KEY
                ? $this->sendSummary($record)
                : null)
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (ProcessTask $record) => ! $record->status->isFinished()
                && $record->status !== TaskStatus::Blocked
                && (Auth::user()?->canRunProcesses() ?? false))
            ->schema(fn (ProcessTask $record) => match (true) {
                // Ask for whatever the letters still need, rather than failing
                // at send and making someone go and edit the record first.
                $record->key === ProcessTaskRunner::LETTERS_KEY => $this->missingLetterFields($record),
                $record->requiresEvidence() => [
                    TextInput::make('evidence')
                        ->label($record->app !== null
                            ? 'Account ID or email created'
                            : 'What was done')
                        ->required()
                        ->helperText('Manual steps must record what actually happened.'),
                ],
                default => [
                    Textarea::make('evidence')
                        ->label('Note (optional)')
                        ->rows(2),
                ],
            })
            ->action(function (ProcessTask $record, array $data) {
                if ($record->key === ProcessTaskRunner::LETTERS_KEY) {
                    $this->persistSuppliedFields($record, $data);
                }

                try {
                    app(ProcessTaskRunner::class)->complete(
                        $record,
                        Auth::user(),
                        $data['evidence'] ?? null,
                    );
                } catch (RuntimeException $e) {
                    Notification::make()
                        ->title('Letters not sent')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->title('Step completed')->success()->send();
            });
    }

    /**
     * States plainly what is about to happen, so nobody sends exit documents
     * without seeing the recipient first.
     */
    private function sendSummary(ProcessTask $task): string
    {
        $dispatcher = app(ExitLetterDispatcher::class);
        $employee = $task->run->employee;

        if ($employee === null) {
            return 'This run has no employee attached.';
        }

        $recipient = $dispatcher->recipientFor($employee) ?? 'no address on record';

        return 'Emails '.implode(' and ', $dispatcher->titlesFor($employee))
            .' to '.$employee->full_name.' at '.$recipient
            .'. The step stays open if any letter fails, and nothing is sent unless both build.';
    }

    /**
     * Form inputs for everything the exit letters still need.
     *
     * @return array<int, mixed>
     */
    private function missingLetterFields(ProcessTask $task, bool $forPreview = false): array
    {
        $employee = $task->run->employee;

        if ($employee === null) {
            return [];
        }

        $context = $task->payload['supplied'] ?? [];
        $missing = app(ExitLetterDispatcher::class)->missingFor($employee, $context);

        if ($missing === []) {
            return $forPreview ? [] : [
                Placeholder::make('ready')
                    ->label('')
                    ->content('Everything the letters need is on file.'),
            ];
        }

        $fields = [
            Placeholder::make('why')
                ->label('')
                ->content($forPreview
                    ? 'These are missing from the record. Values entered here are used for the preview only and are not saved.'
                    : 'These are missing from '.$employee->full_name
                        .'\'s record and are required by the letters. They will be saved to the record as well as used here.'),
        ];

        foreach ($missing as $key) {
            $label = ExitLetterDispatcher::FIELD_LABELS[$key] ?? str_replace('_', ' ', $key);

            $fields[] = in_array($key, ExitLetterDispatcher::DATE_KEYS, true)
                ? DatePicker::make('supplied.'.$key)->label($label)->required()->native(false)
                : TextInput::make('supplied.'.$key)->label($label)->required();
        }

        return $fields;
    }

    /**
     * Writes supplied values back onto the employee where they map to a real
     * column, so the same gap is not asked for again on the next letter.
     */
    private function persistSuppliedFields(ProcessTask $task, array $data): void
    {
        $employee = $task->run->employee;
        $supplied = array_filter($data['supplied'] ?? []);

        if ($employee === null || $supplied === []) {
            return;
        }

        $updates = [];

        foreach ($supplied as $key => $value) {
            if (isset(ExitLetterDispatcher::COLUMNS[$key])) {
                $updates[ExitLetterDispatcher::COLUMNS[$key]] = $value;
            }
        }

        if ($updates !== []) {
            $employee->update($updates);
        }

        // Values with no column of their own (report_to, hr_name) ride along on
        // the task so the send can use them.
        $task->update(['payload' => array_merge($task->payload ?? [], ['supplied' => $supplied])]);
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
