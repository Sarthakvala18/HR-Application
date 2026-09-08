<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Enums\EmployeeStatus;
use App\Enums\EmploymentType;
use App\Models\Employee;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Person')
                    ->columns(2)
                    ->schema([
                        TextInput::make('full_name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(1),
                        TextInput::make('preferred_name')
                            ->helperText('Used in announcements if set.')
                            ->maxLength(255),
                        TextInput::make('personal_email')
                            ->email()
                            ->maxLength(255)
                            ->helperText('Where the forms and contract are sent before they have a work account.')
                            ->visible(fn () => self::canSeeContact()),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(50)
                            ->visible(fn () => self::canSeeContact()),
                        TextInput::make('work_email')
                            ->email()
                            ->maxLength(255)
                            ->helperText('Filled in once the Google account is created manually.'),
                        TextInput::make('employee_code')->maxLength(50),
                    ]),

                Section::make('Role')
                    ->columns(2)
                    ->schema([
                        Select::make('department_id')
                            ->relationship('department', 'name')
                            ->searchable()
                            ->preload()
                            ->native(false),
                        Select::make('role_template_id')
                            ->relationship('roleTemplate', 'name')
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->helperText('Drives which apps and licence tiers this person should receive.'),
                        Select::make('manager_id')
                            ->label('Manager')
                            ->relationship(
                                'manager',
                                'full_name',
                                // Nobody can be their own manager.
                                fn ($query, ?Employee $record) => $record
                                    ? $query->whereKeyNot($record->getKey())
                                    : $query,
                            )
                            ->searchable()
                            ->preload()
                            ->native(false),
                        TextInput::make('position')->maxLength(255),
                        Select::make('employment_type')
                            ->options(EmploymentType::options())
                            ->required()
                            ->default(EmploymentType::FullTime->value)
                            ->native(false),
                        Select::make('status')
                            ->options(EmployeeStatus::options())
                            ->required()
                            ->default(EmployeeStatus::PreOnboarding->value)
                            ->native(false),
                    ]),

                Section::make('Dates and location')
                    ->columns(3)
                    ->schema([
                        DatePicker::make('date_of_joining')->native(false),
                        DatePicker::make('date_of_exit')
                            ->native(false)
                            ->after('date_of_joining')
                            ->helperText('Setting this does not revoke access on its own.'),
                        DatePicker::make('birthday')
                            ->native(false)
                            ->helperText('Day and month are visible company-wide; the year is not.'),
                        TextInput::make('country')->maxLength(100),
                        Select::make('timezone')
                            ->options(fn () => collect(timezone_identifiers_list())
                                ->mapWithKeys(fn (string $tz) => [$tz => $tz])
                                ->all())
                            ->searchable()
                            ->native(false)
                            ->helperText('Used to set the Zoom profile correctly, which prevents the auto-recording problem.'),
                        Toggle::make('is_entity')
                            ->label('Company payee')
                            ->helperText('This payee is a company, not an individual.')
                            ->inline(false),
                    ]),

                Section::make('Compensation')
                    ->columns(3)
                    ->description('Encrypted at rest. Visible to Finance and Super Admin only.')
                    ->visible(fn () => Auth::user()?->canSeeFinancials() ?? false)
                    ->schema([
                        TextInput::make('salary_amount')
                            ->numeric()
                            ->prefix(fn ($get) => $get('salary_currency')),
                        TextInput::make('salary_currency')
                            ->maxLength(3)
                            ->default('USD'),
                        Select::make('salary_period')
                            ->options([
                                'monthly' => 'Monthly',
                                'yearly' => 'Yearly',
                                'hourly' => 'Hourly',
                                'per_project' => 'Per project',
                            ])
                            ->default('monthly')
                            ->native(false),
                    ]),

                Section::make('HR notes')
                    ->collapsed()
                    ->visible(fn () => self::canSeeNotes())
                    ->schema([
                        Textarea::make('notes')->rows(4)->columnSpanFull(),
                    ]),
            ]);
    }

    private static function canSeeContact(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && ($user->isSuperAdmin() || $user->isHrAdmin() || $user->isFinance());
    }

    private static function canSeeNotes(): bool
    {
        $user = Auth::user();

        return $user instanceof User && ($user->isSuperAdmin() || $user->isHrAdmin());
    }
}
