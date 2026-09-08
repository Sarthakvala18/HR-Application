<?php

namespace App\Filament\Resources\FormSubmissions\Tables;

use App\Models\Employee;
use App\Models\FormSubmission;
use App\Services\AuditLogger;
use App\Services\Import\NameMatcher;
use App\Services\Import\TypeformCsvImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class FormSubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('form_key')
                    ->label('Form')
                    ->badge()
                    ->color(fn (string $state) => $state === FormSubmission::FORM_BANK ? 'danger' : 'info'),

                TextColumn::make('submitted_name')
                    ->label('Submitted name')
                    ->state(fn (FormSubmission $record) => $record->normalized['full_name']
                        ?? $record->raw_payload['Name']
                        ?? '—')
                    ->searchable(query: fn (Builder $query, string $search) => $query)
                    ->description(fn (FormSubmission $record) => $record->normalized['personal_email'] ?? null),

                TextColumn::make('employee.full_name')
                    ->label('Linked to')
                    ->placeholder('not linked')
                    ->color(fn (FormSubmission $record) => $record->employee_id ? 'success' : 'gray'),

                TextColumn::make('match_confidence')
                    ->label('Match')
                    ->badge()
                    ->formatStateUsing(fn (?int $state, FormSubmission $record) => $state === null
                        ? '—'
                        : $state.'% '.str_replace('_', ' ', (string) $record->match_method))
                    ->color(fn (?int $state) => match (true) {
                        $state === null => 'gray',
                        $state >= 95 => 'success',
                        $state >= 70 => 'warning',
                        default => 'danger',
                    }),

                TextColumn::make('issues')
                    ->label('Flags')
                    ->badge()
                    ->color('warning')
                    ->separator(',')
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', $state))
                    ->placeholder('none')
                    ->wrap(),

                TextColumn::make('review_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'accepted' => 'success',
                        'quarantined' => 'danger',
                        'discarded' => 'gray',
                        default => 'warning',
                    }),

                TextColumn::make('submitted_at')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('form_key')
                    ->label('Form')
                    ->options([
                        FormSubmission::FORM_PAPERWORK => 'HR paperwork',
                        FormSubmission::FORM_BANK => 'Bank details',
                    ]),

                SelectFilter::make('review_status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Pending review',
                        'accepted' => 'Accepted',
                        'discarded' => 'Discarded',
                        'quarantined' => 'Quarantined',
                    ])
                    ->default('pending'),

                Filter::make('has_issues')
                    ->label('Has data-quality flags')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNotNull('issues')),

                Filter::make('unlinked')
                    ->label('Not linked to anyone')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNull('employee_id')),
            ])
            ->recordActions([
                self::acceptAction(),
                self::discardAction(),
            ])
            ->emptyStateHeading('Nothing to review')
            ->emptyStateDescription('Import a Typeform export with hr:import-typeform to populate this queue.');
    }

    /**
     * Confirming a match. For bank rows the person must be chosen explicitly:
     * the form carries no email, so the system never decides this itself.
     */
    private static function acceptAction(): Action
    {
        return Action::make('accept')
            ->label('Review')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (FormSubmission $record) => $record->review_status === 'pending'
                && (Auth::user()?->can('review', $record) ?? false))
            ->modalHeading(fn (FormSubmission $record) => $record->form_key === FormSubmission::FORM_BANK
                ? 'Link these payout details to a person'
                : 'Confirm this submission')
            ->modalDescription(fn (FormSubmission $record) => $record->form_key === FormSubmission::FORM_BANK
                ? 'The bank form collects no email address, so this link must be confirmed by a human. Paying the wrong person is the risk being guarded against.'
                : 'Applying this creates or updates the person record.')
            ->schema(fn (FormSubmission $record) => [
                Placeholder::make('submitted')
                    ->label('Submitted as')
                    ->content(fn () => $record->normalized['full_name'] ?? $record->raw_payload['Name'] ?? '—'),

                Placeholder::make('proposal')
                    ->label('System proposal')
                    ->content(fn () => $record->review_notes ?: 'No proposal.'),

                Placeholder::make('flags')
                    ->label('Data-quality flags')
                    ->visible(fn () => $record->hasIssues())
                    ->content(fn () => collect($record->issues)
                        ->map(fn ($i) => str_replace('_', ' ', $i))
                        ->implode(', ')),

                Select::make('employee_id')
                    ->label('Person')
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->options(fn () => self::candidateOptions($record))
                    ->default(fn () => $record->form_key === FormSubmission::FORM_BANK
                        ? null
                        : $record->employee_id)
                    ->helperText($record->form_key === FormSubmission::FORM_BANK
                        ? 'Choose deliberately. Nothing is preselected for payout data.'
                        : 'Leave blank only if this should create a new person.'),
            ])
            ->action(function (FormSubmission $record, array $data) {
                $employee = Employee::find($data['employee_id']);

                if ($employee === null) {
                    Notification::make()->title('No person selected')->danger()->send();

                    return;
                }

                $importer = new TypeformCsvImporter;

                if ($record->form_key === FormSubmission::FORM_BANK) {
                    $importer->applyBankSubmission($record, $employee);
                } else {
                    $importer->applyPaperworkSubmission($record, $employee);
                }

                $record->update(['reviewed_by' => Auth::id(), 'reviewed_at' => now()]);

                app(AuditLogger::class)->log(
                    AuditLogger::IMPORT_MATCH_ACCEPTED,
                    $record,
                    ['employee_id' => $employee->id, 'form' => $record->form_key],
                );

                Notification::make()
                    ->title('Applied to '.$employee->full_name)
                    ->success()
                    ->send();
            });
    }

    private static function discardAction(): Action
    {
        return Action::make('discard')
            ->label('Discard')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->visible(fn (FormSubmission $record) => in_array($record->review_status, ['pending', 'quarantined'], true)
                && (Auth::user()?->can('review', $record) ?? false))
            ->requiresConfirmation()
            ->modalDescription('The raw submission is kept for the record; it just stops appearing in the queue.')
            ->schema([
                Textarea::make('review_notes')->label('Why?')->rows(2),
            ])
            ->action(function (FormSubmission $record, array $data) {
                $record->update([
                    'review_status' => 'discarded',
                    'review_notes' => $data['review_notes'] ?? $record->review_notes,
                    'reviewed_by' => Auth::id(),
                    'reviewed_at' => now(),
                ]);

                Notification::make()->title('Discarded')->success()->send();
            });
    }

    /**
     * Candidate people, best name matches first so the likely answer is near
     * the top without being preselected.
     *
     * @return array<int, string>
     */
    private static function candidateOptions(FormSubmission $record): array
    {
        $name = (string) ($record->normalized['full_name'] ?? $record->raw_payload['Name'] ?? '');

        return Employee::query()
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'work_email'])
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'label' => $employee->full_name.($employee->work_email ? " ({$employee->work_email})" : ''),
                'score' => $name === '' ? 0 : NameMatcher::similarity($name, $employee->full_name),
            ])
            ->sortByDesc('score')
            ->mapWithKeys(fn (array $row) => [
                $row['id'] => $row['score'] > 0
                    ? "{$row['label']} — {$row['score']}% match"
                    : $row['label'],
            ])
            ->all();
    }
}
