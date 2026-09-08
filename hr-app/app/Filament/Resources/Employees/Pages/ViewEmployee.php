<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\ProcessRuns\ProcessRunResource;
use App\Models\Employee;
use App\Models\EmployeePaymentDetail;
use App\Models\ProcessRun;
use App\Services\AuditLogger;
use App\Services\Process\OffboardingRunBuilder;
use App\Services\Process\OnboardingRunBuilder;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class ViewEmployee extends ViewRecord
{
    protected static string $resource = EmployeeResource::class;

    public function getTitle(): string
    {
        /** @var Employee $record */
        $record = $this->getRecord();

        return $record->displayName();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->startOnboardingAction(),
            $this->startOffboardingAction(),
            $this->revealPayoutDetailsAction(),
            EditAction::make(),
        ];
    }

    private function startOnboardingAction(): Action
    {
        return Action::make('startOnboarding')
            ->label('Start onboarding')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->color('primary')
            ->visible(function (): bool {
                /** @var Employee $record */
                $record = $this->getRecord();

                return (Auth::user()?->can('runOnboarding', $record) ?? false)
                    && ! $record->processRuns()
                        ->where('type', ProcessRun::TYPE_ONBOARDING)
                        ->open()
                        ->exists();
            })
            ->requiresConfirmation()
            ->modalHeading('Start onboarding')
            ->modalDescription(fn () => $this->getRecord()->role_template_id === null
                ? 'No role template is set, so no provisioning steps will be created. Set one first for a complete pipeline.'
                : 'Builds the pipeline from the role template. Nothing is provisioned until the signature gate is satisfied.')
            ->action(function () {
                /** @var Employee $record */
                $record = $this->getRecord();

                try {
                    $run = app(OnboardingRunBuilder::class)->build($record, Auth::user());
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('Onboarding started')
                    ->body($run->tasks()->count().' steps created.')
                    ->success()
                    ->send();

                $this->redirect(ProcessRunResource::getUrl('view', ['record' => $run]));
            });
    }

    private function startOffboardingAction(): Action
    {
        return Action::make('startOffboarding')
            ->label('Start offboarding')
            ->icon(Heroicon::OutlinedArrowLeftOnRectangle)
            ->color('danger')
            ->visible(function (): bool {
                /** @var Employee $record */
                $record = $this->getRecord();

                return (Auth::user()?->can('runOffboarding', $record) ?? false)
                    && ! $record->processRuns()
                        ->where('type', ProcessRun::TYPE_OFFBOARDING)
                        ->open()
                        ->exists();
            })
            ->modalHeading('Start offboarding')
            ->modalDescription('Steps are generated from the access this person actually holds, revocations first.')
            ->schema([
                Toggle::make('is_urgent')
                    ->label('Immediate termination')
                    ->helperText('Marks the run urgent so the revocation steps are treated as drop-everything.'),
            ])
            ->action(function (array $data) {
                /** @var Employee $record */
                $record = $this->getRecord();

                try {
                    $run = app(OffboardingRunBuilder::class)->build(
                        $record,
                        Auth::user(),
                        (bool) ($data['is_urgent'] ?? false),
                    );
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                app(AuditLogger::class)->log(
                    AuditLogger::OFFBOARDING_TRIGGERED,
                    $record,
                    ['run_id' => $run->id, 'urgent' => $run->is_urgent],
                );

                Notification::make()
                    ->title('Offboarding started')
                    ->body($run->tasks()->count().' steps created. Revoke access first.')
                    ->warning()
                    ->send();

                $this->redirect(ProcessRunResource::getUrl('view', ['record' => $run]));
            });
    }

    /**
     * Decrypting the full account number is a deliberate, reasoned act: the
     * reason is mandatory and the event is written to the audit log before the
     * value is ever shown.
     */
    private function revealPayoutDetailsAction(): Action
    {
        return Action::make('revealPayout')
            ->label('Reveal payout details')
            ->icon(Heroicon::OutlinedEye)
            ->color('danger')
            ->visible(function (): bool {
                /** @var Employee $record */
                $record = $this->getRecord();
                $detail = $record->paymentDetail;

                return $detail !== null && (Auth::user()?->can('reveal', $detail) ?? false);
            })
            ->schema([
                Textarea::make('reason')
                    ->label('Why do you need to see this?')
                    ->required()
                    ->minLength(10)
                    ->rows(3)
                    ->helperText('Recorded against your name in the audit log.'),
            ])
            ->modalHeading('Reveal payout details')
            ->modalDescription('This decrypts the full account number and records the access.')
            ->modalSubmitActionLabel('Reveal and log')
            ->action(function (array $data): void {
                /** @var Employee $record */
                $record = $this->getRecord();
                $detail = $record->paymentDetail;

                if ($detail === null) {
                    return;
                }

                // Authorise again at execution time, not just for visibility.
                $this->authorizeForRecordOrFail($detail);

                app(AuditLogger::class)->logPaymentReveal($detail, $data['reason']);

                Notification::make()
                    ->title('Payout details')
                    ->body(view('filament.partials.payout-reveal', [
                        'detail' => $detail,
                    ])->render())
                    ->persistent()
                    ->warning()
                    ->send();
            });
    }

    private function authorizeForRecordOrFail(EmployeePaymentDetail $detail): void
    {
        abort_unless(Auth::user()?->can('reveal', $detail) ?? false, 403);
    }
}
