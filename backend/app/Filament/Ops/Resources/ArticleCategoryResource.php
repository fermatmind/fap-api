<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\ArticleCategoryResource\Pages;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\ArticleCategory;
use App\Support\OrgContext;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
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

class ArticleCategoryResource extends Resource
{
    protected static ?string $model = ArticleCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-folder';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Categories';

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
        return __('ops.group.content_data');
    }

    public static function getNavigationLabel(): string
    {
        return 'Categories';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Hidden::make('org_id')
                ->default(fn (): int => max(0, (int) app(OrgContext::class)->orgId())),
            TextInput::make('name')
                ->required()
                ->maxLength(128),
            TextInput::make('slug')
                ->required()
                ->maxLength(127)
                ->afterStateUpdated(fn ($state, $set): mixed => $set('slug', Str::slug((string) $state))),
            Textarea::make('description')
                ->rows(4),
            TextInput::make('sort_order')
                ->numeric()
                ->default(0),
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
                    ->label('Status')
                    ->formatStateUsing(fn (bool|int|string|null $state): string => StatusBadge::booleanLabel($state, 'active', 'inactive'))
                    ->badge()
                    ->color(fn (bool|int|string|null $state): string => StatusBadge::booleanColor($state))
                    ->sortable(),
                TextColumn::make('sort_order')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status'),
            ])
            ->defaultSort('sort_order', 'asc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArticleCategories::route('/'),
            'create' => Pages\CreateArticleCategory::route('/create'),
            'edit' => Pages\EditArticleCategory::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('org_id', self::tenantOrgIds());
    }

    /**
     * @return array<int,int>
     */
    private static function tenantOrgIds(): array
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());

        return $orgId > 0 ? [0, $orgId] : [0];
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
