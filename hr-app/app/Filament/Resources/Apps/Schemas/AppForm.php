<?php

namespace App\Filament\Resources\Apps\Schemas;

use App\Enums\ProvisioningMode;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AppForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->columns(2)
                    ->schema([
                        TextInput::make('key')
                            ->required()
                            ->helperText('Stable identifier used by the provisioning code. Do not rename once in use.')
                            ->disabledOn('edit'),
                        TextInput::make('name')->required(),
                        TextInput::make('console_url')
                            ->url()
                            ->label('Admin console URL')
                            ->helperText('Linked from the manual task card.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Provisioning')
                    ->columns(2)
                    ->schema([
                        Select::make('provisioning_mode')
                            ->options(ProvisioningMode::options())
                            ->required()
                            ->native(false)
                            ->helperText('Manual apps produce a guided task card instead of an API call.'),
                        TextInput::make('offboard_priority')
                            ->numeric()
                            ->required()
                            ->default(100)
                            ->helperText('Higher runs first when offboarding, so access-killing steps lead.'),
                        Toggle::make('supports_license_tiers')->inline(false),
                        Toggle::make('costs_money')
                            ->label('Paid seats')
                            ->helperText('Included in the licence guard.')
                            ->inline(false),
                        TagsInput::make('license_tiers')
                            ->placeholder('basic, pro')
                            ->columnSpanFull(),
                        Toggle::make('active')->default(true)->inline(false),
                    ]),

                Section::make('Task card instructions')
                    ->description('Shown verbatim to whoever performs the step. Keep the real click path.')
                    ->collapsed()
                    ->schema([
                        Textarea::make('onboard_instructions_md')
                            ->label('Onboarding')
                            ->rows(8)
                            ->columnSpanFull(),
                        Textarea::make('offboard_instructions_md')
                            ->label('Offboarding')
                            ->rows(8)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
