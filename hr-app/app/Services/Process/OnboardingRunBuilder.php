<?php

namespace App\Services\Process;

use App\Enums\ProvisioningMode;
use App\Models\Employee;
use App\Models\ProcessRun;
use App\Models\ProcessTask;
use App\Models\RoleTemplateApp;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Builds the onboarding pipeline for one person.
 *
 * Order follows the SOP: paperwork out first, then the contract, then a gate.
 * Nothing is provisioned until the signature webhook confirms the contract is
 * signed, which is the rule the current manual process states but cannot
 * enforce.
 */
class OnboardingRunBuilder extends RunBuilder
{
    public const GATE_KEY = 'signature_gate';

    protected function type(): string
    {
        return ProcessRun::TYPE_ONBOARDING;
    }

    public function build(Employee $employee, ?User $initiator = null): ProcessRun
    {
        $this->assertNoOpenRun($employee);

        return DB::transaction(function () use ($employee, $initiator) {
            $run = $this->createRun($employee, $initiator);

            $this->paperworkTasks($employee);
            $this->signatureGate($employee);
            $this->provisioningTasks($employee);
            $this->welcomeTasks($employee);

            return $this->persist($run);
        });
    }

    private function paperworkTasks(Employee $employee): void
    {
        $this->task(
            key: 'paperwork_form',
            title: 'Send the HR paperwork form',
            mode: ProcessTask::MODE_AUTOMATIC,
            description: <<<'MD'
                Sends the HR Paperwork Typeform to their personal email with the
                hidden `employee_id` field pre-filled, so the response links back
                to this record with no name matching.
                MD,
            payload: ['form' => 'paperwork', 'employee_uuid' => $employee->uuid],
        );

        $this->task(
            key: 'bank_form',
            title: 'Send the bank details form',
            mode: ProcessTask::MODE_AUTOMATIC,
            description: <<<'MD'
                Sends the bank details Typeform, also carrying the hidden
                `employee_id`. Responses land encrypted and still require review
                before they are attached to the person.
                MD,
            payload: ['form' => 'bank', 'employee_uuid' => $employee->uuid],
        );

        $documentType = $employee->employment_type->contractDocumentType();

        $this->task(
            key: 'contract',
            title: $documentType === 'contract'
                ? 'Send the freelance contract / NDA for signature'
                : 'Send the appointment letter for signature',
            mode: ProcessTask::MODE_AUTOMATIC,
            description: <<<'MD'
                Drafted from the role template's responsibilities, reviewed by
                HR, then sent through Zoho Sign.
                MD,
            payload: ['document_type' => $documentType],
        );
    }

    /**
     * The blocking step. Everything that grants access depends on this key, so
     * a missing signature stops the whole provisioning chain rather than being
     * a line in a checklist that someone skips.
     */
    private function signatureGate(Employee $employee): void
    {
        $this->task(
            key: self::GATE_KEY,
            title: 'Signature received',
            mode: ProcessTask::MODE_APPROVAL,
            description: <<<'MD'
                **Nothing below is provisioned until this is satisfied.**

                Completed automatically by the Zoho Sign webhook when the
                document is signed. Freelancers and contractors must not receive
                any access before this point.
                MD,
            dependsOn: ['contract'],
        );
    }

    /**
     * One task per app the role template expects, ordered so the identity
     * backbone is created first and everything else can hang off it.
     */
    private function provisioningTasks(Employee $employee): void
    {
        if ($employee->role_template_id === null) {
            return;
        }

        $templateApps = RoleTemplateApp::query()
            ->with('app')
            ->where('role_template_id', $employee->role_template_id)
            ->get()
            ->filter(fn (RoleTemplateApp $ta) => $ta->app?->active)
            // Highest offboard priority first: Google, then the vault, then the rest.
            ->sortByDesc(fn (RoleTemplateApp $ta) => $ta->app->offboard_priority);

        foreach ($templateApps as $templateApp) {
            $app = $templateApp->app;

            $this->task(
                key: 'provision_'.$app->key,
                title: 'Create '.$app->name.' account',
                mode: $app->provisioning_mode === ProvisioningMode::Manual
                    ? ProcessTask::MODE_MANUAL
                    : ProcessTask::MODE_AUTOMATIC,
                description: $app->onboard_instructions_md,
                appId: $app->id,
                dependsOn: [self::GATE_KEY],
                payload: array_filter([
                    'license_tier' => $templateApp->license_tier,
                    'scopes' => $templateApp->scopes,
                    'required' => $templateApp->required,
                ], fn ($v) => $v !== null),
            );
        }
    }

    private function welcomeTasks(Employee $employee): void
    {
        $this->task(
            key: 'welcome_post',
            title: 'Post the welcome announcement in #champions',
            mode: ProcessTask::MODE_AUTOMATIC,
            description: 'Announces the new joiner with their role and start date.',
            dependsOn: [self::GATE_KEY],
        );

        $this->task(
            key: 'manager_checklist',
            title: 'Send the manager their day-one checklist',
            mode: ProcessTask::MODE_AUTOMATIC,
            description: $employee->manager
                ? "DM {$employee->manager->full_name} the day-one checklist."
                : 'No manager is set on this record, so there is nobody to notify.',
            dependsOn: [self::GATE_KEY],
        );
    }
}
