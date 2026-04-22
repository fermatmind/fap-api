<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\ArticleResource\Pages;
use App\Filament\Ops\Resources\ArticleResource\Support\ArticleWorkspace;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Filament\Ops\Support\OpsContentLocaleScope;
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
        return __('ops.nav.articles');
    }

    public static function getModelLabel(): string
    {
        return __('ops.resources.articles.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ops.resources.articles.plural');
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
                        Forms\Components\Section::make(__('ops.resources.articles.sections.basic'))
                            ->description(__('ops.resources.articles.section_descriptions.basic'))
                            ->extraAttributes(['class' => 'ops-article-workspace-section ops-article-workspace-section--main'])
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->label(__('ops.resources.articles.fields.title'))
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull()
                                    ->helperText(__('ops.resources.articles.helpers.title'))
                                    ->extraFieldWrapperAttributes(['class' => 'ops-article-workspace-field ops-article-workspace-field--title'])
                                    ->extraInputAttributes(['class' => 'ops-article-workspace-input ops-article-workspace-input--title']),
                                Forms\Components\TextInput::make('slug')
                                    ->label(__('ops.resources.articles.fields.slug'))
                                    ->required()
                                    ->maxLength(127)
                                    ->helperText(__('ops.resources.articles.helpers.slug'))
                                    ->extraFieldWrapperAttributes(['class' => 'ops-article-workspace-field']),
                                Forms\Components\Textarea::make('excerpt')
                                    ->label(__('ops.resources.articles.fields.excerpt'))
                                    ->rows(4)
                                    ->columnSpanFull()
                                    ->helperText(__('ops.resources.articles.helpers.excerpt'))
                                    ->extraFieldWrapperAttributes(['class' => 'ops-article-workspace-field ops-article-workspace-field--summary']),
                            ])
                            ->columns(2),
                        Forms\Components\Section::make(__('ops.resources.articles.sections.content'))
                            ->description(__('ops.resources.articles.section_descriptions.content'))
                            ->extraAttributes(['class' => 'ops-article-workspace-section ops-article-workspace-section--main'])
                            ->schema([
                                Forms\Components\MarkdownEditor::make('content_md')
                                    ->label(__('ops.resources.articles.fields.content_md'))
                                    ->required()
                                    ->columnSpanFull()
                                    ->helperText(__('ops.resources.articles.helpers.content_md'))
                                    ->extraFieldWrapperAttributes(['class' => 'ops-article-workspace-field ops-article-workspace-field--editor']),
                                Forms\Components\TextInput::make('author_name')
                                    ->label(__('ops.resources.articles.fields.author_name'))
                                    ->maxLength(128)
                                    ->helperText(__('ops.resources.articles.helpers.author_name')),
                                Forms\Components\TextInput::make('reviewer_name')
                                    ->label(__('ops.resources.articles.fields.reviewer_name'))
                                    ->maxLength(128)
                                    ->helperText(__('ops.resources.articles.helpers.reviewer_name')),
                                Forms\Components\TextInput::make('reading_minutes')
                                    ->label(__('ops.resources.articles.fields.reading_minutes'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(1440)
                                    ->helperText(__('ops.resources.articles.helpers.reading_minutes')),
                                Forms\Components\TextInput::make('cover_image_url')
                                    ->label(__('ops.resources.articles.fields.cover_image_url'))
                                    ->maxLength(255)
                                    ->helperText(__('ops.resources.articles.helpers.cover_image_url')),
                                Forms\Components\TextInput::make('cover_image_alt')
                                    ->label(__('ops.resources.articles.fields.cover_image_alt'))
                                    ->maxLength(255)
                                    ->helperText(__('ops.resources.articles.helpers.cover_image_alt')),
                                Forms\Components\TextInput::make('cover_image_width')
                                    ->label(__('ops.resources.articles.fields.cover_image_width'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->helperText(__('ops.resources.articles.helpers.cover_image_width')),
                                Forms\Components\TextInput::make('cover_image_height')
                                    ->label(__('ops.resources.articles.fields.cover_image_height'))
                                    ->numeric()
                                    ->minValue(1)
                                    ->helperText(__('ops.resources.articles.helpers.cover_image_height')),
                                Forms\Components\KeyValue::make('cover_image_variants')
                                    ->label(__('ops.resources.articles.fields.cover_image_variants'))
                                    ->columnSpanFull()
                                    ->helperText(__('ops.resources.articles.helpers.cover_image_variants')),
                            ])
                            ->columns(2),
                    ])
                        ->columnSpan([
                            'xl' => 8,
                        ])
                        ->extraAttributes(['class' => 'ops-article-workspace-main-column']),
                    Forms\Components\Group::make([
                        Forms\Components\Section::make(__('ops.resources.articles.sections.publish'))
                            ->description(__('ops.resources.articles.section_descriptions.publish'))
                            ->extraAttributes(['class' => 'ops-article-workspace-section ops-article-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('workspace_state')
                                    ->label(__('ops.resources.articles.fields.editorial_cues'))
                                    ->content(fn (Forms\Get $get, ?Article $record) => ArticleWorkspace::renderEditorialCues($get, $record))
                                    ->columnSpanFull(),
                                Forms\Components\Select::make('status')
                                    ->label(__('ops.resources.articles.fields.status'))
                                    ->required()
                                    ->options(self::statusOptions())
                                    ->default('draft')
                                    ->helperText(__('ops.resources.articles.helpers.status')),
                                Forms\Components\Toggle::make('is_public')
                                    ->label(__('ops.resources.articles.fields.public_visibility'))
                                    ->default(false)
                                    ->helperText(__('ops.resources.articles.helpers.is_public')),
                                Forms\Components\Toggle::make('is_indexable')
                                    ->label(__('ops.resources.articles.fields.search_indexable'))
                                    ->default(true)
                                    ->helperText(__('ops.resources.articles.helpers.is_indexable')),
                                Forms\Components\DateTimePicker::make('published_at')
                                    ->label(__('ops.resources.articles.fields.published'))
                                    ->helperText(__('ops.resources.articles.helpers.published_at')),
                                Forms\Components\DateTimePicker::make('scheduled_at')
                                    ->label(__('ops.resources.articles.fields.scheduled_at'))
                                    ->helperText(__('ops.resources.articles.helpers.scheduled_at')),
                            ]),
                        Forms\Components\Section::make(__('ops.resources.articles.sections.locale_taxonomy'))
                            ->description(__('ops.resources.articles.section_descriptions.locale_taxonomy'))
                            ->extraAttributes(['class' => 'ops-article-workspace-section ops-article-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('locale_scope_marker')
                                    ->label(__('ops.locale_scope.editor_marker_label'))
                                    ->content(fn (Forms\Get $get, ?Article $record): string => OpsContentLocaleScope::editorMarker((string) ($get('locale') ?? $record?->locale ?? OpsContentLocaleScope::currentContentLocale())))
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('locale')
                                    ->label(__('ops.resources.articles.fields.locale'))
                                    ->required()
                                    ->maxLength(16)
                                    ->default('en')
                                    ->helperText(__('ops.resources.articles.helpers.locale')),
                                Forms\Components\Select::make('category_id')
                                    ->label(__('ops.resources.articles.fields.category'))
                                    ->relationship(
                                        'category',
                                        'name',
                                        fn (Builder $query): Builder => $query->where('article_categories.org_id', self::currentOrgId())
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->helperText(__('ops.resources.articles.helpers.category_id')),
                                BelongsToManyMultiSelect::make('tags')
                                    ->label(__('ops.resources.articles.fields.tags'))
                                    ->relationship(
                                        'tags',
                                        'name',
                                        fn (Builder $query): Builder => $query->where('article_tags.org_id', self::currentOrgId())
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->helperText(__('ops.resources.articles.helpers.tags')),
                                Forms\Components\TextInput::make('related_test_slug')
                                    ->label(__('ops.resources.articles.fields.related_test_slug'))
                                    ->maxLength(127)
                                    ->helperText(__('ops.resources.articles.helpers.related_test_slug')),
                                Forms\Components\TextInput::make('voice')
                                    ->label(__('ops.resources.articles.fields.voice'))
                                    ->maxLength(32)
                                    ->helperText(__('ops.resources.articles.helpers.voice')),
                                Forms\Components\TextInput::make('voice_order')
                                    ->label(__('ops.resources.articles.fields.voice_order'))
                                    ->numeric()
                                    ->minValue(0)
                                    ->maxValue(65535)
                                    ->helperText(__('ops.resources.articles.helpers.voice_order')),
                            ]),
                        Forms\Components\Section::make(__('ops.resources.articles.sections.seo'))
                            ->relationship('seoMeta')
                            ->description(__('ops.resources.articles.section_descriptions.seo'))
                            ->extraAttributes(['class' => 'ops-article-workspace-section ops-article-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('seo_snapshot')
                                    ->label(__('ops.resources.articles.fields.seo_snapshot'))
                                    ->content(fn (Forms\Get $get) => ArticleWorkspace::renderSeoSnapshot($get))
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('seo_title')
                                    ->label(__('ops.resources.articles.fields.seo_title'))
                                    ->maxLength(60)
                                    ->helperText(__('ops.resources.articles.helpers.seo_title')),
                                Forms\Components\Textarea::make('seo_description')
                                    ->label(__('ops.resources.articles.fields.seo_description'))
                                    ->rows(3)
                                    ->maxLength(160)
                                    ->helperText(__('ops.resources.articles.helpers.seo_description')),
                                Forms\Components\TextInput::make('canonical_url')
                                    ->label(__('ops.resources.articles.fields.canonical_url'))
                                    ->maxLength(255)
                                    ->helperText(__('ops.resources.articles.helpers.canonical_url')),
                                Forms\Components\TextInput::make('og_title')
                                    ->label(__('ops.resources.articles.fields.og_title'))
                                    ->maxLength(90)
                                    ->helperText(__('ops.resources.articles.helpers.og_title')),
                                Forms\Components\Textarea::make('og_description')
                                    ->label(__('ops.resources.articles.fields.og_description'))
                                    ->rows(3)
                                    ->maxLength(200)
                                    ->helperText(__('ops.resources.articles.helpers.og_description')),
                                Forms\Components\TextInput::make('og_image_url')
                                    ->label(__('ops.resources.articles.fields.og_image_url'))
                                    ->maxLength(255)
                                    ->helperText(__('ops.resources.articles.helpers.og_image_url')),
                            ]),
                        Forms\Components\Section::make(__('ops.resources.articles.sections.record_cues'))
                            ->description(__('ops.resources.articles.section_descriptions.record_cues'))
                            ->extraAttributes(['class' => 'ops-article-workspace-section ops-article-workspace-section--rail'])
                            ->visible(fn (?Article $record): bool => filled($record))
                            ->schema([
                                Forms\Components\Placeholder::make('public_url_preview')
                                    ->label(__('ops.resources.articles.fields.public_url'))
                                    ->content(fn (Forms\Get $get, ?Article $record): string => ArticleWorkspace::publicUrl((string) ($get('slug') ?? $record?->slug ?? '')) ?? __('ops.resources.articles.placeholders.public_url_after_slug')),
                                Forms\Components\Placeholder::make('created_at_summary')
                                    ->label(__('ops.resources.articles.fields.created'))
                                    ->content(fn (?Article $record): string => ArticleWorkspace::formatTimestamp($record?->created_at, __('ops.resources.articles.placeholders.draft_not_saved'))),
                                Forms\Components\Placeholder::make('updated_at_summary')
                                    ->label(__('ops.resources.articles.fields.last_updated'))
                                    ->content(fn (?Article $record): string => ArticleWorkspace::formatTimestamp($record?->updated_at, __('ops.resources.articles.placeholders.draft_not_saved'))),
                                Forms\Components\Placeholder::make('published_at_summary')
                                    ->label(__('ops.resources.articles.fields.last_published'))
                                    ->content(fn (?Article $record): string => ArticleWorkspace::formatTimestamp($record?->published_at, __('ops.resources.articles.placeholders.not_published_yet'))),
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
                    ->label(__('ops.resources.articles.fields.article'))
                    ->html()
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (Article $record): string => (string) view('filament.ops.articles.partials.table-title', [
                        'meta' => ArticleWorkspace::titleMeta($record),
                        'title' => $record->title,
                    ])->render()),
                Tables\Columns\TextColumn::make('slug')
                    ->label(__('ops.resources.articles.fields.slug'))
                    ->searchable()
                    ->copyable()
                    ->formatStateUsing(fn (string $state): string => '/'.trim($state, '/'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('ops.resources.articles.fields.status'))
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => StatusBadge::label($state))
                    ->description(fn (Article $record): string => ArticleWorkspace::visibilityMeta($record))
                    ->color(fn (string $state): string => StatusBadge::color($state)),
                Tables\Columns\TextColumn::make('locale')
                    ->label(__('ops.locale_scope.content_locale'))
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('source_locale')
                    ->label(__('ops.locale_scope.source_locale'))
                    ->state(fn (Article $record): string => OpsContentLocaleScope::sourceLocale($record->locale))
                    ->badge(),
                Tables\Columns\TextColumn::make('category.name')
                    ->label(__('ops.resources.articles.fields.category'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('published_at')
                    ->label(__('ops.resources.articles.fields.published'))
                    ->dateTime()
                    ->sortable()
                    ->placeholder(__('ops.resources.articles.placeholders.not_published')),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('ops.resources.articles.fields.updated'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('ops.resources.articles.fields.status'))
                    ->options(self::statusOptions()),
                Tables\Filters\SelectFilter::make('locale_scope')
                    ->label(__('ops.locale_scope.filter_label'))
                    ->options(fn (): array => OpsContentLocaleScope::filterOptions())
                    ->default(fn (): string => OpsContentLocaleScope::currentContentLocale())
                    ->query(fn (Builder $query, array $data): Builder => OpsContentLocaleScope::applyToQuery($query, $data)),
            ])
            ->emptyStateHeading(fn (object $livewire): string => OpsContentLocaleScope::emptyStateHeading($livewire, (string) static::getPluralModelLabel()))
            ->emptyStateDescription(fn (object $livewire): ?string => OpsContentLocaleScope::emptyStateDescription(
                $livewire,
                (string) static::getModelLabel(),
                static::canCreate() && static::hasPage('create')
            ))
            ->searchPlaceholder(__('ops.resources.articles.placeholders.search'))
            ->actionsColumnLabel(__('ops.resources.articles.fields.actions'))
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (Article $record): string => static::getUrl('edit', ['record' => $record]))
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label(__('ops.resources.articles.actions.edit'))
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray'),
                Tables\Actions\Action::make('release')
                    ->label(__('ops.resources.articles.actions.release'))
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
            'draft' => __('ops.status.draft'),
            'published' => __('ops.status.published'),
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
