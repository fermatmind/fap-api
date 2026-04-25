<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\InterpretationGuideResource\Pages;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\OpsContentLocaleScope;
use App\Filament\Ops\Support\OpsEdit;
use App\Filament\Ops\Support\OpsTable;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\InterpretationGuide;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InterpretationGuideResource extends Resource
{
    protected static ?string $model = InterpretationGuide::class;

    protected static ?string $slug = 'interpretation-guides';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Interpretation Guides';

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
        return __('ops.nav.interpretation_guides');
    }

    public static function getModelLabel(): string
    {
        return __('ops.nav.interpretation_guides');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ops.nav.interpretation_guides');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('org_id')->default(0),
            Forms\Components\Grid::make(['default' => 1, 'xl' => 12])
                ->extraAttributes(['class' => 'ops-edit-workspace-layout'])
                ->schema([
                    Forms\Components\Group::make([
                        Forms\Components\Tabs::make(__('ops.edit.sections.content'))
                            ->contained(false)
                            ->extraAttributes(['class' => 'ops-edit-workspace-tabs'])
                            ->tabs([
                                Forms\Components\Tabs\Tab::make(__('ops.edit.tabs.content'))
                                    ->schema([
                                        Forms\Components\Section::make(__('ops.edit.sections.content'))
                                            ->schema([
                                                Forms\Components\TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                                                Forms\Components\TextInput::make('slug')->required()->maxLength(128),
                                                Forms\Components\Select::make('locale')
                                                    ->label(__('ops.locale_scope.content_locale'))
                                                    ->required()
                                                    ->native(false)
                                                    ->options(OpsEdit::localeOptions())
                                                    ->default('en'),
                                                Forms\Components\Placeholder::make('locale_scope_marker')
                                                    ->label(__('ops.locale_scope.editor_marker_label'))
                                                    ->content(fn (Forms\Get $get, ?InterpretationGuide $record): string => OpsContentLocaleScope::editorMarker((string) ($get('locale') ?? $record?->locale ?? OpsContentLocaleScope::currentContentLocale())))
                                                    ->columnSpanFull(),
                                                Forms\Components\Textarea::make('summary')->rows(3)->maxLength(2000)->columnSpanFull(),
                                                Forms\Components\MarkdownEditor::make('body_md')->columnSpanFull()->extraFieldWrapperAttributes(['class' => 'ops-edit-workspace-field--editor']),
                                                Forms\Components\Textarea::make('body_html')->rows(8)->columnSpanFull(),
                                            ])
                                            ->columns(2),
                                    ]),
                                Forms\Components\Tabs\Tab::make(__('ops.edit.tabs.seo'))
                                    ->schema([
                                        Forms\Components\Section::make(__('ops.edit.sections.seo_fields'))
                                            ->schema([
                                                Forms\Components\TextInput::make('seo_title')->maxLength(255),
                                                Forms\Components\Textarea::make('seo_description')->rows(3)->maxLength(2000),
                                                Forms\Components\TextInput::make('canonical_path')->maxLength(255),
                                            ])
                                            ->columns(2),
                                    ]),
                                Forms\Components\Tabs\Tab::make(__('ops.edit.tabs.translation'))
                                    ->schema([
                                        Forms\Components\Section::make(__('ops.edit.sections.operations'))
                                            ->schema([
                                                Forms\Components\Select::make('test_family')
                                                    ->required()
                                                    ->native(false)
                                                    ->options(array_combine(InterpretationGuide::TEST_FAMILIES, InterpretationGuide::TEST_FAMILIES))
                                                    ->default('general'),
                                                Forms\Components\Select::make('result_context')
                                                    ->required()
                                                    ->native(false)
                                                    ->options(array_combine(InterpretationGuide::RESULT_CONTEXTS, InterpretationGuide::RESULT_CONTEXTS)),
                                                Forms\Components\TextInput::make('audience')->maxLength(96)->default('general'),
                                            ])
                                            ->columns(2),
                                    ]),
                                Forms\Components\Tabs\Tab::make(__('ops.edit.tabs.revision'))
                                    ->schema([
                                        Forms\Components\Section::make(__('ops.edit.sections.relation_fields'))
                                            ->schema([
                                                Forms\Components\TagsInput::make('related_guide_ids')
                                                    ->dehydrateStateUsing(fn (mixed $state): array => self::normalizeIdList($state)),
                                                Forms\Components\TagsInput::make('related_methodology_page_ids')
                                                    ->dehydrateStateUsing(fn (mixed $state): array => self::normalizeIdList($state)),
                                            ])
                                            ->columns(2),
                                    ]),
                            ]),
                    ])->columnSpan(['xl' => 8])->extraAttributes(['class' => 'ops-edit-workspace-main-column']),
                    Forms\Components\Group::make([
                        Forms\Components\Section::make(__('ops.edit.sections.status_visibility'))
                            ->description(__('ops.edit.descriptions.status_visibility'))
                            ->extraAttributes(['class' => 'ops-edit-workspace-section ops-edit-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('ops_status_visibility')->label(__('ops.edit.sections.status_visibility'))->content(fn (?InterpretationGuide $record) => OpsEdit::statusVisibility($record)),
                                Forms\Components\Select::make('status')
                                    ->required()
                                    ->native(false)
                                    ->options(OpsEdit::statusOptions([InterpretationGuide::STATUS_DRAFT, InterpretationGuide::STATUS_SCHEDULED, InterpretationGuide::STATUS_PUBLISHED, InterpretationGuide::STATUS_ARCHIVED]))
                                    ->default(InterpretationGuide::STATUS_DRAFT),
                                Forms\Components\Select::make('review_state')
                                    ->required()
                                    ->native(false)
                                    ->options(OpsEdit::statusOptions([InterpretationGuide::REVIEW_DRAFT, InterpretationGuide::REVIEW_CONTENT, InterpretationGuide::REVIEW_SCIENCE_OR_PRODUCT, InterpretationGuide::REVIEW_APPROVED, InterpretationGuide::REVIEW_CHANGES_REQUESTED]))
                                    ->default(InterpretationGuide::REVIEW_DRAFT),
                                Forms\Components\DateTimePicker::make('last_reviewed_at'),
                                Forms\Components\DateTimePicker::make('published_at'),
                            ]),
                        Forms\Components\Section::make(__('ops.edit.sections.publish_readiness'))
                            ->description(__('ops.edit.descriptions.publish_readiness'))
                            ->extraAttributes(['class' => 'ops-edit-workspace-section ops-edit-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('ops_publish_readiness')->label(__('ops.edit.sections.publish_readiness'))->content(fn (?InterpretationGuide $record) => OpsEdit::publishReadiness($record, [
                                    'title' => __('ops.resources.articles.fields.title'),
                                    'slug' => __('ops.resources.articles.fields.slug'),
                                    'body_md' => __('ops.resources.articles.fields.content_md'),
                                    'seo_title' => __('ops.edit.fields.seo_title'),
                                    'seo_description' => __('ops.edit.fields.seo_description'),
                                ])),
                            ]),
                        Forms\Components\Section::make(__('ops.edit.sections.translation'))
                            ->description(__('ops.edit.descriptions.translation'))
                            ->extraAttributes(['class' => 'ops-edit-workspace-section ops-edit-workspace-section--rail'])
                            ->schema([Forms\Components\Placeholder::make('ops_translation')->label(__('ops.edit.sections.translation'))->content(fn (?InterpretationGuide $record) => OpsEdit::translation($record))]),
                        Forms\Components\Section::make(__('ops.edit.sections.revision'))
                            ->description(__('ops.edit.descriptions.revision'))
                            ->extraAttributes(['class' => 'ops-edit-workspace-section ops-edit-workspace-section--rail'])
                            ->schema([Forms\Components\Placeholder::make('ops_revision')->label(__('ops.edit.sections.revision'))->content(fn (?InterpretationGuide $record) => OpsEdit::revision($record))]),
                        Forms\Components\Section::make(__('ops.edit.sections.seo'))
                            ->description(__('ops.edit.descriptions.seo'))
                            ->extraAttributes(['class' => 'ops-edit-workspace-section ops-edit-workspace-section--rail'])
                            ->schema([Forms\Components\Placeholder::make('ops_seo')->label(__('ops.edit.sections.seo'))->content(fn (?InterpretationGuide $record) => OpsEdit::seo($record))]),
                        Forms\Components\Section::make(__('ops.edit.sections.audit'))
                            ->description(__('ops.edit.descriptions.audit'))
                            ->extraAttributes(['class' => 'ops-edit-workspace-section ops-edit-workspace-section--rail'])
                            ->schema([Forms\Components\Placeholder::make('ops_audit')->label(__('ops.edit.sections.audit'))->content(fn (?InterpretationGuide $record) => OpsEdit::audit($record))]),
                    ])->columnSpan(['xl' => 4])->extraAttributes(['class' => 'ops-edit-workspace-rail-column']),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                OpsTable::titleWithSlug('title', 'slug', __('ops.nav.interpretation_guides')),
                Tables\Columns\TextColumn::make('slug')
                    ->label(__('ops.resources.articles.fields.slug'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                OpsTable::locale(label: __('ops.locale_scope.content_locale')),
                Tables\Columns\TextColumn::make('source_locale')
                    ->label(__('ops.table.source_locale'))
                    ->state(fn (InterpretationGuide $record): string => (string) ($record->source_locale ?: OpsContentLocaleScope::sourceLocale($record->locale)))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                OpsTable::translationStatus(),
                Tables\Columns\TextColumn::make('test_family')
                    ->label(__('ops.table.category_type'))
                    ->badge()
                    ->sortable()
                    ->description(fn (InterpretationGuide $record): ?string => $record->result_context),
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
            'index' => Pages\ListInterpretationGuides::route('/'),
            'create' => Pages\CreateInterpretationGuide::route('/create'),
            'edit' => Pages\EditInterpretationGuide::route('/{record}/edit'),
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
