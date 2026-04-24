<?php

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\RoleResource\Pages;
use App\Models\Role;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Roles';

    public static function canViewAny(): bool
    {
        $user = auth((string) config('admin.guard', 'admin'))->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_OWNER)
                || $user->hasPermission(PermissionNames::ADMIN_ORG_MANAGE)
            );
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.admin');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.roles');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(64),
                Forms\Components\Textarea::make('description')
                    ->rows(3)
                    ->maxLength(255),
                Forms\Components\CheckboxList::make('permissions')
                    ->relationship('permissions', 'name')
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ops.nav.roles'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Role $record): ?string => $record->description),
                Tables\Columns\TextColumn::make('description')
                    ->label(__('ops.resources.taxonomy.fields.description'))
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->counts('permissions')
                    ->label(__('ops.table.permissions'))
                    ->badge()
                    ->color('gray'),
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
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }
}
