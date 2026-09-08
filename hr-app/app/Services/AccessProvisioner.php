<?php

namespace App\Services;

use App\Enums\AccessStatus;
use App\Enums\EmployeeStatus;
use App\Enums\ProvisioningMode;
use App\Models\AppAccess;
use App\Models\Employee;
use App\Models\RoleTemplateApp;

/**
 * Turns a role template into concrete access rows.
 *
 * Nothing here calls an external API yet: it records what *should* exist so the
 * matrix is truthful, and marks manual apps as awaiting a human step. The API
 * adapters plug in behind this once credentials are available.
 */
class AccessProvisioner
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Creates any access rows the employee's role template expects but which do
     * not exist yet. Existing rows are never overwritten, so re-applying a
     * template is safe.
     *
     * @return int number of rows created
     */
    public function applyRoleTemplate(Employee $employee): int
    {
        if ($employee->role_template_id === null) {
            return 0;
        }

        $existingAppIds = $employee->accesses()->pluck('app_id')->all();

        $expected = RoleTemplateApp::query()
            ->with('app')
            ->where('role_template_id', $employee->role_template_id)
            ->whereNotIn('app_id', $existingAppIds)
            ->get();

        $created = 0;

        foreach ($expected as $templateApp) {
            $app = $templateApp->app;

            if ($app === null || ! $app->active) {
                continue;
            }

            AppAccess::create([
                'employee_id' => $employee->id,
                'app_id' => $app->id,
                'status' => $app->provisioning_mode === ProvisioningMode::Manual
                    ? AccessStatus::PendingManual
                    : AccessStatus::Requested,
                'license_tier' => $templateApp->license_tier,
                'scopes' => $templateApp->scopes,
            ]);

            $created++;
        }

        if ($created > 0) {
            $this->audit->log(
                'app_access.template_applied',
                $employee,
                ['created' => $created, 'template' => $employee->roleTemplate?->key],
            );
        }

        return $created;
    }

    /**
     * Access rows that are live for people who have already left. This is the
     * query the whole system exists to make impossible to ignore.
     */
    public function lingeringAccess()
    {
        return AppAccess::query()
            ->with(['employee', 'app'])
            ->live()
            ->whereHas('employee', fn ($q) => $q->where('status', EmployeeStatus::Exited))
            ->get();
    }
}
