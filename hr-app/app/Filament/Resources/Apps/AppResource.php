<?php

namespace App\Filament\Resources\Apps;

use App\Filament\Resources\Apps\Pages\ListApps;
use App\Filament\Resources\Apps\Schemas\AppForm;
use App\Filament\Resources\Apps\Tables\AppsTable;
use App\Models\App;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class AppResource extends Resource
{
    protected static ?string $model = App::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'App catalog';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return AppForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AppsTable::configure($table);
    }

    /** The catalog defines provisioning behaviour, so only admins may edit it. */
    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && ($user->isSuperAdmin() || $user->isHrAdmin());
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->isSuperAdmin() ?? false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApps::route('/'),
        ];
    }
}
