<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Employee;
use App\Models\EmployeePaymentDetail;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class EmployeeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([

                Tab::make('Profile')->schema([
                    Section::make()->columns(3)->schema([
                        TextEntry::make('full_name')->label('Full name'),
                        TextEntry::make('preferred_name')->placeholder('—'),
                        TextEntry::make('position')->placeholder('—'),
                        TextEntry::make('department.name')->label('Department')->badge()->placeholder('—'),
                        TextEntry::make('manager.full_name')->label('Manager')->placeholder('—'),
                        TextEntry::make('roleTemplate.name')->label('Role template')->placeholder('—'),
                        TextEntry::make('status')
                            ->badge()
                            ->formatStateUsing(fn (EmployeeStatus $state) => $state->label())
                            ->color(fn (EmployeeStatus $state) => $state->color()),
                        TextEntry::make('employment_type')
                            ->label('Employment type')
                            ->badge()
                            ->color('gray')
                            ->formatStateUsing(fn (EmploymentType $state) => $state->label()),
                        TextEntry::make('country')->placeholder('—'),
                    ]),

                    Section::make('Contact')
                        ->columns(3)
                        ->visible(fn (Employee $record) => $record->canBeSeenBy(Auth::user(), 'personal_email'))
                        ->schema([
                            TextEntry::make('work_email')->copyable()->placeholder('not created yet'),
                            TextEntry::make('personal_email')->copyable()->placeholder('—'),
                            TextEntry::make('phone')->placeholder('—'),
                        ]),

                    Section::make('Dates')->columns(3)->schema([
                        TextEntry::make('date_of_joining')->date('d M Y')->placeholder('—'),
                        TextEntry::make('date_of_exit')->date('d M Y')->placeholder('—'),
                        // Day and month only unless the viewer may see the year.
                        TextEntry::make('birthday')
                            ->label('Birthday')
                            ->state(fn (Employee $record) => $record->canBeSeenBy(Auth::user(), 'notes')
                                ? $record->birthday?->format('d M Y')
                                : $record->birthdayDayMonth())
                            ->placeholder('—'),
                    ]),

                    Section::make('Compensation')
                        ->columns(3)
                        ->visible(fn () => Auth::user()?->canSeeFinancials() ?? false)
                        ->schema([
                            TextEntry::make('salary_amount')->label('Salary')->placeholder('—'),
                            TextEntry::make('salary_currency')->label('Currency')->placeholder('—'),
                            TextEntry::make('salary_period')->label('Period')->placeholder('—'),
                        ]),

                    Section::make('HR notes')
                        ->visible(fn (Employee $record) => $record->canBeSeenBy(Auth::user(), 'notes'))
                        ->schema([
                            TextEntry::make('notes')->hiddenLabel()->placeholder('No notes.'),
                        ]),
                ]),

                Tab::make('Payout')
                    // When no payout record exists yet there is no model to
                    // authorise against, so fall back to the class-level rule.
                    ->visible(function (Employee $record): bool {
                        $user = Auth::user();

                        if ($user === null) {
                            return false;
                        }

                        $detail = $record->paymentDetail;

                        return $detail !== null
                            ? $user->can('view', $detail)
                            : $user->can('viewAny', EmployeePaymentDetail::class);
                    })
                    ->schema([
                        Section::make()
                            ->description('Masked by default. Revealing the full account number is recorded in the audit log.')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('paymentDetail.account_last4')
                                    ->label('Account number')
                                    ->state(fn (Employee $record) => $record->paymentDetail?->maskedAccountNumber() ?? '—'),
                                TextEntry::make('paymentDetail.payout_currency')->label('Currency')->placeholder('—'),
                                TextEntry::make('paymentDetail.bank_country')->label('Bank country')->placeholder('—'),
                                TextEntry::make('paymentDetail.bank_name')->label('Bank')->placeholder('—'),
                                TextEntry::make('paymentDetail.source')->label('Source')->badge()->placeholder('—'),
                                TextEntry::make('paymentDetail.verified_at')
                                    ->label('Verified')
                                    ->dateTime('d M Y')
                                    ->placeholder('Not verified'),
                            ]),
                    ]),
            ]),
        ]);
    }
}
