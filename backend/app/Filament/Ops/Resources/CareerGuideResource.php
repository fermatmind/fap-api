<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\CareerGuideResource\Pages;
use App\Filament\Ops\Resources\CareerGuideResource\Support\CareerGuideWorkspace;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\CareerGuide;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class CareerGuideResource extends Resource
{
    protected static ?string $model = CareerGuide::class;

    protected static ?string $slug = 'career-guides';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Career Guides';

    protected static ?string $modelLabel = 'Career Guide';

    protected static ?string $pluralModelLabel = 'Career Guides';

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
        return __('ops.group.content_workspace');
    }

    public static function getNavigationLabel(): string
    {
        return 'Career Guides';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('org_id')
                ->default(0),
            Forms\Components\Hidden::make('schema_version')
                ->default('v1'),
            Forms\Components\Grid::make([
                'default' => 1,
                'xl' => 12,
            ])
                ->extraAttributes(['class' => 'ops-career-job-workspace-layout'])
                ->schema([
                    Forms\Components\Group::make([
                        Forms\Components\Section::make('Basic')
                            ->description('Shape the guide headline, summary, and category before editing the long-form body or related content.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--main'])
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull()
                                    ->helperText('Primary guide headline used in list rows, detail pages, and SEO fallbacks.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-career-job-workspace-field ops-career-job-workspace-field--title'])
                                    ->extraInputAttributes(['class' => 'ops-career-job-workspace-input ops-career-job-workspace-input--title']),
                                Forms\Components\Textarea::make('excerpt')
                                    ->rows(4)
                                    ->columnSpanFull()
                                    ->helperText('Short summary used for the guide list, `/career` landing slice, and search description fallbacks.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-career-job-workspace-field ops-career-job-workspace-field--summary']),
                                Forms\Components\Select::make('category_slug')
                                    ->label('Category')
                                    ->native(false)
                                    ->options(CareerGuideWorkspace::categoryOptions())
                                    ->helperText('V1 stays on the fixed guide category slug set. No taxonomy manager in this workspace.'),
                            ])
                            ->columns(2),
                        Forms\Components\Section::make('Body')
                            ->description('Keep the guide body as one markdown narrative. Sections stay out of scope for G03.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--main'])
                            ->schema([
                                Forms\Components\MarkdownEditor::make('body_md')
                                    ->label('Guide body')
                                    ->columnSpanFull()
                                    ->helperText('Use markdown for the full guide narrative. `body_html` stays derived and is not editable here.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-career-job-workspace-field ops-career-job-workspace-field--editor']),
                            ]),
                        Forms\Components\Section::make('Relations')
                            ->description('Keep relation editing aligned with current frontend detail ordering and constrain selectable targets to the same locale.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--main'])
                            ->schema([
                                Forms\Components\Tabs::make('Career guide relations')
                                    ->contained(false)
                                    ->extraAttributes(['class' => 'ops-career-job-workspace-tabs'])
                                    ->tabs([
                                        Forms\Components\Tabs\Tab::make('Related jobs')
                                            ->schema([
                                                Forms\Components\Repeater::make('workspace_related_jobs')
                                                    ->label('Related jobs')
                                                    ->default([])
                                                    ->addActionLabel('Add related job')
                                                    ->helperText('Only global career jobs in the current guide locale are searchable here.')
                                                    ->itemLabel(fn (array $state): ?string => CareerGuideWorkspace::careerJobOptionLabelById($state['career_job_id'] ?? null))
                                                    ->reorderableWithButtons()
                                                    ->collapsible()
                                                    ->columnSpanFull()
                                                    ->schema([
                                                        Forms\Components\Select::make('career_job_id')
                                                            ->label('Career job')
                                                            ->required()
                                                            ->native(false)
                                                            ->searchable()
                                                            ->getSearchResultsUsing(fn (string $search, Forms\Get $get, ?CareerGuide $record): array => CareerGuideWorkspace::careerJobSearchResults(
                                                                $search,
                                                                CareerGuideWorkspace::relationLocaleFromGet($get, $record),
                                                            ))
                                                            ->getOptionLabelUsing(fn (int|string|null $value): ?string => CareerGuideWorkspace::careerJobOptionLabelById($value))
                                                            ->helperText('No cross-locale fallback is allowed in G03.'),
                                                    ]),
                                            ]),
                                        Forms\Components\Tabs\Tab::make('Related industries')
                                            ->schema([
                                                Forms\Components\TagsInput::make('related_industry_slugs_json')
                                                    ->label('Related industry slugs')
                                                    ->helperText('Slug array only. Values are trimmed, lowercased, and deduplicated. No industry object is implied here.')
                                                    ->columnSpanFull()
                                                    ->dehydrateStateUsing(fn (mixed $state): array => CareerGuideWorkspace::normalizeIndustrySlugs($state)),
                                            ]),
                                        Forms\Components\Tabs\Tab::make('Related articles')
                                            ->schema([
                                                Forms\Components\Repeater::make('workspace_related_articles')
                                                    ->label('Related articles')
                                                    ->default([])
                                                    ->addActionLabel('Add related article')
                                                    ->helperText('Only global articles in the current guide locale are searchable here.')
                                                    ->itemLabel(fn (array $state): ?string => CareerGuideWorkspace::articleOptionLabelById($state['article_id'] ?? null))
                                                    ->reorderableWithButtons()
                                                    ->collapsible()
                                                    ->columnSpanFull()
                                                    ->schema([
                                                        Forms\Components\Select::make('article_id')
                                                            ->label('Article')
                                                            ->required()
                                                            ->native(false)
                                                            ->searchable()
                                                            ->getSearchResultsUsing(fn (string $search, Forms\Get $get, ?CareerGuide $record): array => CareerGuideWorkspace::articleSearchResults(
                                                                $search,
                                                                CareerGuideWorkspace::relationLocaleFromGet($get, $record),
                                                            ))
                                                            ->getOptionLabelUsing(fn (int|string|null $value): ?string => CareerGuideWorkspace::articleOptionLabelById($value))
                                                            ->helperText('This replaces the current frontend hardcode with explicit workspace relations.'),
                                                    ]),
                                            ]),
                                        Forms\Components\Tabs\Tab::make('Related personality profiles')
                                            ->schema([
                                                Forms\Components\Repeater::make('workspace_related_personality_profiles')
                                                    ->label('Related personality profiles')
                                                    ->default([])
                                                    ->addActionLabel('Add related personality profile')
                                                    ->helperText('Only global MBTI personality profiles in the current guide locale are searchable here.')
                                                    ->itemLabel(fn (array $state): ?string => CareerGuideWorkspace::personalityOptionLabelById($state['personality_profile_id'] ?? null))
                                                    ->reorderableWithButtons()
                                                    ->collapsible()
                                                    ->columnSpanFull()
                                                    ->schema([
                                                        Forms\Components\Select::make('personality_profile_id')
                                                            ->label('Personality profile')
                                                            ->required()
                                                            ->native(false)
                                                            ->searchable()
                                                            ->getSearchResultsUsing(fn (string $search, Forms\Get $get, ?CareerGuide $record): array => CareerGuideWorkspace::personalitySearchResults(
                                                                $search,
                                                                CareerGuideWorkspace::relationLocaleFromGet($get, $record),
                                                            ))
                                                            ->getOptionLabelUsing(fn (int|string|null $value): ?string => CareerGuideWorkspace::personalityOptionLabelById($value))
                                                            ->helperText('Stay locale-aware here. Recommendation-layer objects are out of scope for this workspace.'),
                                                    ]),
                                            ]),
                                    ])
                                    ->columnSpanFull(),
                            ]),
                    ])
                        ->columnSpan([
                            'xl' => 8,
                        ])
                        ->extraAttributes(['class' => 'ops-career-job-workspace-main-column']),
                    Forms\Components\Group::make([
                        Forms\Components\Section::make('Identity')
                            ->description('Career guides in G03 are global content objects only. Org scope stays fixed to org_id=0.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--rail'])
                            ->schema([
                                Forms\Components\TextInput::make('guide_code')
                                    ->required()
                                    ->maxLength(96)
                                    ->placeholder('annual-career-review-system')
                                    ->helperText('Stable internal key used to connect locale variants and alternates. Defaults to the slug if left blank.')
                                    ->live(onBlur: true)
                                    ->dehydrateStateUsing(fn (?string $state, Forms\Get $get): string => CareerGuideWorkspace::normalizeGuideCode(
                                        $state,
                                        (string) $get('slug'),
                                    ))
                                    ->afterStateUpdated(function (?string $state, Forms\Set $set, Forms\Get $get): void {
                                        if (trim((string) $get('slug')) !== '') {
                                            return;
                                        }

                                        $set('slug', CareerGuideWorkspace::normalizeSlug(null, $state));
                                    })
                                    ->rules(['regex:/^[a-z0-9-]+$/']),
                                Forms\Components\TextInput::make('slug')
                                    ->required()
                                    ->maxLength(128)
                                    ->placeholder('annual-career-review-system')
                                    ->helperText('Frontend route key for locale-aware `/career/guides/{slug}` pages.')
                                    ->dehydrateStateUsing(fn (?string $state, Forms\Get $get): string => CareerGuideWorkspace::normalizeSlug(
                                        $state,
                                        (string) $get('guide_code'),
                                    ))
                                    ->rules(['regex:/^[a-z0-9-]+$/']),
                                Forms\Components\Select::make('locale')
                                    ->required()
                                    ->native(false)
                                    ->options(CareerGuideWorkspace::localeOptions())
                                    ->default('en')
                                    ->live()
                                    ->helperText('Stored as backend locale codes and mapped to `/en` or `/zh` in planned URLs.'),
                                Forms\Components\Placeholder::make('identity_org')
                                    ->label('Content scope')
                                    ->content('Global career content (org_id=0)'),
                            ])
                            ->columns(2),
                        Forms\Components\Section::make('Publish')
                            ->description('Keep release state, visibility, indexing, scheduling, and ordering cues together in the side rail.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('workspace_state')
                                    ->label('Editorial cues')
                                    ->content(fn (Forms\Get $get, ?CareerGuide $record) => CareerGuideWorkspace::renderEditorialCues($get, $record))
                                    ->columnSpanFull(),
                                Forms\Components\Select::make('status')
                                    ->required()
                                    ->native(false)
                                    ->options(CareerGuideWorkspace::statusOptions())
                                    ->default(CareerGuide::STATUS_DRAFT),
                                Forms\Components\Toggle::make('is_public')
                                    ->label('Public visibility')
                                    ->default(true),
                                Forms\Components\Toggle::make('is_indexable')
                                    ->label('Search indexable')
                                    ->default(true),
                                Forms\Components\DateTimePicker::make('published_at'),
                                Forms\Components\DateTimePicker::make('scheduled_at'),
                                Forms\Components\TextInput::make('sort_order')
                                    ->numeric()
                                    ->default(0)
                                    ->helperText('Used by the public guide list ordering once the frontend cutover happens.'),
                            ]),
                        Forms\Components\Section::make('SEO')
                            ->description('Preview search metadata and planned canonical routing without implying live runtime authority.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('seo_snapshot')
                                    ->label('SEO snapshot')
                                    ->content(fn (Forms\Get $get, ?CareerGuide $record) => CareerGuideWorkspace::renderSeoSnapshot($get, $record))
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('workspace_seo.seo_title')
                                    ->label('SEO title')
                                    ->maxLength(255)
                                    ->helperText('Search headline fallback is the guide title if this stays empty.'),
                                Forms\Components\Textarea::make('workspace_seo.seo_description')
                                    ->label('SEO description')
                                    ->rows(3)
                                    ->helperText('Search description fallback is excerpt, then the guide body.'),
                                Forms\Components\TextInput::make('workspace_seo.canonical_url')
                                    ->label('Canonical override')
                                    ->maxLength(2048)
                                    ->helperText('Optional stored override. The planned canonical preview still follows locale-aware guide URLs.'),
                                Forms\Components\TextInput::make('workspace_seo.og_title')
                                    ->label('Open Graph title')
                                    ->maxLength(255),
                                Forms\Components\Textarea::make('workspace_seo.og_description')
                                    ->label('Open Graph description')
                                    ->rows(3),
                                Forms\Components\TextInput::make('workspace_seo.og_image_url')
                                    ->label('Open Graph image URL')
                                    ->maxLength(2048),
                                Forms\Components\TextInput::make('workspace_seo.twitter_title')
                                    ->label('Twitter title')
                                    ->maxLength(255),
                                Forms\Components\Textarea::make('workspace_seo.twitter_description')
                                    ->label('Twitter description')
                                    ->rows(3),
                                Forms\Components\TextInput::make('workspace_seo.twitter_image_url')
                                    ->label('Twitter image URL')
                                    ->maxLength(2048),
                                Forms\Components\TextInput::make('workspace_seo.robots')
                                    ->label('Robots')
                                    ->maxLength(64)
                                    ->helperText('Leave blank to derive robots from the current indexability toggle.'),
                            ]),
                        Forms\Components\Section::make('Record cues')
                            ->description('Read-only context for planned routing, timestamps, and revision trail. No live public URL is exposed here.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('planned_public_url')
                                    ->label('Planned public URL')
                                    ->content(fn (Forms\Get $get, ?CareerGuide $record): string => CareerGuideWorkspace::plannedPublicUrl(
                                        (string) ($get('slug') ?? $record?->slug ?? ''),
                                        (string) ($get('locale') ?? $record?->locale ?? 'en'),
                                    ) ?? 'Appears once slug and locale are set.'),
                                Forms\Components\Placeholder::make('created_at_summary')
                                    ->label('Created')
                                    ->content(fn (?CareerGuide $record): string => CareerGuideWorkspace::formatTimestamp(
                                        $record?->created_at,
                                        'Workspace draft not saved yet',
                                    )),
                                Forms\Components\Placeholder::make('updated_at_summary')
                                    ->label('Last updated')
                                    ->content(fn (?CareerGuide $record): string => CareerGuideWorkspace::formatTimestamp(
                                        $record?->updated_at,
                                        'Workspace draft not saved yet',
                                    )),
                                Forms\Components\Placeholder::make('revision_count_summary')
                                    ->label('Revision count')
                                    ->content(fn (?CareerGuide $record): string => $record instanceof CareerGuide
                                        ? (string) $record->revisions()->count()
                                        : 'Revision #1 will be written when this guide is created'),
                            ])
                            ->columns(2),
                    ])
                        ->columnSpan([
                            'xl' => 4,
                        ])
                        ->extraAttributes(['class' => 'ops-career-job-workspace-rail-column']),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Career Guide')
                    ->html()
                    ->searchable(['title', 'guide_code', 'slug'])
                    ->sortable()
                    ->formatStateUsing(fn (CareerGuide $record): string => (string) view('filament.ops.career-guides.partials.table-title', [
                        'meta' => CareerGuideWorkspace::titleMeta($record),
                        'title' => $record->title,
                    ])->render()),
                Tables\Columns\TextColumn::make('guide_code')
                    ->label('Guide code')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->copyable()
                    ->formatStateUsing(fn (string $state): string => '/'.trim($state, '/'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('locale')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => Str::of($state)->headline()->value())
                    ->description(fn (CareerGuide $record): string => CareerGuideWorkspace::visibilityMeta($record))
                    ->color(fn (string $state): string => StatusBadge::color($state)),
                Tables\Columns\TextColumn::make('is_public')
                    ->label('Visibility')
                    ->badge()
                    ->formatStateUsing(fn (bool|int|string|null $state): string => StatusBadge::booleanLabel($state, 'Public', 'Private'))
                    ->color(fn (bool|int|string|null $state): string => StatusBadge::booleanColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('is_indexable')
                    ->label('Indexing')
                    ->badge()
                    ->formatStateUsing(fn (bool|int|string|null $state): string => StatusBadge::booleanLabel($state, 'Indexable', 'Noindex'))
                    ->color(fn (bool|int|string|null $state): string => StatusBadge::booleanColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('category_slug')
                    ->label('Category')
                    ->sortable(),
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
                Tables\Filters\SelectFilter::make('locale')
                    ->options(CareerGuideWorkspace::localeOptions()),
                Tables\Filters\SelectFilter::make('status')
                    ->options(CareerGuideWorkspace::statusOptions()),
                TernaryFilter::make('is_public')
                    ->label('Public'),
                TernaryFilter::make('is_indexable')
                    ->label('Indexable'),
                Tables\Filters\SelectFilter::make('category_slug')
                    ->label('Category')
                    ->options(CareerGuideWorkspace::categoryOptions()),
            ])
            ->searchPlaceholder('Search title, guide code, or slug')
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (CareerGuide $record): string => static::getUrl('edit', ['record' => $record]))
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray'),
                Tables\Actions\Action::make('release')
                    ->label('Release')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('primary')
                    ->visible(fn (CareerGuide $record): bool => ContentAccess::canRelease() && $record->status !== CareerGuide::STATUS_PUBLISHED)
                    ->action(fn (CareerGuide $record) => self::releaseRecord($record)),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCareerGuides::route('/'),
            'create' => Pages\CreateCareerGuide::route('/create'),
            'edit' => Pages\EditCareerGuide::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes()
            ->where('org_id', 0);
    }

    private static function canRead(): bool
    {
        return ContentAccess::canRead();
    }

    private static function canWrite(): bool
    {
        return ContentAccess::canWrite();
    }

    public static function releaseRecord(CareerGuide $record): void
    {
        if (! ContentAccess::canRelease()) {
            throw new AuthorizationException('You do not have permission to release career guides.');
        }

        if ($record->status === CareerGuide::STATUS_PUBLISHED) {
            return;
        }

        $record->forceFill([
            'status' => CareerGuide::STATUS_PUBLISHED,
            'published_at' => $record->published_at ?? now(),
        ])->save();

        Notification::make()
            ->title('Career guide released')
            ->body('The career guide is now marked as published.')
            ->success()
            ->send();
    }
}
