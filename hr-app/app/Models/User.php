<?php

namespace App\Models;

use App\Enums\UserRole;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'google_id',
        'employee_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->role->canAccessPanel();
    }

    // ------------------------------------------------------------ role helpers

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SuperAdmin;
    }

    public function isHrAdmin(): bool
    {
        return $this->role === UserRole::HrAdmin;
    }

    public function isFinance(): bool
    {
        return $this->role === UserRole::Finance;
    }

    public function isManager(): bool
    {
        return $this->role === UserRole::Manager;
    }

    public function canSeeFinancials(): bool
    {
        return $this->role->canSeeFinancials();
    }

    public function canRunProcesses(): bool
    {
        return $this->role->canRunProcesses();
    }

    public function canTriggerOffboarding(): bool
    {
        return $this->role->canTriggerOffboarding();
    }

    /** True when the given employee reports to this user's own employee record. */
    public function managesEmployee(Employee $employee): bool
    {
        return $this->employee_id !== null
            && $employee->manager_id === $this->employee_id;
    }
}
