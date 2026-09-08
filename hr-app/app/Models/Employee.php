<?php

namespace App\Models;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Concerns\HasRedactableAttributes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Employee extends Model
{
    use HasFactory;
    use HasRedactableAttributes;
    use SoftDeletes;

    protected $guarded = ['id'];

    /**
     * Sensitive attributes and the policy ability that unlocks each one.
     * Enforced at the model layer via toVisibleArray(), so these cannot leak
     * through an export or an API response.
     *
     * @return array<string, string>
     */
    public function redactableAttributes(): array
    {
        return [
            'salary_amount' => 'viewSalary',
            'salary_currency' => 'viewSalary',
            'salary_period' => 'viewSalary',
            'personal_email' => 'viewContactDetails',
            'phone' => 'viewContactDetails',
            'notes' => 'viewNotes',
        ];
    }

    protected function casts(): array
    {
        return [
            'status' => EmployeeStatus::class,
            'employment_type' => EmploymentType::class,
            'date_of_joining' => 'date',
            'date_of_exit' => 'date',
            'birthday' => 'date',
            'birth_year_known' => 'boolean',
            'is_entity' => 'boolean',
            'salary_amount' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Employee $employee) {
            $employee->uuid ??= (string) Str::uuid();
        });
    }

    // ---------------------------------------------------------------- relations

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    public function roleTemplate(): BelongsTo
    {
        return $this->belongsTo(RoleTemplate::class);
    }

    public function paymentDetail(): HasOne
    {
        return $this->hasOne(EmployeePaymentDetail::class);
    }

    public function accesses(): HasMany
    {
        return $this->hasMany(AppAccess::class);
    }

    public function processRuns(): HasMany
    {
        return $this->hasMany(ProcessRun::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function formSubmissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    // ------------------------------------------------------------------ scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', EmployeeStatus::Active);
    }

    public function scopeExited(Builder $query): Builder
    {
        return $query->where('status', EmployeeStatus::Exited);
    }

    /**
     * The security payoff view: people who have left but still hold live access
     * somewhere. This is the query that catches what manual offboarding misses.
     */
    public function scopeWithLingeringAccess(Builder $query): Builder
    {
        return $query->where('status', EmployeeStatus::Exited)
            ->whereHas('accesses', fn (Builder $q) => $q->live());
    }

    // ------------------------------------------------------------------ helpers

    public function displayName(): string
    {
        return $this->preferred_name ?: $this->full_name;
    }

    /** Day and month only: safe to show company-wide for celebration posts. */
    public function birthdayDayMonth(): ?string
    {
        return $this->birthday?->format('d M');
    }

    public function isPastFinalWorkingDay(): bool
    {
        return $this->date_of_exit !== null && $this->date_of_exit->isPast();
    }

    /**
     * Where this person's mail and Drive data should go when they leave.
     * Marketing routes to the manager unless they were the manager.
     */
    public function offboardDestinationEmail(): ?string
    {
        $department = $this->department;

        if ($department === null) {
            return null;
        }

        if ($department->offboard_to_manager_first && $this->manager?->work_email) {
            return $this->manager->work_email;
        }

        return $department->offboard_destination_email;
    }
}
