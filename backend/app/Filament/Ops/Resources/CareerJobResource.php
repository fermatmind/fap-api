<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\CareerJobResource\Pages;
use App\Filament\Ops\Resources\CareerJobResource\Support\CareerJobWorkspace;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\CareerJob;
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

class CareerJobResource extends Resource
{
    protected static ?string $model = CareerJob::class;

    protected static ?string $slug = 'career-jobs';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Career Jobs';

    protected static ?string $modelLabel = 'Career Job';

    protected static ?string $pluralModelLabel = 'Career Jobs';

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
        return 'Career Jobs';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('org_id')
                ->default(0),
            Forms\Components\Grid::make([
                'default' => 1,
                'xl' => 12,
            ])
                ->extraAttributes(['class' => 'ops-career-job-workspace-layout'])
                ->schema([
                    Forms\Components\Group::make([
                        Forms\Components\Section::make('Basic')
                            ->description('Shape the career job headline, summary, and industry context before editing structured signals.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--main'])
                            ->schema([
                                Forms\Components\TextInput::make('title')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull()
                                    ->helperText('Primary job heading used across the workspace, public detail page, and SEO fallbacks.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-career-job-workspace-field ops-career-job-workspace-field--title'])
                                    ->extraInputAttributes(['class' => 'ops-career-job-workspace-input ops-career-job-workspace-input--title']),
                                Forms\Components\TextInput::make('subtitle')
                                    ->maxLength(255)
                                    ->columnSpanFull()
                                    ->helperText('Optional supporting line for the hero and quick editorial scanning.'),
                                Forms\Components\Textarea::make('excerpt')
                                    ->rows(4)
                                    ->columnSpanFull()
                                    ->helperText('Short summary used for list previews, public excerpts, and SEO description fallbacks.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-career-job-workspace-field ops-career-job-workspace-field--summary']),
                                Forms\Components\TextInput::make('industry_slug')
                                    ->maxLength(128)
                                    ->helperText('Lightweight industry key used for current frontend grouping.'),
                                Forms\Components\TextInput::make('industry_label')
                                    ->maxLength(255)
                                    ->helperText('Optional editor-facing label for the industry until Industry becomes its own CMS object.'),
                            ])
                            ->columns(2),
                        Forms\Components\Section::make('Hero')
                            ->description('Optional framing content for future frontend hero treatments. Safe to leave empty.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--main'])
                            ->schema([
                                Forms\Components\TextInput::make('hero_kicker')
                                    ->maxLength(128),
                                Forms\Components\Textarea::make('hero_quote')
                                    ->rows(4),
                                Forms\Components\TextInput::make('cover_image_url')
                                    ->maxLength(2048)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                        Forms\Components\Section::make('Primary narrative')
                            ->description('The main long-form career narrative stays on the job record instead of being broken into free-form sections.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--main'])
                            ->schema([
                                Forms\Components\MarkdownEditor::make('body_md')
                                    ->columnSpanFull()
                                    ->helperText('Use markdown for the primary career narrative shown after structured job signals on the public page.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-career-job-workspace-field ops-career-job-workspace-field--editor']),
                            ]),
                        Forms\Components\Section::make('Job signals')
                            ->description('Edit recommendation-relevant signals through constrained inputs instead of raw JSON blobs.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--main'])
                            ->schema([
                                Forms\Components\Tabs::make('Career job signals')
                                    ->contained(false)
                                    ->extraAttributes(['class' => 'ops-career-job-workspace-tabs'])
                                    ->tabs(self::signalTabs())
                                    ->columnSpanFull(),
                            ]),
                        Forms\Components\Section::make('Supplemental sections')
                            ->description('Fixed supplemental narrative blocks only. These complement the main body and do not replace it.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--main'])
                            ->schema([
                                Forms\Components\Tabs::make('Career job sections')
                                    ->contained(false)
                                    ->extraAttributes(['class' => 'ops-career-job-workspace-tabs'])
                                    ->tabs(self::sectionTabs())
                                    ->columnSpanFull(),
                            ]),
                    ])
                        ->columnSpan([
                            'xl' => 8,
                        ])
                        ->extraAttributes(['class' => 'ops-career-job-workspace-main-column']),
                    Forms\Components\Group::make([
                        Forms\Components\Section::make('Identity')
                            ->description('Career CMS Lite v1 manages global career content only. Org scope stays fixed to org_id=0.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--rail'])
                            ->schema([
                                Forms\Components\TextInput::make('job_code')
                                    ->required()
                                    ->maxLength(96)
                                    ->placeholder('product-manager')
                                    ->helperText('Stable internal key for this career job. Defaults to the slug if left blank.')
                                    ->live(onBlur: true)
                                    ->dehydrateStateUsing(fn (?string $state, Forms\Get $get): string => CareerJobWorkspace::normalizeJobCode(
                                        $state,
                                        (string) $get('slug'),
                                    ))
                                    ->afterStateUpdated(function (?string $state, Forms\Set $set, Forms\Get $get): void {
                                        if (trim((string) $get('slug')) !== '') {
                                            return;
                                        }

                                        $set('slug', CareerJobWorkspace::normalizeSlug(null, $state));
                                    })
                                    ->rules(['regex:/^[a-z0-9-]+$/']),
                                Forms\Components\TextInput::make('slug')
                                    ->required()
                                    ->maxLength(128)
                                    ->placeholder('product-manager')
                                    ->helperText('Frontend route key for locale-aware `/career/jobs/{slug}` pages.')
                                    ->dehydrateStateUsing(fn (?string $state, Forms\Get $get): string => CareerJobWorkspace::normalizeSlug(
                                        $state,
                                        (string) $get('job_code'),
                                    ))
                                    ->rules(['regex:/^[a-z0-9-]+$/']),
                                Forms\Components\Select::make('locale')
                                    ->required()
                                    ->native(false)
                                    ->options(self::localeOptions())
                                    ->default('en')
                                    ->helperText('Stored as backend locale codes and mapped to `/en` or `/zh` in planned URLs.'),
                                Forms\Components\Placeholder::make('identity_org')
                                    ->label('Content scope')
                                    ->content('Global career content (org_id=0)'),
                            ])
                            ->columns(2),
                        Forms\Components\Section::make('Publish')
                            ->description('Keep release state, visibility, indexing, and ordering cues together in the metadata rail.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('workspace_state')
                                    ->label('Editorial cues')
                                    ->content(fn (Forms\Get $get, ?CareerJob $record) => CareerJobWorkspace::renderEditorialCues($get, $record))
                                    ->columnSpanFull(),
                                Forms\Components\Select::make('status')
                                    ->required()
                                    ->native(false)
                                    ->options(self::statusOptions())
                                    ->default(CareerJob::STATUS_DRAFT),
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
                                    ->helperText('List ordering for future job directories and ops tables.'),
                            ]),
                        Forms\Components\Section::make('SEO')
                            ->description('Keep search metadata, social overrides, and robots hints in one reviewable rail.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('seo_snapshot')
                                    ->label('SEO snapshot')
                                    ->content(fn (Forms\Get $get, ?CareerJob $record) => CareerJobWorkspace::renderSeoSnapshot($get, $record))
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('workspace_seo.seo_title')
                                    ->label('SEO title')
                                    ->maxLength(255)
                                    ->helperText('Search headline fallback is the job title if left blank.'),
                                Forms\Components\Textarea::make('workspace_seo.seo_description')
                                    ->label('SEO description')
                                    ->rows(3)
                                    ->helperText('Description fallback is excerpt, then subtitle.'),
                                Forms\Components\TextInput::make('workspace_seo.canonical_url')
                                    ->label('Canonical override')
                                    ->maxLength(2048)
                                    ->helperText('Optional stored override. Planned canonical still follows locale-aware career job URLs.'),
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
                            ->description('Read-only context so editors can review the planned frontend route, timestamps, and revision trail before cutover.')
                            ->extraAttributes(['class' => 'ops-career-job-workspace-section ops-career-job-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('planned_public_url')
                                    ->label('Planned public URL')
                                    ->content(fn (Forms\Get $get, ?CareerJob $record): string => CareerJobWorkspace::plannedPublicUrl(
                                        (string) ($get('slug') ?? $record?->slug ?? ''),
                                        (string) ($get('locale') ?? $record?->locale ?? 'en'),
                                    ) ?? 'Appears once slug and locale are set.'),
                                Forms\Components\Placeholder::make('created_at_summary')
                                    ->label('Created')
                                    ->content(fn (?CareerJob $record): string => CareerJobWorkspace::formatTimestamp(
                                        $record?->created_at,
                                        'Workspace draft not saved yet',
                                    )),
                                Forms\Components\Placeholder::make('updated_at_summary')
                                    ->label('Last updated')
                                    ->content(fn (?CareerJob $record): string => CareerJobWorkspace::formatTimestamp(
                                        $record?->updated_at,
                                        'Workspace draft not saved yet',
                                    )),
                                Forms\Components\Placeholder::make('revision_count_summary')
                                    ->label('Revision count')
                                    ->content(fn (?CareerJob $record): string => $record instanceof CareerJob
                                        ? (string) $record->revisions()->count()
                                        : 'Revision #1 will be written when this career job is created'),
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
                    ->label('Career Job')
                    ->html()
                    ->searchable(['title', 'job_code', 'slug'])
                    ->sortable()
                    ->formatStateUsing(fn (CareerJob $record): string => (string) view('filament.ops.career-jobs.partials.table-title', [
                        'meta' => CareerJobWorkspace::titleMeta($record),
                        'title' => $record->title,
                    ])->render()),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => Str::of($state)->headline()->value())
                    ->description(fn (CareerJob $record): string => CareerJobWorkspace::visibilityMeta($record))
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
                Tables\Columns\TextColumn::make('industry_slug')
                    ->label('Industry')
                    ->sortable()
                    ->toggleable(),
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
                    ->options(self::localeOptions()),
                Tables\Filters\SelectFilter::make('status')
                    ->options(self::statusOptions()),
                TernaryFilter::make('is_public')
                    ->label('Public'),
                TernaryFilter::make('is_indexable')
                    ->label('Indexable'),
                Tables\Filters\SelectFilter::make('industry_slug')
                    ->label('Industry')
                    ->options(self::industryFilterOptions()),
            ])
            ->searchPlaceholder('Search title, job code, or slug')
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (CareerJob $record): string => static::getUrl('edit', ['record' => $record]))
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray'),
                Tables\Actions\Action::make('release')
                    ->label('Release')
                    ->icon('heroicon-o-rocket-launch')
                    ->color('primary')
                    ->visible(fn (CareerJob $record): bool => ContentAccess::canRelease() && $record->status !== CareerJob::STATUS_PUBLISHED)
                    ->action(fn (CareerJob $record) => self::releaseRecord($record)),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCareerJobs::route('/'),
            'create' => Pages\CreateCareerJob::route('/create'),
            'edit' => Pages\EditCareerJob::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes()
            ->where('org_id', 0);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return [
            CareerJob::STATUS_DRAFT => CareerJob::STATUS_DRAFT,
            CareerJob::STATUS_PUBLISHED => CareerJob::STATUS_PUBLISHED,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function localeOptions(): array
    {
        return collect(CareerJob::SUPPORTED_LOCALES)
            ->mapWithKeys(static fn (string $locale): array => [$locale => $locale])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function industryFilterOptions(): array
    {
        return CareerJob::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->whereNotNull('industry_slug')
            ->orderBy('industry_slug')
            ->pluck('industry_slug', 'industry_slug')
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function mbtiTypeOptions(): array
    {
        return collect([
            'INTJ', 'INTP', 'ENTJ', 'ENTP',
            'INFJ', 'INFP', 'ENFJ', 'ENFP',
            'ISTJ', 'ISFJ', 'ESTJ', 'ESFJ',
            'ISTP', 'ISFP', 'ESTP', 'ESFP',
        ])->mapWithKeys(static fn (string $code): array => [$code => $code])->all();
    }

    /**
     * @return array<string, string>
     */
    private static function marketSignalOptions(): array
    {
        return [
            'low' => 'low',
            'balanced' => 'balanced',
            'high' => 'high',
            'unknown' => 'unknown',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function traitTargetOptions(): array
    {
        return [
            'low' => 'low',
            'balanced' => 'balanced',
            'high' => 'high',
        ];
    }

    /**
     * @return array<int, Forms\Components\Tabs\Tab>
     */
    private static function signalTabs(): array
    {
        return [
            Forms\Components\Tabs\Tab::make('Compensation & Outlook')
                ->schema([
                    Forms\Components\TextInput::make('salary_json.currency')
                        ->label('Salary currency')
                        ->maxLength(16),
                    Forms\Components\TextInput::make('salary_json.region')
                        ->label('Salary region')
                        ->maxLength(32),
                    Forms\Components\TextInput::make('salary_json.low')
                        ->label('Salary low')
                        ->numeric(),
                    Forms\Components\TextInput::make('salary_json.median')
                        ->label('Salary median')
                        ->numeric(),
                    Forms\Components\TextInput::make('salary_json.high')
                        ->label('Salary high')
                        ->numeric(),
                    Forms\Components\Textarea::make('salary_json.notes')
                        ->label('Salary notes')
                        ->rows(3)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('outlook_json.summary')
                        ->label('Outlook summary')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('outlook_json.horizon_years')
                        ->label('Outlook horizon (years)')
                        ->numeric(),
                    Forms\Components\Textarea::make('outlook_json.notes')
                        ->label('Outlook notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Forms\Components\Tabs\Tab::make('Work & Skills')
                ->schema([
                    Forms\Components\TagsInput::make('work_contents_json.items')
                        ->label('Work contents')
                        ->helperText('Each tag becomes one main responsibility or work-content item.')
                        ->columnSpanFull(),
                    Forms\Components\TagsInput::make('skills_json.core')
                        ->label('Core skills')
                        ->helperText('Primary capabilities that define day-to-day fit.'),
                    Forms\Components\TagsInput::make('skills_json.supporting')
                        ->label('Supporting skills')
                        ->helperText('Secondary capabilities that strengthen performance but are not always central.'),
                ])
                ->columns(2),
            Forms\Components\Tabs\Tab::make('Growth & Market')
                ->schema([
                    Forms\Components\TextInput::make('growth_path_json.entry')
                        ->label('Entry level')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('growth_path_json.mid')
                        ->label('Mid level')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('growth_path_json.senior')
                        ->label('Senior level')
                        ->maxLength(255),
                    Forms\Components\Textarea::make('growth_path_json.notes')
                        ->label('Growth notes')
                        ->rows(3)
                        ->columnSpanFull(),
                    Forms\Components\Select::make('market_demand_json.signal')
                        ->label('Market demand signal')
                        ->native(false)
                        ->options(self::marketSignalOptions()),
                    Forms\Components\Textarea::make('market_demand_json.notes')
                        ->label('Market demand notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Forms\Components\Tabs\Tab::make('Personality & Assessment Signals')
                ->schema([
                    Forms\Components\Select::make('fit_personality_codes_json')
                        ->label('Fit personality codes')
                        ->multiple()
                        ->native(false)
                        ->options(self::mbtiTypeOptions())
                        ->helperText('Constrain this field to the 16 MBTI codes for v1 consistency.')
                        ->columnSpanFull(),
                    Forms\Components\Select::make('mbti_primary_codes_json')
                        ->label('MBTI primary codes')
                        ->multiple()
                        ->native(false)
                        ->options(self::mbtiTypeOptions()),
                    Forms\Components\Select::make('mbti_secondary_codes_json')
                        ->label('MBTI secondary codes')
                        ->multiple()
                        ->native(false)
                        ->options(self::mbtiTypeOptions()),
                    Forms\Components\TextInput::make('riasec_profile_json.R')
                        ->label('RIASEC R')
                        ->numeric()
                        ->helperText('Suggested 0-100'),
                    Forms\Components\TextInput::make('riasec_profile_json.I')
                        ->label('RIASEC I')
                        ->numeric()
                        ->helperText('Suggested 0-100'),
                    Forms\Components\TextInput::make('riasec_profile_json.A')
                        ->label('RIASEC A')
                        ->numeric()
                        ->helperText('Suggested 0-100'),
                    Forms\Components\TextInput::make('riasec_profile_json.S')
                        ->label('RIASEC S')
                        ->numeric()
                        ->helperText('Suggested 0-100'),
                    Forms\Components\TextInput::make('riasec_profile_json.E')
                        ->label('RIASEC E')
                        ->numeric()
                        ->helperText('Suggested 0-100'),
                    Forms\Components\TextInput::make('riasec_profile_json.C')
                        ->label('RIASEC C')
                        ->numeric()
                        ->helperText('Suggested 0-100'),
                    Forms\Components\Select::make('big5_targets_json.openness')
                        ->label('Big5 openness')
                        ->native(false)
                        ->options(self::traitTargetOptions()),
                    Forms\Components\Select::make('big5_targets_json.conscientiousness')
                        ->label('Big5 conscientiousness')
                        ->native(false)
                        ->options(self::traitTargetOptions()),
                    Forms\Components\Select::make('big5_targets_json.extraversion')
                        ->label('Big5 extraversion')
                        ->native(false)
                        ->options(self::traitTargetOptions()),
                    Forms\Components\Select::make('big5_targets_json.agreeableness')
                        ->label('Big5 agreeableness')
                        ->native(false)
                        ->options(self::traitTargetOptions()),
                    Forms\Components\Select::make('big5_targets_json.neuroticism')
                        ->label('Big5 neuroticism')
                        ->native(false)
                        ->options(self::traitTargetOptions()),
                    Forms\Components\Textarea::make('iq_eq_notes_json.iq')
                        ->label('IQ notes')
                        ->rows(3),
                    Forms\Components\Textarea::make('iq_eq_notes_json.eq')
                        ->label('EQ notes')
                        ->rows(3),
                ])
                ->columns(2),
        ];
    }

    /**
     * @return array<int, Forms\Components\Tabs\Tab>
     */
    private static function sectionTabs(): array
    {
        $variantOptions = CareerJobWorkspace::renderVariantOptions();

        return collect(CareerJobWorkspace::sectionDefinitions())
            ->map(function (array $definition, string $sectionKey) use ($variantOptions): Forms\Components\Tabs\Tab {
                return Forms\Components\Tabs\Tab::make($definition['label'])
                    ->schema([
                        Forms\Components\Toggle::make("workspace_sections.{$sectionKey}.is_enabled")
                            ->label('Enable this section')
                            ->helperText($definition['description'])
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make("workspace_sections.{$sectionKey}.title")
                            ->label('Section title')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make("workspace_sections.{$sectionKey}.render_variant")
                            ->label('Render variant')
                            ->native(false)
                            ->options($variantOptions)
                            ->required(),
                        Forms\Components\Textarea::make("workspace_sections.{$sectionKey}.body_md")
                            ->label('Narrative body')
                            ->rows(8)
                            ->columnSpanFull()
                            ->helperText('Use markdown-friendly narrative copy when this section needs long-form explanation.')
                            ->extraFieldWrapperAttributes(['class' => 'ops-career-job-workspace-field ops-career-job-workspace-field--body']),
                        Forms\Components\Textarea::make("workspace_sections.{$sectionKey}.payload_json_text")
                            ->label('Structured payload JSON')
                            ->rows(6)
                            ->columnSpanFull()
                            ->helperText('Optional structured payload for cards, FAQ, links, or other fixed render variants.')
                            ->rules(['nullable', 'json'])
                            ->extraFieldWrapperAttributes(['class' => 'ops-career-job-workspace-field ops-career-job-workspace-field--payload']),
                    ])
                    ->columns(2);
            })
            ->values()
            ->all();
    }

    private static function canRead(): bool
    {
        return ContentAccess::canRead();
    }

    private static function canWrite(): bool
    {
        return ContentAccess::canWrite();
    }

    public static function releaseRecord(CareerJob $record): void
    {
        if (! ContentAccess::canRelease()) {
            throw new AuthorizationException('You do not have permission to release career jobs.');
        }

        if ($record->status === CareerJob::STATUS_PUBLISHED) {
            return;
        }

        $record->forceFill([
            'status' => CareerJob::STATUS_PUBLISHED,
            'published_at' => $record->published_at ?? now(),
        ])->save();

        Notification::make()
            ->title('Career job released')
            ->body('The career job is now marked as published.')
            ->success()
            ->send();
    }
}
