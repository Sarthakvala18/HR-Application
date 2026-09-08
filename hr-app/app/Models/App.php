<?php

namespace App\Models;

use App\Enums\ProvisioningMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A system we grant access to (Google Workspace, Slack, Zoom, ...).
 * Named App rather than Application to match the domain language in the SOP;
 * aliased where it would clash with Illuminate's App facade.
 */
class App extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'provisioning_mode' => ProvisioningMode::class,
            'license_tiers' => 'array',
            'supports_license_tiers' => 'boolean',
            'costs_money' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function accesses(): HasMany
    {
        return $this->hasMany(AppAccess::class);
    }

    public function roleTemplateApps(): HasMany
    {
        return $this->hasMany(RoleTemplateApp::class);
    }

    public function isManual(): bool
    {
        return $this->provisioning_mode === ProvisioningMode::Manual;
    }

    public function canAttemptApi(): bool
    {
        return in_array(
            $this->provisioning_mode,
            [ProvisioningMode::Automated, ProvisioningMode::Semi],
            true,
        );
    }
}
