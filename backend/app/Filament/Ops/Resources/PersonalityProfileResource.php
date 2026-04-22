<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\PersonalityProfileResource\Pages;
use App\Filament\Ops\Resources\PersonalityProfileResource\Support\PersonalityWorkspace;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\PersonalityProfile;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class PersonalityProfileResource extends Resource
{
    protected static ?string $model = PersonalityProfile::class;

    protected static ?string $slug = 'personality';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Personality';

    protected static ?string $modelLabel = 'Personality Profile';

    protected static ?string $pluralModelLabel = 'Personality Profiles';

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
        return __('ops.nav.personality');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('org_id')
                ->default(0),
            Forms\Components\Hidden::make('scale_code')
                ->default(PersonalityProfile::SCALE_CODE_MBTI),
            Forms\Components\Grid::make([
                'default' => 1,
                'xl' => 12,
            ])
                ->extraAttributes(['class' => 'ops-personality-workspace-layout'])
                ->schema([
                    Forms\Components\Group::make([
                        Forms\Components\Section::make('Basic')
                            ->description('Shape the core MBTI profile identity before moving into structured sections and metadata.')
                            ->extraAttributes(['class' => 'ops-personality-workspace-section ops-personality-workspace-section--main'])
                            ->schema([
                                Forms\Components\Select::make('type_code')
                                    ->label('MBTI type')
                                    ->required()
                                    ->native(false)
                                    ->searchable()
                                    ->options(self::typeOptions())
                                    ->helperText('V1 supports the 16 base MBTI types only.')
                                    ->live()
                                    ->afterStateUpdated(function (?string $state, Forms\Set $set, Forms\Get $get): void {
                                        $set('canonical_type_code', PersonalityWorkspace::normalizeCanonicalTypeCode(
                                            (string) ($get('canonical_type_code') ?? ''),
                                            $state,
                                        ));

                                        if (trim((string) $get('slug')) !== '') {
                                            return;
                                        }

                                        $set('slug', PersonalityWorkspace::normalizeSlug(null, $state));
                                    }),
                                Forms\Components\TextInput::make('canonical_type_code')
                                    ->label('Canonical type')
                                    ->readOnly()
                                    ->dehydrated(false)
                                    ->helperText('Auto-synced 16-type canonical identity for the base profile layer.'),
                                Forms\Components\TextInput::make('slug')
                                    ->required()
                                    ->maxLength(64)
                                    ->placeholder('intj')
                                    ->helperText('Stored in lowercase and used in the planned frontend personality URL.')
                                    ->dehydrateStateUsing(fn (?string $state, Forms\Get $get): string => PersonalityWorkspace::normalizeSlug(
                                        $state,
                                        (string) $get('type_code'),
                                    ))
                                    ->rules(['regex:/^[a-z0-9-]+$/']),
                                Forms\Components\Select::make('locale')
                                    ->required()
                                    ->native(false)
                                    ->options(self::localeOptions())
                                    ->default('en')
                                    ->helperText('Stored as backend locale codes. Frontend URL mapping still resolves to locale-aware paths.'),
                                Forms\Components\Select::make('schema_version')
                                    ->required()
                                    ->native(false)
                                    ->options([
                                        PersonalityProfile::SCHEMA_VERSION_V1 => PersonalityProfile::SCHEMA_VERSION_V1,
                                        PersonalityProfile::SCHEMA_VERSION_V2 => PersonalityProfile::SCHEMA_VERSION_V2,
                                    ])
                                    ->default(PersonalityProfile::SCHEMA_VERSION_V2)
                                    ->live()
                                    ->helperText('Controls whether the workspace manages the legacy v1 catalog or the canonical MBTI v2 catalog.'),
                                Forms\Components\TextInput::make('title')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpanFull()
                                    ->helperText('Primary profile heading shown in the workspace, public detail page, and SEO fallback.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-personality-workspace-field ops-personality-workspace-field--title'])
                                    ->extraInputAttributes(['class' => 'ops-personality-workspace-input ops-personality-workspace-input--title']),
                                Forms\Components\TextInput::make('type_name')
                                    ->label('Type name')
                                    ->maxLength(120)
                                    ->helperText('Canonical localized type name used by the 32-type content authority.'),
                                Forms\Components\TextInput::make('nickname')
                                    ->label('Nickname')
                                    ->maxLength(160)
                                    ->helperText('Optional editorial nickname for the canonical base profile.'),
                                Forms\Components\TextInput::make('subtitle')
                                    ->maxLength(255)
                                    ->helperText('Short supporting line for the hero and quick list scanning.'),
                                Forms\Components\TextInput::make('rarity_text')
                                    ->label('Rarity text')
                                    ->maxLength(64)
                                    ->helperText('Human-readable rarity label when canonical content provides one.'),
                                Forms\Components\Textarea::make('excerpt')
                                    ->rows(4)
                                    ->columnSpanFull()
                                    ->helperText('Used as the summary fallback across list, SEO, and public profile contexts.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-personality-workspace-field ops-personality-workspace-field--summary']),
                                Forms\Components\TagsInput::make('keywords_json')
                                    ->label('Keywords')
                                    ->columnSpanFull()
                                    ->helperText('Canonical keyword tags stored as structured JSON on the base profile row.'),
                                Forms\Components\Textarea::make('hero_summary_md')
                                    ->label('Hero summary')
                                    ->rows(4)
                                    ->columnSpanFull()
                                    ->helperText('Canonical hero summary stored separately from excerpt and hero quote.')
                                    ->extraFieldWrapperAttributes(['class' => 'ops-personality-workspace-field ops-personality-workspace-field--summary']),
                            ])
                            ->columns(3),
                        Forms\Components\Section::make('Hero')
                            ->description('Set the lead framing used to introduce the personality profile before readers enter the structured sections.')
                            ->extraAttributes(['class' => 'ops-personality-workspace-section ops-personality-workspace-section--main'])
                            ->schema([
                                Forms\Components\TextInput::make('hero_kicker')
                                    ->maxLength(128)
                                    ->helperText('Short kicker that frames the profile in a more editorial tone.'),
                                Forms\Components\Textarea::make('hero_quote')
                                    ->rows(4)
                                    ->helperText('Optional quote or rallying line for the hero block.'),
                                Forms\Components\TextInput::make('hero_image_url')
                                    ->maxLength(2048)
                                    ->columnSpanFull()
                                    ->helperText('Optional lead image URL for future frontend card or hero coverage.'),
                            ])
                            ->columns(2),
                        Forms\Components\Section::make('Structured sections')
                            ->description('Edit fixed profile sections instead of inventing new content blocks. The section keys stay controlled by the workspace.')
                            ->extraAttributes(['class' => 'ops-personality-workspace-section ops-personality-workspace-section--main'])
                            ->schema([
                                Forms\Components\Tabs::make('Personality sections')
                                    ->contained(false)
                                    ->extraAttributes(['class' => 'ops-personality-workspace-tabs'])
                                    ->tabs(self::sectionTabs())
                                    ->columnSpanFull(),
                            ]),
                    ])
                        ->columnSpan([
                            'xl' => 8,
                        ])
                        ->extraAttributes(['class' => 'ops-personality-workspace-main-column']),
                    Forms\Components\Group::make([
                        Forms\Components\Section::make('Identity')
                            ->description('Personality CMS v1 is global MBTI content. Org scope and scale are fixed by the workspace.')
                            ->extraAttributes(['class' => 'ops-personality-workspace-section ops-personality-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('identity_scale')
                                    ->label('Scale')
                                    ->content(PersonalityProfile::SCALE_CODE_MBTI),
                                Forms\Components\Placeholder::make('identity_type')
                                    ->label('Type code')
                                    ->content(fn (Forms\Get $get, ?PersonalityProfile $record): string => PersonalityWorkspace::normalizeTypeCode(
                                        (string) ($get('type_code') ?? $record?->type_code ?? '')
                                    ) ?: 'Not set yet'),
                                Forms\Components\Placeholder::make('identity_canonical')
                                    ->label('Canonical type')
                                    ->content(fn (Forms\Get $get, ?PersonalityProfile $record): string => PersonalityWorkspace::normalizeCanonicalTypeCode(
                                        (string) ($get('canonical_type_code') ?? $record?->canonical_type_code ?? ''),
                                        (string) ($get('type_code') ?? $record?->type_code ?? ''),
                                    ) ?: 'Not set yet'),
                                Forms\Components\Placeholder::make('identity_slug')
                                    ->label('Slug')
                                    ->content(fn (Forms\Get $get, ?PersonalityProfile $record): string => PersonalityWorkspace::normalizeSlug(
                                        (string) ($get('slug') ?? $record?->slug ?? ''),
                                        (string) ($get('type_code') ?? $record?->type_code ?? ''),
                                    ) ?: 'Not set yet'),
                                Forms\Components\Placeholder::make('identity_locale')
                                    ->label('Locale')
                                    ->content(fn (Forms\Get $get, ?PersonalityProfile $record): string => PersonalityWorkspace::normalizeLocale(
                                        (string) ($get('locale') ?? $record?->locale ?? 'en'),
                                    )),
                                Forms\Components\Placeholder::make('identity_org')
                                    ->label('Content scope')
                                    ->content('Global MBTI content (org_id=0)'),
                                Forms\Components\Placeholder::make('identity_schema_version')
                                    ->label('Schema version')
                                    ->content(fn (Forms\Get $get, ?PersonalityProfile $record): string => PersonalityWorkspace::normalizeSchemaVersion(
                                        (string) ($get('schema_version') ?? $record?->schema_version ?? PersonalityProfile::SCHEMA_VERSION_V1),
                                    )),
                            ])
                            ->columns(2),
                        Forms\Components\Section::make('Publish')
                            ->description('Keep editorial visibility, indexing, and scheduling cues together in the side rail.')
                            ->extraAttributes(['class' => 'ops-personality-workspace-section ops-personality-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('workspace_state')
                                    ->label(__('ops.resources.common.fields.editorial_cues'))
                                    ->content(fn (Forms\Get $get, ?PersonalityProfile $record) => PersonalityWorkspace::renderEditorialCues($get, $record))
                                    ->columnSpanFull(),
                                Forms\Components\Select::make('status')
                                    ->required()
                                    ->native(false)
                                    ->options(self::statusOptions())
                                    ->default('draft')
                                    ->helperText('Published profiles are eligible for the public read API once visibility and locale are aligned.'),
                                Forms\Components\Toggle::make('is_public')
                                    ->label(__('ops.resources.common.fields.public_visibility'))
                                    ->default(true)
                                    ->helperText('Controls whether the public personality API can serve this profile.'),
                                Forms\Components\Toggle::make('is_indexable')
                                    ->label(__('ops.resources.common.fields.search_indexable'))
                                    ->default(true)
                                    ->helperText('Used for SEO payload fallbacks and robots defaults.'),
                                Forms\Components\DateTimePicker::make('published_at')
                                    ->helperText('Timestamp shown in editorial cues and public profile responses.'),
                                Forms\Components\DateTimePicker::make('scheduled_at')
                                    ->helperText('Optional future release time for editorial planning.'),
                            ]),
                        Forms\Components\Section::make('SEO')
                            ->description('Keep title, summary, social metadata, and advanced JSON-LD overrides in one reviewable rail.')
                            ->extraAttributes(['class' => 'ops-personality-workspace-section ops-personality-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('seo_snapshot')
                                    ->label(__('ops.resources.common.fields.seo_snapshot'))
                                    ->content(fn (Forms\Get $get, ?PersonalityProfile $record) => PersonalityWorkspace::renderSeoSnapshot($get, $record))
                                    ->columnSpanFull(),
                                Forms\Components\TextInput::make('workspace_seo.seo_title')
                                    ->label('SEO title')
                                    ->maxLength(255)
                                    ->helperText('Search headline fallback is the profile title if this stays empty.'),
                                Forms\Components\Textarea::make('workspace_seo.seo_description')
                                    ->label('SEO description')
                                    ->rows(3)
                                    ->helperText('Search description fallback is excerpt, then subtitle.'),
                                Forms\Components\TextInput::make('workspace_seo.canonical_url')
                                    ->label('Canonical override')
                                    ->maxLength(2048)
                                    ->helperText('Optional stored override. Final frontend canonical still follows locale-aware personality URLs.'),
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
                                    ->helperText('Optional advanced overrides merged into the public Personality JSON-LD payload.')
                                    ->rules(['nullable', 'json'])
                                    ->extraFieldWrapperAttributes(['class' => 'ops-personality-workspace-field ops-personality-workspace-field--payload']),
                            ]),
                        Forms\Components\Section::make('Record cues')
                            ->description('Read-only context so editors can see what is planned, when the record changed, and how many revisions already exist.')
                            ->extraAttributes(['class' => 'ops-personality-workspace-section ops-personality-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('planned_public_url')
                                    ->label('Planned public URL')
                                    ->content(fn (Forms\Get $get, ?PersonalityProfile $record): string => PersonalityWorkspace::plannedPublicUrl(
                                        (string) ($get('slug') ?? $record?->slug ?? ''),
                                        (string) ($get('locale') ?? $record?->locale ?? 'en'),
                                    ) ?? 'Appears once slug and locale are set.'),
                                Forms\Components\Placeholder::make('created_at_summary')
                                    ->label('Created')
                                    ->content(fn (?PersonalityProfile $record): string => PersonalityWorkspace::formatTimestamp(
                                        $record?->created_at,
                                        'Workspace draft not saved yet',
                                    )),
                                Forms\Components\Placeholder::make('updated_at_summary')
                                    ->label('Last updated')
                                    ->content(fn (?PersonalityProfile $record): string => PersonalityWorkspace::formatTimestamp(
                                        $record?->updated_at,
                                        'Workspace draft not saved yet',
                                    )),
                                Forms\Components\Placeholder::make('revision_count_summary')
                                    ->label('Revision count')
                                    ->content(fn (?PersonalityProfile $record): string => $record instanceof PersonalityProfile
                                        ? (string) $record->revisions()->count()
                                        : 'Revision #1 will be written when this profile is created'),
                            ])
                            ->columns(2),
                    ])
                        ->columnSpan([
                            'xl' => 4,
                        ])
                        ->extraAttributes(['class' => 'ops-personality-workspace-rail-column']),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Profile')
                    ->html()
                    ->searchable(['title', 'slug', 'type_code'])
                    ->sortable()
                    ->formatStateUsing(fn (PersonalityProfile $record): string => (string) view('filament.ops.personality.partials.table-title', [
                        'meta' => PersonalityWorkspace::titleMeta($record),
                        'title' => $record->title,
                    ])->render()),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->copyable()
                    ->formatStateUsing(fn (string $state): string => '/'.trim($state, '/'))
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('schema_version')
                    ->badge()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->formatStateUsing(fn (string $state): string => Str::of($state)->headline()->value())
                    ->description(fn (PersonalityProfile $record): string => PersonalityWorkspace::visibilityMeta($record))
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
                Tables\Filters\SelectFilter::make('type_code')
                    ->label('Type')
                    ->options(self::typeOptions()),
                Tables\Filters\SelectFilter::make('locale')
                    ->options(self::localeOptions()),
                Tables\Filters\SelectFilter::make('status')
                    ->options(self::statusOptions()),
                Tables\Filters\SelectFilter::make('schema_version')
                    ->options([
                        PersonalityProfile::SCHEMA_VERSION_V1 => PersonalityProfile::SCHEMA_VERSION_V1,
                        PersonalityProfile::SCHEMA_VERSION_V2 => PersonalityProfile::SCHEMA_VERSION_V2,
                    ]),
                TernaryFilter::make('is_public')
                    ->label('Public'),
                TernaryFilter::make('is_indexable')
                    ->label('Indexable'),
            ])
            ->searchPlaceholder('Search type, title, or slug')
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (PersonalityProfile $record): string => static::getUrl('edit', ['record' => $record]))
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
            'index' => Pages\ListPersonalityProfiles::route('/'),
            'create' => Pages\CreatePersonalityProfile::route('/create'),
            'edit' => Pages\EditPersonalityProfile::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI);
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return [
            'draft' => 'draft',
            'published' => 'published',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function localeOptions(): array
    {
        return collect(PersonalityProfile::SUPPORTED_LOCALES)
            ->mapWithKeys(static fn (string $locale): array => [$locale => $locale])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        return collect(PersonalityProfile::TYPE_CODES)
            ->mapWithKeys(static fn (string $typeCode): array => [$typeCode => $typeCode])
            ->all();
    }

    /**
     * @return array<int, Forms\Components\Tabs\Tab>
     */
    private static function sectionTabs(): array
    {
        return collect(PersonalityWorkspace::allSectionDefinitions())
            ->map(function (array $definition, string $sectionKey): Forms\Components\Tabs\Tab {
                return Forms\Components\Tabs\Tab::make($definition['label'])
                    ->visible(fn (Forms\Get $get): bool => PersonalityWorkspace::isManagedSectionKeyForSchemaVersion(
                        $sectionKey,
                        (string) ($get('schema_version') ?? PersonalityProfile::SCHEMA_VERSION_V1),
                    ))
                    ->schema([
                        Forms\Components\Toggle::make("workspace_sections.{$sectionKey}.is_enabled")
                            ->label('Enable this section')
                            ->helperText($definition['description'])
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make("workspace_sections.{$sectionKey}.title")
                            ->label('Section title')
                            ->required(fn (Forms\Get $get): bool => PersonalityWorkspace::isManagedSectionKeyForSchemaVersion(
                                $sectionKey,
                                (string) ($get('schema_version') ?? PersonalityProfile::SCHEMA_VERSION_V1),
                            ))
                            ->maxLength(255),
                        Forms\Components\Select::make("workspace_sections.{$sectionKey}.render_variant")
                            ->label('Render variant')
                            ->native(false)
                            ->options(fn (Forms\Get $get): array => PersonalityWorkspace::renderVariantOptions(
                                (string) ($get('schema_version') ?? PersonalityProfile::SCHEMA_VERSION_V1),
                                $sectionKey,
                            ))
                            ->required(fn (Forms\Get $get): bool => PersonalityWorkspace::isManagedSectionKeyForSchemaVersion(
                                $sectionKey,
                                (string) ($get('schema_version') ?? PersonalityProfile::SCHEMA_VERSION_V1),
                            )),
                        Forms\Components\Textarea::make("workspace_sections.{$sectionKey}.body_md")
                            ->label('Narrative body')
                            ->rows(8)
                            ->columnSpanFull()
                            ->helperText('Use markdown-friendly narrative copy when this section needs long-form explanation.')
                            ->extraFieldWrapperAttributes(['class' => 'ops-personality-workspace-field ops-personality-workspace-field--body']),
                        Forms\Components\Textarea::make("workspace_sections.{$sectionKey}.payload_json_text")
                            ->label('Structured payload JSON')
                            ->rows(6)
                            ->columnSpanFull()
                            ->helperText('Optional structured payload for cards, FAQ, links, or other fixed render variants.')
                            ->rules(['nullable', 'json'])
                            ->extraFieldWrapperAttributes(['class' => 'ops-personality-workspace-field ops-personality-workspace-field--payload']),
                    ])
                    ->columns(2);
            })
            ->values()
            ->all();
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
