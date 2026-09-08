<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Enums\AccessStatus;
use App\Enums\ProvisioningMode;
use App\Models\AppAccess;
use App\Models\Employee;
use App\Services\AccessProvisioner;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class AccessesRelationManager extends RelationManager
{
    protected static string $relationship = 'accesses';

    protected static ?string $title = 'Access';

    protected static ?string $recordTitleAttribute = 'id';

    protected static bool $isLazy = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('app_id')
                ->label('App')
                ->relationship('app', 'name')
                ->required()
                ->native(false)
                ->searchable()
                ->preload(),

            Select::make('status')
                ->options(AccessStatus::options())
                ->required()
                ->default(AccessStatus::Requested->value)
                ->native(false),

            TextInput::make('license_tier')
                ->helperText('e.g. pro, member, single_channel_guest'),

            TextInput::make('external_id')
                ->label('External ID')
                ->helperText('The account id in that system, captured as evidence.'),

            Textarea::make('notes')->rows(2)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('app_id')
            ->columns([
                TextColumn::make('app.name')
                    ->label('App')
                    ->description(fn (AppAccess $record) => $record->app?->provisioning_mode->label())
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (AccessStatus $state) => $state->label())
                    ->color(fn (AccessStatus $state) => $state->color()),

                TextColumn::make('license_tier')
                    ->label('Tier')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),

                TextColumn::make('external_id')
                    ->label('Account ID')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(),

                TextColumn::make('granted_at')
                    ->dateTime('d M Y')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('revoked_at')
                    ->dateTime('d M Y')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_error')
                    ->label('Error')
                    ->color('danger')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(AccessStatus::options())->multiple(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Grant access')
                    ->visible(fn () => Auth::user()?->canRunProcesses() ?? false),

                Action::make('applyTemplate')
                    ->label('Apply role template')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->color('gray')
                    ->visible(fn () => (Auth::user()?->canRunProcesses() ?? false)
                        && $this->getOwnerRecord()->role_template_id !== null)
                    ->requiresConfirmation()
                    ->modalDescription('Creates any access rows the role template expects that are missing. Existing rows are left alone.')
                    ->action(function () {
                        /** @var Employee $employee */
                        $employee = $this->getOwnerRecord();

                        $created = app(AccessProvisioner::class)->applyRoleTemplate($employee);

                        Notification::make()
                            ->title($created === 0 ? 'Nothing to add' : "Added {$created} access row(s)")
                            ->body($created === 0
                                ? 'Every app in the template already has a row.'
                                : 'Manual apps are queued as task cards; nothing was provisioned automatically.')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('grant')
                    ->label('Mark granted')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (AppAccess $record) => ! $record->status->isLive()
                        && (Auth::user()?->can('grant', $record) ?? false))
                    ->schema(fn (AppAccess $record) => array_filter([
                        $record->app?->provisioning_mode === ProvisioningMode::Manual
                            ? TextInput::make('external_id')
                                ->label('Account ID or email created')
                                ->required()
                                ->helperText('Manual steps must record what was actually created.')
                            : null,
                        $record->app?->supports_license_tiers
                            ? Select::make('license_tier')
                                ->options(fn () => collect($record->app?->license_tiers ?? [])
                                    ->mapWithKeys(fn ($t) => [$t => $t])->all())
                                ->native(false)
                            : null,
                    ]))
                    ->action(function (AppAccess $record, array $data) {
                        $record->update([
                            'status' => AccessStatus::Active,
                            'granted_at' => now(),
                            'granted_by' => Auth::id(),
                            'external_id' => $data['external_id'] ?? $record->external_id,
                            'license_tier' => $data['license_tier'] ?? $record->license_tier,
                            'last_error' => null,
                        ]);

                        app(AuditLogger::class)->log(
                            AuditLogger::ACCESS_GRANTED,
                            $record,
                            ['app' => $record->app?->key],
                        );

                        Notification::make()->title('Access recorded')->success()->send();
                    }),

                Action::make('revoke')
                    ->label('Revoke')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (AppAccess $record) => $record->status->isLive()
                        && (Auth::user()?->can('revoke', $record) ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(fn (AppAccess $record) => $record->app?->isManual()
                        ? 'This app is manual: record the revocation here after doing it in the console.'
                        : 'Marks the access revoked in this system.')
                    ->action(function (AppAccess $record) {
                        $record->update([
                            'status' => AccessStatus::Revoked,
                            'revoked_at' => now(),
                            'revoked_by' => Auth::id(),
                        ]);

                        app(AuditLogger::class)->log(
                            AuditLogger::ACCESS_REVOKED,
                            $record,
                            ['app' => $record->app?->key],
                        );

                        Notification::make()->title('Access revoked')->success()->send();
                    }),

                Action::make('instructions')
                    ->label('How to')
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->color('gray')
                    ->visible(fn (AppAccess $record) => filled($record->app?->onboard_instructions_md))
                    ->modalHeading(fn (AppAccess $record) => $record->app?->name.' — task card')
                    ->modalContent(fn (AppAccess $record) => view('filament.partials.task-card', [
                        'app' => $record->app,
                        'employee' => $this->getOwnerRecord(),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),

                EditAction::make()->visible(fn () => Auth::user()?->canRunProcesses() ?? false),
            ]);
    }

    public function isReadOnly(): bool
    {
        return false;
    }
}
