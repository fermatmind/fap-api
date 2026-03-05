<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\ArticleResource\Pages;
use App\Models\Article;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Components\BelongsToManyMultiSelect;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ArticleResource extends Resource
{
    protected static ?string $model = Article::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Articles';

    public static function canViewAny(): bool
    {
        return self::canRead();
    }

    public static function canCreate(): bool
    {
        return self::canPublish();
    }

    public static function canEdit($record): bool
    {
        return self::canPublish();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content');
    }

    public static function getNavigationLabel(): string
    {
        return 'Articles';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('Article')
                ->tabs([
                    Forms\Components\Tabs\Tab::make('Basic')
                        ->schema([
                            Forms\Components\Hidden::make('org_id')
                                ->default(fn (): int => max(0, (int) app(OrgContext::class)->orgId())),
                            Forms\Components\TextInput::make('title')
                                ->required()
                                ->maxLength(255),
                            Forms\Components\TextInput::make('slug')
                                ->required()
                                ->maxLength(127),
                            Forms\Components\TextInput::make('locale')
                                ->required()
                                ->maxLength(16)
                                ->default('en'),
                            Forms\Components\Select::make('category_id')
                                ->label('Category')
                                ->relationship(
                                    'category',
                                    'name',
                                    fn (Builder $query): Builder => $query->whereIn('article_categories.org_id', self::tenantOrgIds())
                                )
                                ->searchable()
                                ->preload(),
                            BelongsToManyMultiSelect::make('tags')
                                ->relationship(
                                    'tags',
                                    'name',
                                    fn (Builder $query): Builder => $query->whereIn('article_tags.org_id', self::tenantOrgIds())
                                )
                                ->searchable()
                                ->preload(),
                        ]),
                    Forms\Components\Tabs\Tab::make('Content')
                        ->schema([
                            Forms\Components\Textarea::make('excerpt')
                                ->rows(4),
                            Forms\Components\MarkdownEditor::make('content_md')
                                ->required()
                                ->columnSpanFull(),
                            Forms\Components\TextInput::make('cover_image_url')
                                ->maxLength(255),
                        ]),
                    Forms\Components\Tabs\Tab::make('SEO')
                        ->schema([
                            Forms\Components\Section::make('SEO Meta')
                                ->relationship('seoMeta')
                                ->schema([
                                    Forms\Components\TextInput::make('seo_title')
                                        ->maxLength(60),
                                    Forms\Components\Textarea::make('seo_description')
                                        ->rows(3)
                                        ->maxLength(160),
                                    Forms\Components\TextInput::make('canonical_url')
                                        ->maxLength(255),
                                    Forms\Components\TextInput::make('og_title')
                                        ->maxLength(90),
                                    Forms\Components\Textarea::make('og_description')
                                        ->rows(3)
                                        ->maxLength(200),
                                    Forms\Components\TextInput::make('og_image_url')
                                        ->maxLength(255),
                                ]),
                        ]),
                    Forms\Components\Tabs\Tab::make('Publish')
                        ->schema([
                            Forms\Components\Select::make('status')
                                ->required()
                                ->options([
                                    'draft' => 'draft',
                                    'published' => 'published',
                                ])
                                ->default('draft'),
                            Forms\Components\Toggle::make('is_public')
                                ->default(false),
                            Forms\Components\Toggle::make('is_indexable')
                                ->default(true),
                            Forms\Components\DateTimePicker::make('published_at'),
                            Forms\Components\DateTimePicker::make('scheduled_at'),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => $state === 'published' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('locale')
                    ->sortable(),
                Tables\Columns\TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'draft' => 'draft',
                        'published' => 'published',
                    ]),
                Tables\Filters\SelectFilter::make('locale')
                    ->options(fn (): array => static::getEloquentQuery()
                        ->select('locale')
                        ->whereNotNull('locale')
                        ->distinct()
                        ->orderBy('locale')
                        ->pluck('locale', 'locale')
                        ->toArray()),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListArticles::route('/'),
            'create' => Pages\CreateArticle::route('/create'),
            'edit' => Pages\EditArticle::route('/{record}/edit'),
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
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_CONTENT_READ)
                || $user->hasPermission(PermissionNames::ADMIN_OWNER)
            );
    }

    private static function canPublish(): bool
    {
        $guard = (string) config('admin.guard', 'admin');
        $user = auth($guard)->user();

        return is_object($user)
            && method_exists($user, 'hasPermission')
            && (
                $user->hasPermission(PermissionNames::ADMIN_CONTENT_PUBLISH)
                || $user->hasPermission(PermissionNames::ADMIN_OWNER)
            );
    }
}
