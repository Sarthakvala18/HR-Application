<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Audit log';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 30;

    /** The log is evidence: nobody edits or deletes it from the UI. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && ($user->isSuperAdmin() || $user->isHrAdmin());
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('Who')
                    ->placeholder('system')
                    ->description(fn (AuditLog $record) => $record->user?->email),

                TextColumn::make('action')
                    ->badge()
                    ->color(fn (AuditLog $record) => $record->isSensitiveAccess() ? 'danger' : 'gray')
                    ->formatStateUsing(fn (string $state) => str_replace(['_', '.'], [' ', ' · '], $state))
                    ->searchable(),

                TextColumn::make('auditable_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state, AuditLog $record) => $state
                        ? class_basename($state).' #'.$record->auditable_id
                        : '—'),

                TextColumn::make('reason')
                    ->wrap()
                    ->placeholder('—')
                    ->limit(80),

                TextColumn::make('ip')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->options(fn () => AuditLog::query()
                        ->distinct()
                        ->orderBy('action')
                        ->pluck('action', 'action')
                        ->all())
                    ->multiple(),

                Filter::make('sensitive')
                    ->label('Payout data access only')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('action', 'like', 'payment_details.%')),
            ])
            ->emptyStateHeading('Nothing logged yet');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }
}
