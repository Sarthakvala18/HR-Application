<?php

namespace App\Filament\Resources\ProcessRuns\RelationManagers;

use App\Enums\TaskStatus;
use App\Models\ProcessTask;
use App\Services\Process\ProcessTaskRunner;
use App\Services\Zoho\LetterService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
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
                ? 'This sends the relieving and experience letters to the employee for signature through Zoho Sign. The step stays open if any letter fails.'
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
     * Form inputs for everything the exit letters still need.
     *
     * @return array<int, mixed>
     */
    private function missingLetterFields(ProcessTask $task): array
    {
        $employee = $task->run->employee;

        if ($employee === null) {
            return [];
        }

        $missing = app(LetterService::class)->missingFor($employee);

        if ($missing === []) {
            return [
                Placeholder::make('ready')
                    ->label('')
                    ->content('Everything the letters need is on file. Sending both now.'),
            ];
        }

        $fields = [
            Placeholder::make('why')
                ->label('')
                ->content('These are missing from '.$employee->full_name
                    .'\'s record and are required by the letters. They will be saved to the record as well as used here.'),
        ];

        foreach ($missing as $key) {
            $label = LetterService::FIELD_LABELS[$key] ?? str_replace('_', ' ', $key);

            $fields[] = in_array($key, LetterService::DATE_KEYS, true)
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

        $columns = [
            'employee_id' => 'employee_code',
            'job_title' => 'position',
            'role' => 'position',
            'join_date' => 'date_of_joining',
            'joining_date' => 'date_of_joining',
            'last_date' => 'date_of_exit',
            'leaving_date' => 'date_of_exit',
        ];

        $updates = [];

        foreach ($supplied as $key => $value) {
            if (isset($columns[$key])) {
                $updates[$columns[$key]] = $value;
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
