<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\ScaleSlugResource\Pages;
use App\Models\ScaleSlug;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScaleSlugResource extends Resource
{
    protected static ?string $model = ScaleSlug::class;

    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Scale Slugs';

    public static function canViewAny(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_CONTENT_READ)
                || $user->hasPermission(PermissionNames::ADMIN_OWNER)
            );
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('org_id')
                ->numeric()
                ->required()
                ->default(fn () => max(0, (int) app(OrgContext::class)->orgId())),
            Forms\Components\TextInput::make('scale_code')
                ->required()
                ->maxLength(64),
            Forms\Components\TextInput::make('slug')
                ->required()
                ->maxLength(127),
            Forms\Components\Toggle::make('is_primary')->default(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('org_id')->sortable(),
                Tables\Columns\TextColumn::make('scale_code')->sortable()->searchable(),
                Tables\Columns\TextColumn::make('slug')->searchable()->copyable(),
                Tables\Columns\IconColumn::make('is_primary')->boolean(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScaleSlugs::route('/'),
            'create' => Pages\CreateScaleSlug::route('/create'),
            'edit' => Pages\EditScaleSlug::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());

        return parent::getEloquentQuery()
            ->whereIn('org_id', $orgId > 0 ? [0, $orgId] : [0]);
    }
}
