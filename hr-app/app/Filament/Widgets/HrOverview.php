<?php

namespace App\Filament\Widgets;

use App\Enums\AccessStatus;
use App\Enums\EmployeeStatus;
use App\Filament\Pages\AccessMatrix;
use App\Filament\Resources\FormSubmissions\FormSubmissionResource;
use App\Models\AppAccess;
use App\Models\Employee;
use App\Models\FormSubmission;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class HrOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Where things stand';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->role->canAccessPanel();
    }

    protected function getStats(): array
    {
        $lingering = Employee::withLingeringAccess()->count();
        $pendingReviews = FormSubmission::where('review_status', 'pending')->count();
        $awaitingManual = AppAccess::where('status', AccessStatus::PendingManual)->count();

        return [
            Stat::make('Active people', Employee::active()->count())
                ->description('On the books today')
                ->color('success'),

            // The number this system exists to keep at zero.
            Stat::make('Exited with live access', $lingering)
                ->description($lingering > 0 ? 'Revoke these now' : 'Nothing outstanding')
                ->color($lingering > 0 ? 'danger' : 'success')
                ->url($lingering > 0 ? AccessMatrix::getUrl(['scope' => 'risk']) : null),

            Stat::make('Awaiting a manual step', $awaitingManual)
                ->description('Google and Bitwarden task cards')
                ->color($awaitingManual > 0 ? 'warning' : 'gray'),

            Stat::make('Submissions to review', $pendingReviews)
                ->description('Imported, not yet applied')
                ->color($pendingReviews > 0 ? 'warning' : 'gray')
                ->url($pendingReviews > 0 ? FormSubmissionResource::getUrl() : null),

            Stat::make('Joining soon', Employee::where('status', EmployeeStatus::PreOnboarding)->count())
                ->description('Pre-onboarding')
                ->color('info'),
        ];
    }
}
