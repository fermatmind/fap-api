<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\OrganizationResource\Pages;
use App\Filament\Ops\Support\OpsTable;
use App\Models\Organization;
use App\Services\Ops\OrgVisibilityResolver;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Organizations';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return self::canManageOrganizations();
    }

    public static function canCreate(): bool
    {
        return self::canManageOrganizations();
    }

    public static function canEdit($record): bool
    {
        return self::canManageOrganizations();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.admin');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.organizations');
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Select::make('status')
                    ->required()
                    ->options([
                        'active' => 'active',
                        'suspended' => 'suspended',
                    ])
                    ->default('active'),
                Forms\Components\TextInput::make('domain')
                    ->maxLength(191),
                Forms\Components\TextInput::make('timezone')
                    ->required()
                    ->maxLength(64)
                    ->default('UTC'),
                Forms\Components\TextInput::make('locale')
                    ->required()
                    ->maxLength(16)
                    ->default('en-US'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('ops.nav.organizations'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (Organization $record): ?string => $record->domain),
                Tables\Columns\TextColumn::make('id')
                    ->label(__('ops.topbar.org_id'))
                    ->sortable()
                    ->copyable(),
                OpsTable::status(),
                Tables\Columns\TextColumn::make('domain')
                    ->label(__('ops.table.domain'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('timezone')
                    ->label(__('ops.table.timezone'))
                    ->toggleable(isToggledHiddenByDefault: true),
                OpsTable::locale(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('ops.resources.articles.fields.created'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                OpsTable::updatedAt(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('ops.table.status'))
                    ->options([
                        'active' => __('ops.status.active'),
                        'suspended' => __('ops.status.suspended'),
                    ]),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $guard = (string) config('admin.guard', 'admin');

        return app(OrgVisibilityResolver::class)
            ->applyEloquentVisibility(parent::getEloquentQuery(), auth($guard)->user());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrganizations::route('/'),
            'create' => Pages\CreateOrganization::route('/create'),
            'edit' => Pages\EditOrganization::route('/{record}/edit'),
        ];
    }

    private static function canManageOrganizations(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_OWNER)
                || $user->hasPermission(PermissionNames::ADMIN_ORG_MANAGE)
            );
    }
}
