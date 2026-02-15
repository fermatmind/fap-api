<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\ContentPackVersionResource\Pages;
use App\Models\ContentPackVersion;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ContentPackVersionResource extends Resource
{
    protected static ?string $model = ContentPackVersion::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Content Pack Versions';

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

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.content_pack_versions');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Grid::make(2)
                ->schema([
                    Forms\Components\TextInput::make('region')->required()->maxLength(32),
                    Forms\Components\TextInput::make('locale')->required()->maxLength(32),
                    Forms\Components\TextInput::make('pack_id')->required()->maxLength(128),
                    Forms\Components\TextInput::make('content_package_version')->required()->maxLength(64),
                    Forms\Components\TextInput::make('dir_version_alias')->required()->maxLength(128),
                    Forms\Components\TextInput::make('sha256')->required()->maxLength(64),
                    Forms\Components\TextInput::make('source_type')->required()->maxLength(32),
                    Forms\Components\TextInput::make('created_by')->maxLength(64),
                ]),
            Forms\Components\Textarea::make('source_ref')->required()->rows(2),
            Forms\Components\Textarea::make('extracted_rel_path')->required()->rows(2),
            Forms\Components\Textarea::make('manifest_json')
                ->label('Manifest JSON')
                ->required()
                ->rows(12)
                ->formatStateUsing(function (mixed $state): string {
                    if (is_array($state)) {
                        return (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    }

                    return is_string($state) && trim($state) !== '' ? $state : '{}';
                })
                ->dehydrateStateUsing(function (string $state): array {
                    $decoded = json_decode($state, true);

                    return is_array($decoded) ? $decoded : [];
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('pack_id')->searchable(),
                Tables\Columns\TextColumn::make('content_package_version')->label('Version')->searchable(),
                Tables\Columns\TextColumn::make('dir_version_alias')->label('Dir Alias')->searchable(),
                Tables\Columns\TextColumn::make('region')->sortable(),
                Tables\Columns\TextColumn::make('locale')->sortable(),
                Tables\Columns\TextColumn::make('sha256')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContentPackVersions::route('/'),
            'create' => Pages\CreateContentPackVersion::route('/create'),
            'edit' => Pages\EditContentPackVersion::route('/{record}/edit'),
        ];
    }
}
