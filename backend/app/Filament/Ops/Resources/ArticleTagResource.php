<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\ArticleTagResource\Pages;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\ArticleTag;
use App\Support\OrgContext;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ArticleTagResource extends Resource
{
    protected static ?string $model = ArticleTag::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = null;

    protected static ?string $navigationLabel = null;

    public static function canViewAny(): bool
    {
        return self::canRead();
    }

    public static function canCreate(): bool
    {
        return self::canWrite();
    }

    public static function canEdit($record): bool
    {
        return self::canWrite();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.taxonomy');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.article_tags');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Hidden::make('org_id')
                ->default(fn (): int => max(0, (int) app(OrgContext::class)->orgId())),
            TextInput::make('name')
                ->required()
                ->maxLength(64),
            TextInput::make('slug')
                ->required()
                ->maxLength(127)
                ->afterStateUpdated(fn ($state, $set): mixed => $set('slug', Str::slug((string) $state))),
            Toggle::make('is_active')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('is_active')
                    ->label(__('ops.status.label'))
                    ->formatStateUsing(fn (bool|int|string|null $state): string => StatusBadge::booleanLabel($state, __('ops.status.active'), __('ops.status.inactive')))
                    ->badge()
                    ->color(fn (bool|int|string|null $state): string => StatusBadge::booleanColor($state))
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('ops.status.label')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArticleTags::route('/'),
            'create' => Pages\CreateArticleTag::route('/create'),
            'edit' => Pages\EditArticleTag::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('org_id', self::currentOrgId());
    }

    private static function currentOrgId(): int
    {
        return max(0, (int) app(OrgContext::class)->orgId());
    }

    private static function canRead(): bool
    {
        return ContentAccess::canRead();
    }

    private static function canWrite(): bool
    {
        return ContentAccess::canWrite();
    }
}
