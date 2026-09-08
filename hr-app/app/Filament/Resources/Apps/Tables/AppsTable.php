<?php

namespace App\Filament\Resources\Apps\Tables;

use App\Enums\ProvisioningMode;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AppsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('offboard_priority', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn ($record) => $record->key),

                TextColumn::make('provisioning_mode')
                    ->badge()
                    ->label('Provisioning')
                    ->formatStateUsing(fn (ProvisioningMode $state) => $state->label())
                    ->color(fn (ProvisioningMode $state) => $state->color()),

                TextColumn::make('license_tiers')
                    ->label('Tiers')
                    ->badge()
                    ->placeholder('—'),

                IconColumn::make('costs_money')
                    ->label('Paid')
                    ->boolean(),

                TextColumn::make('accesses_count')
                    ->counts('accesses')
                    ->label('Grants')
                    ->sortable(),

                TextColumn::make('offboard_priority')
                    ->label('Revoke order')
                    ->numeric()
                    ->sortable()
                    ->tooltip('Higher runs first during offboarding.')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('active')->boolean(),
            ])
            ->filters([
                SelectFilter::make('provisioning_mode')
                    ->options(ProvisioningMode::options())
                    ->label('Provisioning'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
