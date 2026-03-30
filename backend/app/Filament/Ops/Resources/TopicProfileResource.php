<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\TopicProfileResource\Pages;
use App\Filament\Ops\Resources\TopicProfileResource\Support\TopicWorkspace;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\ContentGovernanceForm;
use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\TopicProfile;
use App\Services\Cms\ContentPublishGateService;
use App\Support\Rbac\PermissionNames;
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

class TopicProfileResource extends Resource
{
    protected static ?string $model = TopicProfile::class;

    protected static ?string $slug = 'topics';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Topics';

    protected static ?string $modelLabel = 'Topic Profile';

    protected static ?string $pluralModelLabel = 'Topic Profiles';

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
        return 'Topics';
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
                ->extraAttributes(['class' => 'ops-topic-workspace-layout'])
                ->schema([
                    Forms\Components\Group::make([
                        Forms\Components\Section::make('Basic')
                            ->description('Shape the topic identity, title hierarchy, and summary before expanding the narrative rails or aggregated entries.')
                            ->extraAttributes(['class' => 'ops-topic-workspace-section ops-topic-workspace-section--main'])
                            ->schema([
                                Forms\Components\TextInput::make('topic_code')
                                    ->required()
                                    ->maxLength(64)
                                    ->placeholder('mbti')
                                    ->helperText('Stable internal key for this topic hub. Keep it lowercase and durable.')
                                    ->live(onBlur: true)
                                    ->dehydrateStateUsing(fn (?string $state): string => TopicWorkspace::normalizeTopicCode($state))
                                    ->afterStateUpdated(function (?string $state, Forms\Set $set, Forms\Get $get): void {
                                        if (trim((string) $get('slug')) !== '') {
                                            return;
                                        }

                                        $set('slug', TopicWorkspace::normalizeSlug(null, $state));
                                    })
                                    ->rules(['regex:/^[a-z0-9-]+$/']),
                                Forms\Components\TextInput::make('slug')
                                    ->required()
                                    ->maxLength(128)
                                    ->placeholder('mbti')
                                    ->helperText('Frontend route key for `/en/topics/{slug}` and `/zh/topics/{slug}`.')
                                    ->dehydrateStateUsing(fn (?string $state, Forms\Get $get): string => TopicWorkspace::normalizeSlug(
                                        $state,
                                        (string) $get('topic_code'),
                                    ))
                                    ->rules(['regex:/^[a-z0-9-]+$/']),
                                Forms\Components\Select::make('locale')
                                    ->required()
                                    ->native(false)
                                    ->options(self::localeOptions())
                                    ->default('en')
                                    ->helperText('Stored as backend locale codes; frontend routes still resolve to locale-aware `/en` and `/zh`.'),
                                Forms\Components\TextInput::make('title')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull()
                                    ->helperText('Primary topic heading shown in list rows, public hubs, and SEO fallbacks.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-topic-workspace-field ops-topic-workspace-field--title'])
                                    ->extraInputAttributes(['class' => 'ops-topic-workspace-input ops-topic-workspace-input--title']),
                                Forms\Components\TextInput::make('subtitle')
                                    ->maxLength(255)
                                    ->columnSpanFull()
                                    ->helperText('Supporting line for the hero and high-level positioning of the topic hub.'),
                                Forms\Components\Textarea::make('excerpt')
                                    ->rows(4)
                                    ->columnSpanFull()
                                    ->helperText('Short summary used across list cards, resolver fallbacks, and SEO fallback copy.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-topic-workspace-field ops-topic-workspace-field--summary']),
                            ])
                            ->columns(3),
                        Forms\Components\Section::make('Hero')
                            ->description('Frame the topic hub with a short kicker, quote, and optional cover image without turning it into a generic article header.')
                            ->extraAttributes(['class' => 'ops-topic-workspace-section ops-topic-workspace-section--main'])
                            ->schema([
                                Forms\Components\TextInput::make('hero_kicker')
                                    ->maxLength(128)
                                    ->helperText('Optional editorial kicker for topic landing pages.'),
                                Forms\Components\Textarea::make('hero_quote')
                                    ->rows(4)
                                    ->helperText('Optional callout or orienting quote.'),
                                Forms\Components\TextInput::make('cover_image_url')
                                    ->maxLength(2048)
                                    ->columnSpanFull()
                                    ->helperText('Optional cover image URL for future frontend or card usage.'),
                            ])
                            ->columns(2),
                        Forms\Components\Section::make('Narrative sections')
                            ->description('Edit only the fixed narrative sections that define the topic story. Unknown section keys are ignored by the workspace.')
                            ->extraAttributes(['class' => 'ops-topic-workspace-section ops-topic-workspace-section--main'])
                            ->schema([
                                Forms\Components\Tabs::make('Topic sections')
                                    ->contained(false)
                                    ->extraAttributes(['class' => 'ops-topic-workspace-tabs'])
                                    ->tabs(self::sectionTabs())
                                    ->columnSpanFull(),
                            ]),
                        Forms\Components\Section::make('Entry groups')
                            ->description('Manage group-aware aggregations instead of editing raw database rows. Each group keeps allowed entry types constrained.')
                            ->extraAttributes(['class' => 'ops-topic-workspace-section ops-topic-workspace-section--main'])
                            ->schema([
                                Forms\Components\Tabs::make('Topic entry groups')
                                    ->contained(false)
                                    ->extraAttributes(['class' => 'ops-topic-workspace-tabs'])
                                    ->tabs(self::entryGroupTabs())
                                    ->columnSpanFull(),
                            ]),
                    ])
                        ->columnSpan([
                            'xl' => 8,
                        ])
                        ->extraAttributes(['class' => 'ops-topic-workspace-main-column']),
                    Forms\Components\Group::make([
                        Forms\Components\Section::make('Identity')
                            ->description('Topics CMS v1 manages global hub content only. Org scope stays fixed and not tenant-specific.')
                            ->extraAttributes(['class' => 'ops-topic-workspace-section ops-topic-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('identity_topic_code')
                                    ->label('Topic code')
                                    ->content(fn (Forms\Get $get, ?TopicProfile $record): string => TopicWorkspace::normalizeTopicCode(
                                        (string) ($get('topic_code') ?? $record?->topic_code ?? '')
                                    ) ?: 'Not set yet'),
                                Forms\Components\Placeholder::make('identity_slug')
                                    ->label('Slug')
                                    ->content(fn (Forms\Get $get, ?TopicProfile $record): string => TopicWorkspace::normalizeSlug(
                                        (string) ($get('slug') ?? $record?->slug ?? ''),
                                        (string) ($get('topic_code') ?? $record?->topic_code ?? ''),
                                    ) ?: 'Not set yet'),
                                Forms\Components\Placeholder::make('identity_locale')
                                    ->label('Locale')
                                    ->content(fn (Forms\Get $get, ?TopicProfile $record): string => TopicWorkspace::normalizeLocale(
                                        (string) ($get('locale') ?? $record?->locale ?? 'en'),
                                    )),
                                Forms\Components\Placeholder::make('identity_org')
                                    ->label('Content scope')
                                    ->content('Global topic content (org_id=0)'),
                            ])
                            ->columns(2),
                        Forms\Components\Section::make('Publish')
                            ->description('Keep visibility, indexing, and ordering cues together on the metadata rail.')
                            ->extraAttributes(['class' => 'ops-topic-workspace-section ops-topic-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('workspace_state')
                                    ->label('Editorial cues')
                                    ->content(fn (Forms\Get $get, ?TopicProfile $record) => TopicWorkspace::renderEditorialCues($get, $record))
                                    ->columnSpanFull(),
                                Forms\Components\Select::make('status')
                                    ->required()
                                    ->native(false)
                                    ->options(self::statusOptions())
                                    ->default(TopicProfile::STATUS_DRAFT)
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->helperText('Managed by the release workflow. Use editorial review and release actions to change publish state.'),
                                Forms\Components\Toggle::make('is_public')
                                    ->label('Public visibility')
                                    ->default(true)
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->helperText('Managed by the release workflow. Public visibility changes only through release actions.'),
                                Forms\Components\Toggle::make('is_indexable')
                                    ->label('Search indexable')
                                    ->default(true)
                                    ->helperText('Used for SEO payload fallbacks and future sitemap eligibility.'),
                                Forms\Components\DateTimePicker::make('published_at')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->helperText('Managed by the release workflow. Publish timestamps are written only during release.'),
                                Forms\Components\DateTimePicker::make('scheduled_at')
                                    ->helperText('Optional scheduling hint for editorial planning.'),
                                Forms\Components\TextInput::make('sort_order')
                                    ->numeric()
                                    ->default(0)
                                    ->helperText('List ordering for future topic hubs and ops tables.'),
                            ]),
                        Forms\Components\Section::make('SEO')
                            ->description('Keep search metadata, social overrides, and advanced JSON-LD input in one reviewable rail.')
                            ->extraAttributes(['class' => 'ops-topic-workspace-section ops-topic-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('seo_snapshot')
                                    ->label('SEO snapshot')
                                    ->content(fn (Forms\Get $get, ?TopicProfile $record) => TopicWorkspace::renderSeoSnapshot($get, $record))
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('workspace_seo.seo_title')
                                    ->label('SEO title')
                                    ->maxLength(255)
                                    ->helperText('Search headline fallback is the topic title if this stays empty.'),
                                Forms\Components\Textarea::make('workspace_seo.seo_description')
                                    ->label('SEO description')
                                    ->rows(3)
                                    ->helperText('Search description fallback is excerpt, then subtitle.'),
                                Forms\Components\TextInput::make('workspace_seo.canonical_url')
                                    ->label('Canonical override')
                                    ->maxLength(2048)
                                    ->helperText('Optional stored override. Final frontend canonical still resolves to locale-aware topic URLs.'),
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
                                Forms\Components\Textarea::make('workspace_seo.jsonld_overrides_json_text')
                                    ->label('JSON-LD overrides')
                                    ->rows(5)
                                    ->helperText('Optional limited overrides. Core schema fields like @type, author, publisher, canonical, and mainEntityOfPage are policy-managed and cannot be overridden.')
                                    ->rules(['nullable', 'json'])
                                    ->extraFieldWrapperAttributes(['class' => 'ops-topic-workspace-field ops-topic-workspace-field--payload']),
                            ]),
                        ContentGovernanceForm::section(
                            defaultPageType: 'hub',
                            railClass: 'ops-topic-workspace-section ops-topic-workspace-section--rail',
                        ),
                        Forms\Components\Section::make('Record cues')
                            ->description('Read-only context so editors can see the planned URL, revision trail, entry count, and SEO readiness before frontend cutover.')
                            ->extraAttributes(['class' => 'ops-topic-workspace-section ops-topic-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('planned_public_url')
                                    ->label('Planned public URL')
                                    ->content(fn (Forms\Get $get, ?TopicProfile $record): string => TopicWorkspace::plannedPublicUrl(
                                        (string) ($get('slug') ?? $record?->slug ?? ''),
                                        (string) ($get('locale') ?? $record?->locale ?? 'en'),
                                    ) ?? 'Appears once slug and locale are set.'),
                                Forms\Components\Placeholder::make('created_at_summary')
                                    ->label('Created')
                                    ->content(fn (?TopicProfile $record): string => TopicWorkspace::formatTimestamp(
                                        $record?->created_at,
                                        'Workspace draft not saved yet',
                                    )),
                                Forms\Components\Placeholder::make('updated_at_summary')
                                    ->label('Last updated')
                                    ->content(fn (?TopicProfile $record): string => TopicWorkspace::formatTimestamp(
                                        $record?->updated_at,
                                        'Workspace draft not saved yet',
                                    )),
                                Forms\Components\Placeholder::make('revision_count_summary')
                                    ->label('Revision count')
                                    ->content(fn (?TopicProfile $record): string => $record instanceof TopicProfile
                                        ? (string) $record->revisions()->count()
                                        : 'Revision #1 will be written when this topic is created'),
                                Forms\Components\Placeholder::make('entry_count_summary')
                                    ->label('Entry summary')
                                    ->content(fn (Forms\Get $get, ?TopicProfile $record): string => TopicWorkspace::entrySummary(
                                        is_array($get('workspace_entries') ?? null) ? $get('workspace_entries') : [],
                                        $record,
                                    )),
                            ])
                            ->columns(2),
                    ])
                        ->columnSpan([
                            'xl' => 4,
                        ])
                        ->extraAttributes(['class' => 'ops-topic-workspace-rail-column']),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Topic')
                    ->html()
                    ->searchable(['topic_code', 'title', 'slug'])
                    ->sortable()
                    ->formatStateUsing(fn (TopicProfile $record): string => (string) view('filament.ops.topics.partials.table-title', [
                        'meta' => TopicWorkspace::titleMeta($record),
                        'title' => $record->title,
                    ])->render()),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => Str::of($state)->headline()->value())
                    ->description(fn (TopicProfile $record): string => TopicWorkspace::visibilityMeta($record))
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
                Tables\Columns\TextColumn::make('entries_count')
                    ->label('Entries')
                    ->numeric()
                    ->sortable()
                    ->alignCenter(),
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
                Tables\Filters\SelectFilter::make('topic_code')
                    ->label('Topic code')
                    ->options(self::topicCodeFilterOptions()),
                Tables\Filters\SelectFilter::make('locale')
                    ->options(self::localeOptions()),
                Tables\Filters\SelectFilter::make('status')
                    ->options(self::statusOptions()),
                TernaryFilter::make('is_public')
                    ->label('Public'),
                TernaryFilter::make('is_indexable')
                    ->label('Indexable'),
            ])
            ->searchPlaceholder('Search topic code, title, or slug')
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (TopicProfile $record): string => static::getUrl('edit', ['record' => $record]))
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->color('gray'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTopicProfiles::route('/'),
            'create' => Pages\CreateTopicProfile::route('/create'),
            'edit' => Pages\EditTopicProfile::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->withCount('entries');
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return [
            TopicProfile::STATUS_DRAFT => TopicProfile::STATUS_DRAFT,
            TopicProfile::STATUS_PUBLISHED => TopicProfile::STATUS_PUBLISHED,
        ];
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
                || $user->hasPermission(PermissionNames::ADMIN_CONTENT_PUBLISH)
                || $user->hasPermission(PermissionNames::ADMIN_CONTENT_RELEASE)
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

    public static function releaseRecord(TopicProfile $record, string $source = 'resource_table'): void
    {
        if (! ContentAccess::canRelease()) {
            throw new AuthorizationException('You do not have permission to release topic profiles.');
        }

        if ($record->status === TopicProfile::STATUS_PUBLISHED) {
            return;
        }

        if ((EditorialReviewAudit::latestState('topic', $record)['state'] ?? null) !== EditorialReviewAudit::STATE_APPROVED) {
            throw new AuthorizationException('This topic profile must be approved in editorial review before it can be published.');
        }

        ContentPublishGateService::assertReadyForRelease('topic', $record);

        $record->forceFill([
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => $record->published_at ?? now(),
        ])->save();

        ContentReleaseAudit::log('topic', $record->fresh(), $source);

        Notification::make()
            ->title('Topic profile released')
            ->body('The topic profile is now marked as published.')
            ->success()
            ->send();
    }

    /**
     * @return array<string, string>
     */
    private static function localeOptions(): array
    {
        return collect(TopicProfile::SUPPORTED_LOCALES)
            ->mapWithKeys(static fn (string $locale): array => [$locale => $locale])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function topicCodeFilterOptions(): array
    {
        return TopicProfile::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->orderBy('topic_code')
            ->pluck('topic_code', 'topic_code')
            ->all();
    }

    /**
     * @return array<int, Forms\Components\Tabs\Tab>
     */
    private static function sectionTabs(): array
    {
        $variantOptions = TopicWorkspace::renderVariantOptions();

        return collect(TopicWorkspace::sectionDefinitions())
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
                            ->extraFieldWrapperAttributes(['class' => 'ops-topic-workspace-field ops-topic-workspace-field--body']),
                        Forms\Components\Textarea::make("workspace_sections.{$sectionKey}.payload_json_text")
                            ->label('Structured payload JSON')
                            ->rows(6)
                            ->columnSpanFull()
                            ->helperText('Optional structured payload for cards, FAQ, links, or callout render variants.')
                            ->rules(['nullable', 'json'])
                            ->extraFieldWrapperAttributes(['class' => 'ops-topic-workspace-field ops-topic-workspace-field--payload']),
                    ])
                    ->columns(2);
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, Forms\Components\Tabs\Tab>
     */
    private static function entryGroupTabs(): array
    {
        return collect(TopicWorkspace::entryGroupDefinitions())
            ->map(function (array $definition, string $groupKey): Forms\Components\Tabs\Tab {
                return Forms\Components\Tabs\Tab::make($definition['label'])
                    ->schema([
                        Forms\Components\Repeater::make("workspace_entries.{$groupKey}")
                            ->label($definition['label'])
                            ->default([])
                            ->addActionLabel($definition['add_label'])
                            ->helperText($definition['description'])
                            ->itemLabel(fn (array $state): ?string => TopicWorkspace::entryItemLabel($state))
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->cloneable()
                            ->columns(2)
                            ->schema([
                                Forms\Components\Select::make('entry_type')
                                    ->required()
                                    ->native(false)
                                    ->options(TopicWorkspace::entryTypeOptionsForGroup($groupKey))
                                    ->helperText('Only entry types approved for this group appear here.'),
                                Forms\Components\TextInput::make('target_key')
                                    ->label('Target key')
                                    ->helperText('Use article slug, personality type/slug, or scale code depending on the entry type.')
                                    ->required(fn (Forms\Get $get): bool => $get('entry_type') !== 'custom_link')
                                    ->visible(fn (Forms\Get $get): bool => $get('entry_type') !== 'custom_link'),
                                Forms\Components\Select::make('target_locale')
                                    ->label('Target locale')
                                    ->native(false)
                                    ->options(self::localeOptions())
                                    ->helperText('Defaults to the current topic locale if left blank.')
                                    ->visible(fn (Forms\Get $get): bool => $get('entry_type') !== 'custom_link'),
                                Forms\Components\TextInput::make('target_url_override')
                                    ->label('Custom relative URL')
                                    ->helperText('Required for custom links. Use site-relative paths like `/en/articles/example`.')
                                    ->required(fn (Forms\Get $get): bool => $get('entry_type') === 'custom_link')
                                    ->visible(fn (Forms\Get $get): bool => $get('entry_type') === 'custom_link'),
                                Forms\Components\TextInput::make('title_override')
                                    ->label('Title override')
                                    ->maxLength(255)
                                    ->helperText('Optional for resolved entries. Required for custom links.')
                                    ->required(fn (Forms\Get $get): bool => $get('entry_type') === 'custom_link'),
                                Forms\Components\Textarea::make('excerpt_override')
                                    ->label('Excerpt override')
                                    ->rows(3),
                                Forms\Components\TextInput::make('badge_label')
                                    ->label('Badge label')
                                    ->maxLength(64),
                                Forms\Components\TextInput::make('cta_label')
                                    ->label('CTA label')
                                    ->maxLength(64),
                                Forms\Components\Textarea::make('payload_json_text')
                                    ->label('Payload JSON')
                                    ->rows(5)
                                    ->columnSpanFull()
                                    ->rules(['nullable', 'json'])
                                    ->helperText('Optional structured payload for future UI enrichment.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-topic-workspace-field ops-topic-workspace-field--payload']),
                                Forms\Components\Toggle::make('is_featured')
                                    ->label('Featured')
                                    ->default((bool) $definition['force_featured'])
                                    ->hidden((bool) $definition['force_featured']),
                                Forms\Components\Toggle::make('is_enabled')
                                    ->label('Enabled')
                                    ->default(true),
                                Forms\Components\TextInput::make('sort_order')
                                    ->label('Sort order')
                                    ->numeric()
                                    ->default(0),
                            ]),
                    ]);
            })
            ->values()
            ->all();
    }
}
