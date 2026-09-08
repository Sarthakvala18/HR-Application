<?php

namespace App\Filament\Pages;

use App\Enums\AccessStatus;
use App\Enums\EmployeeStatus;
use App\Models\App as AppModel;
use App\Models\AppAccess;
use App\Models\Employee;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Every person against every app, in one grid.
 *
 * The point of this screen is the question manual offboarding cannot answer:
 * who still has access to what, and which of them have already left.
 */
class AccessMatrix extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?string $navigationLabel = 'Access matrix';

    protected static string|\UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.access-matrix';

    #[Url]
    public string $scope = 'current';

    #[Url]
    public string $search = '';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->role->canAccessPanel();
    }

    public function getTitle(): string
    {
        return 'Access matrix';
    }

    public function getSubheading(): ?string
    {
        return 'Who holds what, across every system. Red cells are access held by someone who has already left.';
    }

    /** @return array<string, string> */
    public function scopeOptions(): array
    {
        return [
            'current' => 'Current people',
            'risk' => 'Exited with live access',
            'joining' => 'Joining',
            'all' => 'Everyone',
        ];
    }

    public function apps(): Collection
    {
        return AppModel::query()
            ->where('active', true)
            ->orderByDesc('offboard_priority')
            ->get();
    }

    public function employees(): Collection
    {
        $query = Employee::query()
            ->with(['accesses.app', 'department'])
            ->orderBy('full_name');

        match ($this->scope) {
            'current' => $query->whereIn('status', [
                EmployeeStatus::Active->value,
                EmployeeStatus::OnNotice->value,
                EmployeeStatus::Offboarding->value,
            ]),
            'joining' => $query->where('status', EmployeeStatus::PreOnboarding),
            'risk' => $query->where('status', EmployeeStatus::Exited)
                ->whereHas('accesses', fn ($q) => $q->live()),
            default => null,
        };

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(fn ($q) => $q
                ->where('full_name', 'like', $term)
                ->orWhere('work_email', 'like', $term)
                ->orWhere('position', 'like', $term));
        }

        // Managers only ever see their own reports.
        $user = Auth::user();
        if ($user instanceof User && $user->isManager()) {
            $query->where(fn ($q) => $q
                ->where('manager_id', $user->employee_id)
                ->orWhere('id', $user->employee_id));
        }

        return $query->limit(200)->get();
    }

    /** Status of one employee/app cell, or null when no row exists. */
    public function cellStatus(Employee $employee, AppModel $app): ?AccessStatus
    {
        return $employee->accesses
            ->firstWhere('app_id', $app->id)
            ?->status;
    }

    public function cellTier(Employee $employee, AppModel $app): ?string
    {
        return $employee->accesses->firstWhere('app_id', $app->id)?->license_tier;
    }

    /** Access that is live for someone who has left: the number that matters. */
    public function riskCount(): int
    {
        return Employee::withLingeringAccess()->count();
    }

    public function billableSeatCount(): int
    {
        return AppAccess::query()
            ->live()
            ->whereNotNull('license_tier')
            ->whereHas('app', fn ($q) => $q->where('costs_money', true))
            ->count();
    }

    /** Paid seats still held by people who have left: direct wasted spend. */
    public function wastedSeatCount(): int
    {
        return AppAccess::query()
            ->live()
            ->whereNotNull('license_tier')
            ->whereHas('app', fn ($q) => $q->where('costs_money', true))
            ->whereHas('employee', fn ($q) => $q->where('status', EmployeeStatus::Exited))
            ->count();
    }
}
