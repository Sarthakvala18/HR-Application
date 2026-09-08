<?php

namespace App\Filament\Resources\ProcessRuns\Pages;

use App\Filament\Resources\ProcessRuns\ProcessRunResource;
use App\Models\ProcessRun;
use App\Services\Process\OnboardingRunBuilder;
use App\Services\Process\ProcessTaskRunner;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

class ViewProcessRun extends ViewRecord
{
    protected static string $resource = ProcessRunResource::class;

    public function getTitle(): string
    {
        /** @var ProcessRun $run */
        $run = $this->getRecord();

        return ucfirst($run->type).' — '.($run->employee?->displayName() ?? 'unknown');
    }

    public function getSubheading(): ?string
    {
        /** @var ProcessRun $run */
        $run = $this->getRecord();

        if ($run->type === ProcessRun::TYPE_OFFBOARDING) {
            return 'Access is revoked first, data handled second, letters last. Steps unlock as the ones they depend on finish.';
        }

        return 'Nothing is provisioned until the signature gate is satisfied.';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->satisfyGateAction(),
            $this->cancelRunAction(),
        ];
    }

    /**
     * Manual override for the signature gate, for when the document was signed
     * outside the system. Normally the Zoho Sign webhook does this.
     */
    private function satisfyGateAction(): Action
    {
        return Action::make('satisfyGate')
            ->label('Confirm signature received')
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->visible(function (): bool {
                /** @var ProcessRun $run */
                $run = $this->getRecord();

                if (! $run->isOnboarding() || ! (Auth::user()?->canRunProcesses() ?? false)) {
                    return false;
                }

                $gate = $run->tasks->firstWhere('key', OnboardingRunBuilder::GATE_KEY);

                return $gate !== null && ! $gate->status->isFinished();
            })
            ->requiresConfirmation()
            ->modalHeading('Confirm the document has been signed')
            ->modalDescription('This unblocks every provisioning step below. Only confirm if the signed document actually exists.')
            ->action(function () {
                /** @var ProcessRun $run */
                $run = $this->getRecord();

                app(ProcessTaskRunner::class)->satisfySignatureGate($run, Auth::user());

                Notification::make()
                    ->title('Signature confirmed')
                    ->body('Provisioning steps are now unblocked.')
                    ->success()
                    ->send();

                // This unblocks many rows at once, and a header action does not
                // refresh the steps table on its own.
                $this->redirect(static::getUrl(['record' => $run]));
            });
    }

    private function cancelRunAction(): Action
    {
        return Action::make('cancelRun')
            ->label('Cancel run')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('gray')
            ->visible(fn () => $this->getRecord()->status !== 'completed'
                && $this->getRecord()->status !== 'cancelled'
                && (Auth::user()?->canRunProcesses() ?? false))
            ->requiresConfirmation()
            ->schema([
                Textarea::make('reason')->label('Reason')->required()->rows(2),
            ])
            ->action(function (array $data) {
                $this->getRecord()->update([
                    'status' => 'cancelled',
                    'cancellation_reason' => $data['reason'],
                ]);

                Notification::make()->title('Run cancelled')->success()->send();
            });
    }
}
