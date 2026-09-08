<?php

namespace App\Services\Process;

use App\Enums\ProvisioningMode;
use App\Models\AppAccess;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\ProcessRun;
use App\Models\ProcessTask;
use App\Models\User;
use App\Services\Zoho\LetterService;
use Illuminate\Support\Facades\DB;

/**
 * Builds the offboarding pipeline.
 *
 * Order is the whole point: revoke access first, handle data second, letters
 * last. Tasks are generated from the access the person *actually holds* rather
 * than from their role template, because those two drift and only the former
 * is a real exposure.
 */
class OffboardingRunBuilder extends RunBuilder
{
    public const REVOKE_PREFIX = 'revoke_';

    protected function type(): string
    {
        return ProcessRun::TYPE_OFFBOARDING;
    }

    public function build(Employee $employee, ?User $initiator = null, bool $urgent = false): ProcessRun
    {
        $this->assertNoOpenRun($employee);

        return DB::transaction(function () use ($employee, $initiator, $urgent) {
            $run = $this->createRun($employee, $initiator, $urgent);

            $revokeKeys = $this->revocationTasks($employee);
            $this->credentialRotationTask($employee, $revokeKeys);
            $this->dataTasks($employee, $revokeKeys);
            $this->closingTasks($employee);

            return $this->persist($run);
        });
    }

    /**
     * One revocation task per live access, highest offboard priority first so
     * the identity backbone dies before anything else.
     *
     * @return array<int, string> the task keys created
     */
    private function revocationTasks(Employee $employee): array
    {
        $accesses = $employee->accesses()
            ->with('app')
            ->live()
            ->get()
            ->filter(fn (AppAccess $access) => $access->app !== null)
            ->sortByDesc(fn (AppAccess $access) => $access->app->offboard_priority);

        $keys = [];

        foreach ($accesses as $access) {
            $app = $access->app;
            $key = self::REVOKE_PREFIX.$app->key;
            $keys[] = $key;

            $isFirst = count($keys) === 1;

            $this->task(
                key: $key,
                title: 'Revoke '.$app->name,
                mode: $app->provisioning_mode === ProvisioningMode::Manual
                    ? ProcessTask::MODE_MANUAL
                    : ProcessTask::MODE_AUTOMATIC,
                description: $this->revocationDescription($app, $isFirst),
                appId: $app->id,
                payload: array_filter([
                    'app_access_id' => $access->id,
                    'license_tier' => $access->license_tier,
                    'external_id' => $access->external_id,
                    'billable' => $access->isBillableSeat(),
                ], fn ($v) => $v !== null),
            );
        }

        return $keys;
    }

    private function revocationDescription($app, bool $isFirst): string
    {
        $body = $app->offboard_instructions_md ?: 'Revoke this access.';

        return $isFirst
            ? "**Do this first.**\n\n".$body
            : $body;
    }

    /**
     * Removing someone from the vault does not change the shared logins they
     * already read. This task exists because that is the step everyone forgets.
     */
    private function credentialRotationTask(Employee $employee, array $revokeKeys): void
    {
        $collections = $employee->accesses()
            ->with('app')
            ->live()
            ->get()
            ->filter(fn (AppAccess $a) => $a->app?->key === 'bitwarden')
            ->flatMap(fn (AppAccess $a) => $a->scopes['collections'] ?? [])
            ->unique()
            ->values()
            ->all();

        $bitwardenKey = self::REVOKE_PREFIX.'bitwarden';

        $this->task(
            key: 'credential_rotation',
            title: 'Rotate shared credentials they could read',
            mode: ProcessTask::MODE_MANUAL,
            description: $collections === []
                ? "Review every shared login in the Bitwarden collections this person had access to and rotate them.\n\nNo collections were recorded on their access row, so check the vault directly."
                : "Rotate the shared logins in these collections:\n\n- ".implode("\n- ", $collections),
            dependsOn: in_array($bitwardenKey, $revokeKeys, true) ? [$bitwardenKey] : [],
            payload: ['collections' => $collections],
        );
    }

    /**
     * Data handling runs only after every revocation, and the destination is
     * resolved from the department matrix rather than left to memory.
     */
    private function dataTasks(Employee $employee, array $revokeKeys): void
    {
        $destination = $employee->offboardDestinationEmail();

        $this->task(
            key: 'data_migration',
            title: 'Migrate mail and Drive data',
            mode: ProcessTask::MODE_MANUAL,
            description: <<<MD
                Transfer Drive ownership and migrate mail to the destination for
                their department.

                **Destination: {$this->destinationLabel($destination)}**

                Set an auto-reply or forward for the handover period before
                deleting anything.
                MD,
            dependsOn: $revokeKeys,
            payload: ['destination' => $destination, 'department' => $employee->department?->key],
        );

        $this->task(
            key: 'verify_and_delete',
            title: 'Verify migration, then delete the account and add the alias',
            mode: ProcessTask::MODE_MANUAL,
            description: <<<MD
                **Wait at least 24 hours after the migration before doing this.**

                1. Confirm the data actually arrived at {$this->destinationLabel($destination)}.
                2. Delete the user.
                3. Add their old address as an alias on the destination account so
                   client mail sent afterwards still lands somewhere.
                MD,
            dependsOn: ['data_migration'],
            payload: ['destination' => $destination, 'not_before' => now()->addDay()->toDateString()],
        );
    }

    private function destinationLabel(?string $destination): string
    {
        return $destination ?: 'not set — confirm with HR before proceeding';
    }

    /**
     * Names every exit letter this person should receive and flags any that
     * cannot be sent yet, so the gap is visible while the run is being worked
     * rather than at the moment someone clicks send.
     *
     * @param  array<int, DocumentTemplate>  $templates
     */
    private function lettersDescription(Employee $employee, array $templates): string
    {
        $from = config('mail.from.address', 'hr@example.com');
        $department = $employee->department?->name ?? 'this department';

        if ($templates === []) {
            return 'No exit-letter templates are registered for '.$department
                .'. Register them before this step can run.';
        }

        $lines = ['Sent from '.$from.'.', ''];

        foreach ($templates as $template) {
            $state = match (true) {
                empty($template->field_map) => 'no field map recorded yet',
                blank($template->zoho_template_id) => 'no Zoho template id yet',
                ! $template->isVerified() => 'field map not verified against the Zoho API',
                default => 'ready to send',
            };

            $lines[] = '- **'.ucfirst($template->type).'**: "'.$template->name.'" — '.$state.'.';
        }

        $expected = [DocumentTemplate::TYPE_RELIEVING, DocumentTemplate::TYPE_EXPERIENCE];
        $present = array_map(fn (DocumentTemplate $template) => $template->type, $templates);

        foreach (array_diff($expected, $present) as $missingType) {
            $lines[] = '- **'.ucfirst($missingType).'**: no template registered for '.$department.'.';
        }

        return implode("\n", $lines);
    }

    private function closingTasks(Employee $employee): void
    {
        // Resolved at build time so the step names the exact templates and
        // flags a missing one now, rather than failing at send.
        $templates = app(LetterService::class)->exitTemplatesFor($employee);

        $this->task(
            key: 'letters',
            title: 'Send the relieving and experience letters',
            mode: ProcessTask::MODE_AUTOMATIC,
            description: $this->lettersDescription($employee, $templates),
            dependsOn: ['verify_and_delete'],
            payload: [
                'document_template_ids' => array_map(
                    fn (DocumentTemplate $template) => $template->id,
                    $templates,
                ),
            ],
        );

        $this->task(
            key: 'summary',
            title: 'Post the completion summary to the HR channel',
            mode: ProcessTask::MODE_AUTOMATIC,
            description: 'Posted only once access revocation is complete, never before.',
            dependsOn: ['verify_and_delete'],
        );
    }
}
