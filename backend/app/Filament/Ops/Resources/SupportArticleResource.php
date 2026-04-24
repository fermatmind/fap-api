<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\SupportArticleResource\Pages;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\OpsContentLocaleScope;
use App\Filament\Ops\Support\OpsTable;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\SupportArticle;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SupportArticleResource extends Resource
{
    protected static ?string $model = SupportArticle::class;

    protected static ?string $slug = 'support-articles';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Support Articles';

    public static function canViewAny(): bool
    {
        return ContentAccess::canRead();
    }

    public static function canCreate(): bool
    {
        return ContentAccess::canWrite();
    }

    public static function canEdit($record): bool
    {
        return ContentAccess::canWrite() || ContentAccess::canReview();
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
        return __('ops.nav.support_articles');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('org_id')->default(0),
            Forms\Components\Section::make('Support operation')
                ->schema([
                    Forms\Components\TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                    Forms\Components\TextInput::make('slug')->required()->maxLength(128),
                    Forms\Components\Select::make('locale')
                        ->label(__('ops.locale_scope.content_locale'))
                        ->required()
                        ->native(false)
                        ->options(['en' => 'en', 'zh-CN' => 'zh-CN'])
                        ->default('en'),
                    Forms\Components\Placeholder::make('locale_scope_marker')
                        ->label(__('ops.locale_scope.editor_marker_label'))
                        ->content(fn (Forms\Get $get, ?SupportArticle $record): string => OpsContentLocaleScope::editorMarker((string) ($get('locale') ?? $record?->locale ?? OpsContentLocaleScope::currentContentLocale())))
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('summary')->rows(3)->maxLength(2000)->columnSpanFull(),
                    Forms\Components\Select::make('support_category')
                        ->required()
                        ->native(false)
                        ->options(array_combine(SupportArticle::CATEGORIES, SupportArticle::CATEGORIES)),
                    Forms\Components\Select::make('support_intent')
                        ->required()
                        ->native(false)
                        ->options(array_combine(SupportArticle::INTENTS, SupportArticle::INTENTS)),
                    Forms\Components\TextInput::make('primary_cta_label')->maxLength(128),
                    Forms\Components\TextInput::make('primary_cta_url')->maxLength(255),
                ])
                ->columns(2),
            Forms\Components\Section::make('Publishing and review')
                ->schema([
                    Forms\Components\Select::make('status')
                        ->required()
                        ->native(false)
                        ->options([
                            SupportArticle::STATUS_DRAFT => 'Draft',
                            SupportArticle::STATUS_SCHEDULED => 'Scheduled',
                            SupportArticle::STATUS_PUBLISHED => 'Published',
                            SupportArticle::STATUS_ARCHIVED => 'Archived',
                        ])
                        ->default(SupportArticle::STATUS_DRAFT),
                    Forms\Components\Select::make('review_state')
                        ->required()
                        ->native(false)
                        ->options([
                            SupportArticle::REVIEW_DRAFT => 'Draft',
                            SupportArticle::REVIEW_SUPPORT => 'Support review',
                            SupportArticle::REVIEW_PRODUCT_OR_POLICY => 'Product or policy review',
                            SupportArticle::REVIEW_APPROVED => 'Approved',
                            SupportArticle::REVIEW_CHANGES_REQUESTED => 'Changes requested',
                        ])
                        ->default(SupportArticle::REVIEW_DRAFT),
                    Forms\Components\DateTimePicker::make('last_reviewed_at'),
                    Forms\Components\DateTimePicker::make('published_at'),
                ])
                ->columns(2),
            Forms\Components\Section::make('Body')
                ->schema([
                    Forms\Components\MarkdownEditor::make('body_md')->columnSpanFull(),
                    Forms\Components\Textarea::make('body_html')->rows(8)->columnSpanFull(),
                ]),
            Forms\Components\Section::make('Relations and SEO')
                ->schema([
                    Forms\Components\TagsInput::make('related_support_article_ids')
                        ->helperText('Related support article ids.')
                        ->dehydrateStateUsing(fn (mixed $state): array => self::normalizeIdList($state)),
                    Forms\Components\TagsInput::make('related_content_page_ids')
                        ->helperText('Related content_page ids.')
                        ->dehydrateStateUsing(fn (mixed $state): array => self::normalizeIdList($state)),
                    Forms\Components\TextInput::make('seo_title')->maxLength(255),
                    Forms\Components\Textarea::make('seo_description')->rows(3)->maxLength(2000),
                    Forms\Components\TextInput::make('canonical_path')->maxLength(255),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                OpsTable::titleWithSlug('title', 'slug', __('ops.nav.support_articles')),
                Tables\Columns\TextColumn::make('slug')
                    ->label(__('ops.resources.articles.fields.slug'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                OpsTable::locale(label: __('ops.locale_scope.content_locale')),
                Tables\Columns\TextColumn::make('source_locale')
                    ->label(__('ops.table.source_locale'))
                    ->state(fn (SupportArticle $record): string => (string) ($record->source_locale ?: OpsContentLocaleScope::sourceLocale($record->locale)))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                OpsTable::translationStatus(),
                Tables\Columns\TextColumn::make('support_category')
                    ->label(__('ops.table.category_type'))
                    ->badge()
                    ->sortable()
                    ->description(fn (SupportArticle $record): ?string => $record->support_intent),
                OpsTable::status(),
                Tables\Columns\TextColumn::make('review_state')
                    ->label(__('ops.resources.articles.fields.working_revision_status'))
                    ->badge()
                    ->color(fn (?string $state): string => StatusBadge::color($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                OpsTable::updatedAt(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('ops.table.status'))
                    ->options([
                        'draft' => __('ops.status.draft'),
                        'scheduled' => __('ops.status.scheduled'),
                        'published' => __('ops.status.published'),
                        'archived' => __('ops.status.archived'),
                    ]),
                Tables\Filters\SelectFilter::make('translation_status')
                    ->label(__('ops.table.translation_status'))
                    ->options([
                        'source' => __('ops.status.source'),
                        'draft' => __('ops.status.draft'),
                        'machine_draft' => __('ops.status.machine_draft'),
                        'human_review' => __('ops.status.human_review'),
                        'approved' => __('ops.status.approved'),
                        'published' => __('ops.status.published'),
                        'stale' => __('ops.status.stale'),
                        'archived' => __('ops.status.archived'),
                    ]),
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
            ->defaultSort('updated_at', 'desc')
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportArticles::route('/'),
            'create' => Pages\CreateSupportArticle::route('/create'),
            'edit' => Pages\EditSupportArticle::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->where('org_id', 0);
    }

    /**
     * @return list<int>
     */
    private static function normalizeIdList(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        $ids = array_filter(array_map(
            static fn (mixed $value): int => (int) $value,
            $state
        ), static fn (int $value): bool => $value > 0);

        return array_values(array_unique($ids));
    }
}
