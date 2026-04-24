<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\ContentPageResource\Pages;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\OpsContentLocaleScope;
use App\Filament\Ops\Support\OpsEdit;
use App\Filament\Ops\Support\OpsTable;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\ContentPage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContentPageResource extends Resource
{
    protected static ?string $model = ContentPage::class;

    protected static ?string $slug = 'content-pages';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Content Pages';

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
        return __('ops.nav.content_pages');
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
                                                Forms\Components\TextInput::make('path')->required()->maxLength(160),
                                                Forms\Components\Select::make('locale')
                                                    ->label(__('ops.locale_scope.content_locale'))
                                                    ->required()
                                                    ->native(false)
                                                    ->options(OpsEdit::localeOptions())
                                                    ->default('en'),
                                                Forms\Components\Placeholder::make('locale_scope_marker')
                                                    ->label(__('ops.locale_scope.editor_marker_label'))
                                                    ->content(fn (Forms\Get $get, ?ContentPage $record): string => OpsContentLocaleScope::editorMarker((string) ($get('locale') ?? $record?->locale ?? OpsContentLocaleScope::currentContentLocale())))
                                                    ->columnSpanFull(),
                                                Forms\Components\TextInput::make('kicker')->maxLength(96),
                                                Forms\Components\Textarea::make('summary')->rows(3)->maxLength(2000)->columnSpanFull(),
                                                Forms\Components\MarkdownEditor::make('content_md')->columnSpanFull()->extraFieldWrapperAttributes(['class' => 'ops-edit-workspace-field--editor']),
                                                Forms\Components\Textarea::make('content_html')->rows(8)->columnSpanFull(),
                                            ])
                                            ->columns(2),
                                    ]),
                                Forms\Components\Tabs\Tab::make(__('ops.edit.tabs.seo'))
                                    ->schema([
                                        Forms\Components\Section::make(__('ops.edit.sections.seo_fields'))
                                            ->schema([
                                                Forms\Components\TextInput::make('seo_title')->maxLength(255),
                                                Forms\Components\Textarea::make('meta_description')->rows(3)->maxLength(2000),
                                                Forms\Components\Textarea::make('seo_description')->rows(3)->maxLength(2000),
                                                Forms\Components\TextInput::make('canonical_path')->maxLength(255),
                                            ])
                                            ->columns(2),
                                    ]),
                                Forms\Components\Tabs\Tab::make(__('ops.edit.tabs.translation'))
                                    ->schema([
                                        Forms\Components\Section::make(__('ops.edit.sections.operations'))
                                            ->schema([
                                                Forms\Components\Select::make('kind')
                                                    ->required()
                                                    ->native(false)
                                                    ->options(OpsEdit::statusOptions([ContentPage::KIND_COMPANY, ContentPage::KIND_POLICY, ContentPage::KIND_HELP]))
                                                    ->default(ContentPage::KIND_COMPANY),
                                                Forms\Components\Select::make('page_type')
                                                    ->required()
                                                    ->native(false)
                                                    ->options(array_combine(ContentPage::PAGE_TYPES, ContentPage::PAGE_TYPES))
                                                    ->default('company'),
                                                Forms\Components\TextInput::make('template')->required()->maxLength(64)->default('company'),
                                                Forms\Components\TextInput::make('animation_profile')->required()->maxLength(64)->default('none'),
                                                Forms\Components\TextInput::make('owner')->maxLength(128),
                                                Forms\Components\TextInput::make('source_doc')->maxLength(255),
                                            ])
                                            ->columns(2),
                                    ]),
                                Forms\Components\Tabs\Tab::make(__('ops.edit.tabs.audit'))
                                    ->schema([
                                        Forms\Components\Section::make(__('ops.edit.sections.audit'))
                                            ->schema([
                                                Forms\Components\Toggle::make('legal_review_required')->default(false),
                                                Forms\Components\Toggle::make('science_review_required')->default(false),
                                                Forms\Components\DateTimePicker::make('source_updated_at'),
                                                Forms\Components\DateTimePicker::make('effective_at'),
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
                                Forms\Components\Placeholder::make('ops_status_visibility')->label(__('ops.edit.sections.status_visibility'))->content(fn (?ContentPage $record) => OpsEdit::statusVisibility($record)),
                                Forms\Components\Select::make('status')
                                    ->required()
                                    ->native(false)
                                    ->options(OpsEdit::statusOptions([ContentPage::STATUS_DRAFT, ContentPage::STATUS_SCHEDULED, ContentPage::STATUS_PUBLISHED, ContentPage::STATUS_ARCHIVED]))
                                    ->default(ContentPage::STATUS_DRAFT),
                                Forms\Components\Select::make('review_state')
                                    ->required()
                                    ->native(false)
                                    ->options(OpsEdit::statusOptions(ContentPage::REVIEW_STATES))
                                    ->default('draft'),
                                Forms\Components\Toggle::make('is_public')->default(false),
                                Forms\Components\Toggle::make('is_indexable')->default(true),
                                Forms\Components\DateTimePicker::make('last_reviewed_at'),
                                Forms\Components\DateTimePicker::make('published_at'),
                            ]),
                        Forms\Components\Section::make(__('ops.edit.sections.publish_readiness'))
                            ->description(__('ops.edit.descriptions.publish_readiness'))
                            ->extraAttributes(['class' => 'ops-edit-workspace-section ops-edit-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('ops_publish_readiness')->label(__('ops.edit.sections.publish_readiness'))->content(fn (?ContentPage $record) => OpsEdit::publishReadiness($record, [
                                    'title' => __('ops.resources.articles.fields.title'),
                                    'slug' => __('ops.resources.articles.fields.slug'),
                                    'path' => 'path',
                                    'content_md' => __('ops.resources.articles.fields.content_md'),
                                    'seo_title' => __('ops.edit.fields.seo_title'),
                                    'seo_description' => __('ops.edit.fields.seo_description'),
                                    'canonical_path' => __('ops.edit.fields.canonical_path'),
                                ])),
                            ]),
                        Forms\Components\Section::make(__('ops.edit.sections.translation'))
                            ->description(__('ops.edit.descriptions.translation'))
                            ->extraAttributes(['class' => 'ops-edit-workspace-section ops-edit-workspace-section--rail'])
                            ->schema([Forms\Components\Placeholder::make('ops_translation')->label(__('ops.edit.sections.translation'))->content(fn (?ContentPage $record) => OpsEdit::translation($record))]),
                        Forms\Components\Section::make(__('ops.edit.sections.revision'))
                            ->description(__('ops.edit.descriptions.revision'))
                            ->extraAttributes(['class' => 'ops-edit-workspace-section ops-edit-workspace-section--rail'])
                            ->schema([Forms\Components\Placeholder::make('ops_revision')->label(__('ops.edit.sections.revision'))->content(fn (?ContentPage $record) => OpsEdit::revision($record))]),
                        Forms\Components\Section::make(__('ops.edit.sections.seo'))
                            ->description(__('ops.edit.descriptions.seo'))
                            ->extraAttributes(['class' => 'ops-edit-workspace-section ops-edit-workspace-section--rail'])
                            ->schema([Forms\Components\Placeholder::make('ops_seo')->label(__('ops.edit.sections.seo'))->content(fn (?ContentPage $record) => OpsEdit::seo($record))]),
                        Forms\Components\Section::make(__('ops.edit.sections.audit'))
                            ->description(__('ops.edit.descriptions.audit'))
                            ->extraAttributes(['class' => 'ops-edit-workspace-section ops-edit-workspace-section--rail'])
                            ->schema([Forms\Components\Placeholder::make('ops_audit')->label(__('ops.edit.sections.audit'))->content(fn (?ContentPage $record) => OpsEdit::audit($record))]),
                    ])->columnSpan(['xl' => 4])->extraAttributes(['class' => 'ops-edit-workspace-rail-column']),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                OpsTable::titleWithSlug('title', 'slug', __('ops.nav.content_pages')),
                Tables\Columns\TextColumn::make('slug')
                    ->label(__('ops.resources.articles.fields.slug'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                OpsTable::locale(label: __('ops.locale_scope.content_locale')),
                Tables\Columns\TextColumn::make('source_locale')
                    ->label(__('ops.table.source_locale'))
                    ->state(fn (ContentPage $record): string => (string) ($record->source_locale ?: OpsContentLocaleScope::sourceLocale($record->locale)))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                OpsTable::translationStatus(),
                Tables\Columns\TextColumn::make('kind')
                    ->label(__('ops.table.category_type'))
                    ->badge()
                    ->sortable()
                    ->description(fn (ContentPage $record): ?string => $record->page_type),
                OpsTable::status(),
                Tables\Columns\TextColumn::make('review_state')
                    ->label(__('ops.resources.articles.fields.working_revision_status'))
                    ->badge()
                    ->color(fn (?string $state): string => StatusBadge::color($state))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('is_public')
                    ->label(__('ops.table.public'))
                    ->boolean()
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
            'index' => Pages\ListContentPages::route('/'),
            'create' => Pages\CreateContentPage::route('/create'),
            'edit' => Pages\EditContentPage::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes()->where('org_id', 0);
    }
}
