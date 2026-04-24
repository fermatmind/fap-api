<?php

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\PermissionResource\Pages;
use App\Models\Permission;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PermissionResource extends Resource
{
    protected static ?string $model = Permission::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Permissions';

    public static function canViewAny(): bool
    {
        $user = auth((string) config('admin.guard', 'admin'))->user();

        return $user !== null
            && method_exists($user, 'hasPermission')
            && $user->hasPermission(PermissionNames::ADMIN_OWNER);
    }

    public static function canCreate(): bool
    {
        return self::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return self::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return self::canViewAny();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.admin');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.permissions');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(128),
                Forms\Components\Textarea::make('description')
                    ->rows(2)
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ops.nav.permissions'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Permission $record): ?string => $record->description),
                Tables\Columns\TextColumn::make('description')
                    ->label(__('ops.resources.taxonomy.fields.description'))
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ops.resources.articles.fields.created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label(__('ops.resources.articles.actions.edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPermissions::route('/'),
            'create' => Pages\CreatePermission::route('/create'),
            'edit' => Pages\EditPermission::route('/{record}/edit'),
        ];
    }
}
