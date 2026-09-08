<?php

namespace App\Filament\Resources\RoleTemplates\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TemplateAppsRelationManager extends RelationManager
{
    protected static string $relationship = 'templateApps';

    protected static ?string $title = 'Apps in this template';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('app_id')
                ->relationship('app', 'name')
                ->label('App')
                ->required()
                ->native(false)
                ->preload(),

            TextInput::make('license_tier')
                ->helperText('Leave blank when the app has no tiers.'),

            Toggle::make('required')
                ->default(true)
                ->helperText('Optional apps are suggested but not treated as missing.')
                ->inline(false),

            KeyValue::make('scopes')
                ->helperText('Groups, collections or departments, e.g. departments = all')
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('app.name')->label('App')->sortable(),

                TextColumn::make('app.provisioning_mode')
                    ->label('Provisioning')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->label())
                    ->color(fn ($state) => $state->color()),

                TextColumn::make('license_tier')->label('Tier')->badge()->placeholder('—'),

                IconColumn::make('required')->boolean(),

                TextColumn::make('scopes')
                    ->formatStateUsing(fn ($state) => $state
                        ? collect($state)->map(fn ($v, $k) => $k.': '.(is_array($v) ? implode('/', $v) : $v))->implode(', ')
                        : '—')
                    ->wrap()
                    ->toggleable(),
            ])
            ->headerActions([CreateAction::make()->label('Add app')])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
