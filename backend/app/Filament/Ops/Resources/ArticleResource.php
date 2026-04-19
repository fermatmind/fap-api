<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\ArticleResource\Pages;
use App\Filament\Ops\Resources\ArticleResource\Support\ArticleWorkspace;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\Article;
use App\Support\OrgContext;
use Filament\Forms;
use Filament\Forms\Components\BelongsToManyMultiSelect;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
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
        return __('ops.group.editorial');
    }

    public static function getNavigationLabel(): string
    {
        return 'Articles';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('org_id')
                ->default(fn (): int => max(0, (int) app(OrgContext::class)->orgId())),
            Forms\Components\Grid::make([
                'default' => 1,
                'xl' => 12,
            ])
                ->extraAttributes(['class' => 'ops-article-workspace-layout'])
                ->schema([
                    Forms\Components\Group::make([
                        Forms\Components\Section::make('Basic')
                            ->description('Write the article headline, URL slug, and short summary before moving into the body.')
                            ->extraAttributes(['class' => 'ops-article-workspace-section ops-article-workspace-section--main'])
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull()
                                    ->helperText('Primary headline used throughout the editorial workspace and public article page.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-article-workspace-field ops-article-workspace-field--title'])
                                    ->extraInputAttributes(['class' => 'ops-article-workspace-input ops-article-workspace-input--title']),
                                Forms\Components\TextInput::make('slug')
                                    ->required()
                                    ->maxLength(127)
                                    ->helperText('Used in the public article URL. Keep it short, stable, and human-readable.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-article-workspace-field']),
                                Forms\Components\Textarea::make('excerpt')
                                    ->rows(4)
                                    ->columnSpanFull()
                                    ->helperText('Short summary for list previews, share cards, and search snippets.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-article-workspace-field ops-article-workspace-field--summary']),
                            ])
                            ->columns(2),
                        Forms\Components\Section::make('Content')
                            ->description('Draft the canonical article body and any lead media reference used by the frontend.')
                            ->extraAttributes(['class' => 'ops-article-workspace-section ops-article-workspace-section--main'])
                            ->schema([
                                Forms\Components\MarkdownEditor::make('content_md')
                                    ->required()
                                    ->columnSpanFull()
                                    ->helperText('This markdown body is the source content for article rendering and editorial review.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-article-workspace-field ops-article-workspace-field--editor']),
                                Forms\Components\TextInput::make('author_name')
                                    ->maxLength(128)
                                    ->helperText('Public byline shown on the article surface when present.'),
                                Forms\Components\TextInput::make('reviewer_name')
                                    ->maxLength(128)
                                    ->helperText('Optional public reviewer attribution.'),
                                Forms\Components\TextInput::make('reading_minutes')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(1440)
                                    ->helperText('Optional editorial read-time override in minutes.'),
                                Forms\Components\TextInput::make('cover_image_url')
                                    ->maxLength(255)
                                    ->helperText('Optional lead image URL used for cards, previews, and Open Graph fallback.'),
                                Forms\Components\TextInput::make('cover_image_alt')
                                    ->maxLength(255)
                                    ->helperText('Accessible description for the lead image.'),
                                Forms\Components\TextInput::make('cover_image_width')
                                    ->numeric()
                                    ->minValue(1)
                                    ->helperText('Intrinsic lead image width in pixels.'),
                                Forms\Components\TextInput::make('cover_image_height')
                                    ->numeric()
                                    ->minValue(1)
                                    ->helperText('Intrinsic lead image height in pixels.'),
                                Forms\Components\KeyValue::make('cover_image_variants')
                                    ->columnSpanFull()
                                    ->helperText('Optional responsive image variant map. Use keys such as hero, card, thumbnail, square, og, and preload.'),
                            ])
                            ->columns(2),
                    ])
                        ->columnSpan([
                            'xl' => 8,
                        ])
                        ->extraAttributes(['class' => 'ops-article-workspace-main-column']),
                    Forms\Components\Group::make([
                        Forms\Components\Section::make('Publish')
                            ->description('Control editorial state, public visibility, and release timing from one side rail.')
                            ->extraAttributes(['class' => 'ops-article-workspace-section ops-article-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('workspace_state')
                                    ->label('Editorial cues')
                                    ->content(fn (Forms\Get $get, ?Article $record) => ArticleWorkspace::renderEditorialCues($get, $record))
                                    ->columnSpanFull(),
                                Forms\Components\Select::make('status')
                                    ->required()
                                    ->options(self::statusOptions())
                                    ->default('draft')
                                    ->helperText('Published records are ready for release once visibility and scheduling are aligned.'),
                                Forms\Components\Toggle::make('is_public')
                                    ->label('Public visibility')
                                    ->default(false)
                                    ->helperText('Controls whether the article can be served on the public experience.'),
                                Forms\Components\Toggle::make('is_indexable')
                                    ->label('Search indexable')
                                    ->default(true)
                                    ->helperText('Signals whether search engines should index this article.'),
                                Forms\Components\DateTimePicker::make('published_at')
                                    ->helperText('Set the publish timestamp used for editorial reporting and release cues.'),
                                Forms\Components\DateTimePicker::make('scheduled_at')
                                    ->helperText('Optional future release time for editorial scheduling.'),
                            ]),
                        Forms\Components\Section::make('Locale, category, and tags')
                            ->description('Organize the article for the right locale, taxonomy, and downstream filtering.')
                            ->extraAttributes(['class' => 'ops-article-workspace-section ops-article-workspace-section--rail'])
                            ->schema([
                                Forms\Components\TextInput::make('locale')
                                    ->required()
                                    ->maxLength(16)
                                    ->default('en')
                                    ->helperText('Locale code used for editorial segmentation and frontend routing.'),
                                Forms\Components\Select::make('category_id')
                                    ->label('Category')
                                    ->relationship(
                                        'category',
                                        'name',
                                        fn (Builder $query): Builder => $query->where('article_categories.org_id', self::currentOrgId())
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->helperText('Primary editorial bucket for this article.'),
                                BelongsToManyMultiSelect::make('tags')
                                    ->relationship(
                                        'tags',
                                        'name',
                                        fn (Builder $query): Builder => $query->where('article_tags.org_id', self::currentOrgId())
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->helperText('Tags help content operators slice the workspace and power discovery.'),
                                Forms\Components\TextInput::make('related_test_slug')
                                    ->maxLength(127)
                                    ->helperText('Optional assessment slug used to place this article on a test detail page.'),
                                Forms\Components\TextInput::make('voice')
                                    ->maxLength(32)
                                    ->helperText('Optional editorial voice slot, such as tool, growth, narrative, or editorial.'),
                                Forms\Components\TextInput::make('voice_order')
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(65535)
                                    ->helperText('Ordering within a related content placement.'),
                            ]),
                        Forms\Components\Section::make('SEO')
                            ->relationship('seoMeta')
                            ->description('Keep search and social metadata grouped so the article stays easy to review and maintain.')
                            ->extraAttributes(['class' => 'ops-article-workspace-section ops-article-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('seo_snapshot')
                                    ->label('SEO snapshot')
                                    ->content(fn (Forms\Get $get) => ArticleWorkspace::renderSeoSnapshot($get))
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('seo_title')
                                    ->maxLength(60)
                                    ->helperText('Recommended search result headline. Keep it focused and concise.'),
                                Forms\Components\Textarea::make('seo_description')
                                    ->rows(3)
                                    ->maxLength(160)
                                    ->helperText('Search snippet summary shown in results and link previews.'),
                                Forms\Components\TextInput::make('canonical_url')
                                    ->maxLength(255)
                                    ->helperText('Use when the public URL must explicitly point search engines to the canonical article URL.'),
                                Forms\Components\TextInput::make('og_title')
                                    ->maxLength(90)
                                    ->helperText('Optional social headline override for richer sharing cards.'),
                                Forms\Components\Textarea::make('og_description')
                                    ->rows(3)
                                    ->maxLength(200)
                                    ->helperText('Optional social description override for editorial campaigns.'),
                                Forms\Components\TextInput::make('og_image_url')
                                    ->maxLength(255)
                                    ->helperText('Optional image URL for Open Graph and social previews.'),
                            ]),
                        Forms\Components\Section::make('Record cues')
                            ->description('Read-only context to help editors understand what is already live and when it last changed.')
                            ->extraAttributes(['class' => 'ops-article-workspace-section ops-article-workspace-section--rail'])
                            ->visible(fn (?Article $record): bool => filled($record))
                            ->schema([
                                Forms\Components\Placeholder::make('public_url_preview')
                                    ->label('Public URL')
                                    ->content(fn (Forms\Get $get, ?Article $record): string => ArticleWorkspace::publicUrl((string) ($get('slug') ?? $record?->slug ?? '')) ?? 'Public URL appears after a slug is set.'),
                                Forms\Components\Placeholder::make('created_at_summary')
                                    ->label('Created')
                                    ->content(fn (?Article $record): string => ArticleWorkspace::formatTimestamp($record?->created_at, 'Draft not saved yet')),
                                Forms\Components\Placeholder::make('updated_at_summary')
                                    ->label('Last updated')
                                    ->content(fn (?Article $record): string => ArticleWorkspace::formatTimestamp($record?->updated_at, 'Draft not saved yet')),
                                Forms\Components\Placeholder::make('published_at_summary')
                                    ->label('Last published')
                                    ->content(fn (?Article $record): string => ArticleWorkspace::formatTimestamp($record?->published_at, 'Not published yet')),
                            ]),
                    ])
                        ->columnSpan([
                            'xl' => 4,
                        ])
                        ->extraAttributes(['class' => 'ops-article-workspace-rail-column']),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Article')
                    ->html()
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (Article $record): string => (string) view('filament.ops.articles.partials.table-title', [
                        'meta' => ArticleWorkspace::titleMeta($record),
                        'title' => $record->title,
                    ])->render()),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->copyable()
                    ->formatStateUsing(fn (string $state): string => '/'.trim($state, '/'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->description(fn (Article $record): string => ArticleWorkspace::visibilityMeta($record))
                    ->color(fn (string $state): string => StatusBadge::color($state)),
                Tables\Columns\TextColumn::make('locale')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('category.name')
                    ->label('Category')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('published_at')
                    ->label('Published')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Not published'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(self::statusOptions()),
                Tables\Filters\SelectFilter::make('locale')
                    ->options(fn (): array => static::getEloquentQuery()
                        ->select('locale')
                        ->whereNotNull('locale')
                        ->distinct()
                        ->orderBy('locale')
                        ->pluck('locale', 'locale')
                        ->toArray()),
            ])
            ->searchPlaceholder('Search title or slug')
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (Article $record): string => static::getUrl('edit', ['record' => $record]))
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray'),
                Tables\Actions\Action::make('release')
                    ->label('Release')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('primary')
                    ->visible(fn (Article $record): bool => ContentAccess::canRelease()
                        && $record->status !== 'published'
                        && (EditorialReviewAudit::latestState('article', $record)['state'] ?? null) === EditorialReviewAudit::STATE_APPROVED)
                    ->action(fn (Article $record) => self::releaseRecord($record, 'resource_table')),
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
            ->where('org_id', self::currentOrgId());
    }

    private static function currentOrgId(): int
    {
        return max(0, (int) app(OrgContext::class)->orgId());
    }

    /**
     * @return array<string,string>
     */
    private static function statusOptions(): array
    {
        return [
            'draft' => 'draft',
            'published' => 'published',
        ];
    }

    private static function canRead(): bool
    {
        return ContentAccess::canRead();
    }

    private static function canWrite(): bool
    {
        return ContentAccess::canWrite();
    }

    public static function releaseRecord(Article $record, string $source = 'resource_table'): void
    {
        if (! ContentAccess::canRelease()) {
            throw new AuthorizationException('You do not have permission to release articles.');
        }

        if ($record->status === 'published') {
            return;
        }

        if ((EditorialReviewAudit::latestState('article', $record)['state'] ?? null) !== EditorialReviewAudit::STATE_APPROVED) {
            throw new AuthorizationException('This article must be approved in editorial review before it can be published.');
        }

        $record->forceFill([
            'status' => 'published',
            'is_public' => true,
            'published_at' => $record->published_at ?? now(),
        ])->save();

        ContentReleaseAudit::log('article', $record->fresh(), $source);

        Notification::make()
            ->title('Article released')
            ->body('The article is now marked as published.')
            ->success()
            ->send();
    }
}
