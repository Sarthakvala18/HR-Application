<?php

namespace App\Filament\Resources\RoleTemplates;

use App\Filament\Resources\RoleTemplates\Pages\ListRoleTemplates;
use App\Filament\Resources\RoleTemplates\RelationManagers\TemplateAppsRelationManager;
use App\Models\RoleTemplate;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class RoleTemplateResource extends Resource
{
    protected static ?string $model = RoleTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $navigationLabel = 'Role templates';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user instanceof User && ($user->isSuperAdmin() || $user->isHrAdmin());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('key')->required()->disabledOn('edit'),
                    TextInput::make('name')->required(),
                    Select::make('department_id')
                        ->relationship('department', 'name')
                        ->native(false)
                        ->preload(),
                    Toggle::make('active')->default(true)->inline(false),
                    Textarea::make('description')->rows(2)->columnSpanFull(),
                ]),

            Section::make('Roles and responsibilities')
                ->description('Seeds the appointment letter. Markdown list, one duty per line.')
                ->schema([
                    Textarea::make('responsibilities_md')
                        ->hiddenLabel()
                        ->rows(8)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (RoleTemplate $record) => $record->description)
                    ->wrap(),

                TextColumn::make('department.name')->badge()->placeholder('—'),

                TextColumn::make('apps_count')
                    ->counts('apps')
                    ->label('Apps granted')
                    ->badge(),

                TextColumn::make('employees_count')
                    ->counts('employees')
                    ->label('People')
                    ->badge()
                    ->color('gray'),

                IconColumn::make('active')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            TemplateAppsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoleTemplates::route('/'),
            'edit' => Pages\EditRoleTemplate::route('/{record}/edit'),
        ];
    }
}
