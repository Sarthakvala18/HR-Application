<?php

namespace App\Filament\Widgets;

use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Birthdays and work anniversaries in the next 30 days.
 *
 * These drive the celebration posts that are written by hand today. Only the
 * day and month are shown, never the birth year.
 */
class UpcomingCelebrations extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Coming up in the next 30 days';

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->role->canAccessPanel();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->celebrationsQuery())
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('full_name')->label('Person'),

                TextColumn::make('department.name')->label('Department')->badge()->placeholder('—'),

                TextColumn::make('birthday')
                    ->label('Birthday')
                    // Day and month only: the year stays private.
                    ->formatStateUsing(fn ($state) => $state ? CarbonImmutable::parse($state)->format('d M') : '—')
                    ->placeholder('—'),

                TextColumn::make('date_of_joining')
                    ->label('Anniversary')
                    ->formatStateUsing(function ($state) {
                        if (! $state) {
                            return '—';
                        }

                        $joined = CarbonImmutable::parse($state);
                        $years = $joined->diffInYears(now());

                        return $joined->format('d M').($years > 0 ? " · {$years} yr" : '');
                    })
                    ->placeholder('—'),
            ])
            ->emptyStateHeading('Nothing in the next 30 days');
    }

    /**
     * Matches on day-of-year rather than full date, so it works across years.
     * Kept in PHP-friendly SQL to stay portable between SQLite and MySQL.
     */
    private function celebrationsQuery(): Builder
    {
        $window = collect(range(0, 30))
            ->map(fn (int $days) => now()->addDays($days)->format('m-d'))
            ->all();

        return Employee::query()
            ->active()
            ->where(function (Builder $query) use ($window) {
                $query
                    ->whereIn($this->monthDayExpression('birthday'), $window)
                    ->orWhereIn($this->monthDayExpression('date_of_joining'), $window);
            });
    }

    private function monthDayExpression(string $column): Expression
    {
        $driver = Employee::query()->getConnection()->getDriverName();

        return $driver === 'sqlite'
            ? DB::raw("strftime('%m-%d', {$column})")
            : DB::raw("DATE_FORMAT({$column}, '%m-%d')");
    }
}
