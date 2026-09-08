<?php

namespace App\Filament\Resources\Employees\Tables;

use App\Enums\AccessStatus;
use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Employee;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('full_name')
            ->columns([
                TextColumn::make('full_name')
                    ->label('Name')
                    ->searchable(['full_name', 'preferred_name'])
                    ->sortable()
                    ->description(fn (Employee $record) => $record->position)
                    ->weight('medium'),

                TextColumn::make('work_email')
                    ->searchable()
                    ->copyable()
                    ->placeholder('not created yet')
                    ->toggleable(),

                TextColumn::make('department.name')
                    ->badge()
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (EmployeeStatus $state) => $state->label())
                    ->color(fn (EmployeeStatus $state) => $state->color())
                    ->sortable(),

                TextColumn::make('employment_type')
                    ->label('Type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (EmploymentType $state) => $state->label())
                    ->toggleable(),

                TextColumn::make('manager.full_name')
                    ->label('Manager')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                // The security signal: live access held by someone who has left.
                TextColumn::make('live_accesses_count')
                    ->label('Live access')
                    ->counts([
                        'accesses' => fn (Builder $query) => $query->live(),
                    ])
                    ->badge()
                    ->color(fn ($state, Employee $record) => $record->status === EmployeeStatus::Exited && $state > 0
                        ? 'danger'
                        : 'gray')
                    ->tooltip(fn ($state, Employee $record) => $record->status === EmployeeStatus::Exited && $state > 0
                        ? 'This person has left but still holds access.'
                        : null),

                TextColumn::make('date_of_joining')
                    ->date('d M Y')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(EmployeeStatus::options())
                    ->multiple()
                    ->default([
                        EmployeeStatus::Active->value,
                        EmployeeStatus::PreOnboarding->value,
                        EmployeeStatus::OnNotice->value,
                        EmployeeStatus::Offboarding->value,
                    ]),

                SelectFilter::make('department')
                    ->relationship('department', 'name')
                    ->multiple()
                    ->preload(),

                SelectFilter::make('employment_type')
                    ->options(EmploymentType::options())
                    ->multiple(),

                Filter::make('lingering_access')
                    ->label('Exited but still has access')
                    ->toggle()
                    ->query(fn (Builder $query) => $query
                        ->where('status', EmployeeStatus::Exited)
                        ->whereHas('accesses', fn (Builder $q) => $q->whereIn('status', [
                            AccessStatus::Active->value,
                            AccessStatus::RevokePending->value,
                        ]))),

                Filter::make('missing_work_email')
                    ->label('No work account yet')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNull('work_email')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateHeading('No people yet')
            ->emptyStateDescription('Import the Typeform history with hr:import-typeform, or add someone directly.');
    }
}
