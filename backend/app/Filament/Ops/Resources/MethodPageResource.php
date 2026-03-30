<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\MethodPageResource\Pages;
use App\Filament\Ops\Resources\MethodPageResource\Support\MethodPageWorkspace;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\ContentGovernanceForm;
use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Filament\Ops\Support\EditorialReviewAudit;
use App\Models\MethodPage;
use App\Services\Cms\ContentPublishGateService;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class MethodPageResource extends Resource
{
    protected static ?string $model = MethodPage::class;

    protected static ?string $slug = 'methods';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Methods';

    protected static ?string $modelLabel = 'Method Page';

    protected static ?string $pluralModelLabel = 'Method Pages';

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
        return 'Methods';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('org_id')->default(0),
            Forms\Components\Hidden::make('schema_version')->default('v1'),
            Forms\Components\Grid::make(['default' => 1, 'xl' => 12])->schema([
                Forms\Components\Group::make([
                    Forms\Components\Section::make('Basic')
                        ->schema([
                            Forms\Components\TextInput::make('method_code')
                                ->required()
                                ->maxLength(96)
                                ->placeholder('fermat-facet-matrix')
                                ->dehydrateStateUsing(fn (?string $state): string => MethodPageWorkspace::normalizeMethodCode($state))
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (?string $state, Forms\Set $set, Forms\Get $get): void {
                                    if (trim((string) $get('slug')) !== '') {
                                        return;
                                    }

                                    $set('slug', MethodPageWorkspace::normalizeSlug(null, $state));
                                })
                                ->rules(['regex:/^[a-z0-9-]+$/']),
                            Forms\Components\TextInput::make('slug')
                                ->required()
                                ->maxLength(128)
                                ->placeholder('fermat-facet-matrix')
                                ->dehydrateStateUsing(fn (?string $state, Forms\Get $get): string => MethodPageWorkspace::normalizeSlug(
                                    $state,
                                    (string) $get('method_code'),
                                ))
                                ->rules(['regex:/^[a-z0-9-]+$/']),
                            Forms\Components\Select::make('locale')
                                ->required()
                                ->native(false)
                                ->options([
                                    'en' => 'en',
                                    'zh-CN' => 'zh-CN',
                                ])
                                ->default('en')
                                ->dehydrateStateUsing(fn (?string $state): string => MethodPageWorkspace::normalizeLocale($state)),
                            Forms\Components\TextInput::make('title')
                                ->required()
                                ->maxLength(255)
                                ->columnSpanFull(),
                            Forms\Components\TextInput::make('subtitle')
                                ->maxLength(255)
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('excerpt')
                                ->rows(4)
                                ->columnSpanFull(),
                            Forms\Components\TextInput::make('hero_kicker')
                                ->maxLength(128),
                            Forms\Components\TextInput::make('cover_image_url')
                                ->url()
                                ->columnSpanFull(),
                        ])
                        ->columns(2),
                    Forms\Components\Section::make('Narrative')
                        ->schema([
                            Forms\Components\MarkdownEditor::make('body_md')
                                ->label('Method body')
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('definition_summary_md')
                                ->label('Definition summary')
                                ->rows(4)
                                ->columnSpanFull(),
                            Forms\Components\Textarea::make('boundary_notes_md')
                                ->label('Boundary notes')
                                ->rows(4)
                                ->columnSpanFull(),
                        ]),
                ])->columnSpan(['xl' => 8]),
                Forms\Components\Group::make([
                    Forms\Components\Section::make('Publish')
                        ->schema([
                            Forms\Components\Select::make('status')
                                ->options([
                                    MethodPage::STATUS_DRAFT => MethodPage::STATUS_DRAFT,
                                    MethodPage::STATUS_PUBLISHED => MethodPage::STATUS_PUBLISHED,
                                ])
                                ->required()
                                ->disabled()
                                ->dehydrated(false),
                            Forms\Components\Toggle::make('is_public')
                                ->disabled()
                                ->dehydrated(false),
                            Forms\Components\Toggle::make('is_indexable')
                                ->default(true),
                            Forms\Components\DateTimePicker::make('published_at')
                                ->disabled()
                                ->dehydrated(false),
                            Forms\Components\DateTimePicker::make('scheduled_at'),
                            Forms\Components\TextInput::make('sort_order')->numeric(),
                        ]),
                    Forms\Components\Section::make('SEO')
                        ->schema([
                            Forms\Components\TextInput::make('workspace_seo.seo_title')->maxLength(255),
                            Forms\Components\Textarea::make('workspace_seo.seo_description')->rows(3),
                            Forms\Components\TextInput::make('workspace_seo.canonical_url')->url()->columnSpanFull(),
                            Forms\Components\TextInput::make('workspace_seo.og_title')->maxLength(255),
                            Forms\Components\Textarea::make('workspace_seo.og_description')->rows(3),
                            Forms\Components\TextInput::make('workspace_seo.og_image_url')->url()->columnSpanFull(),
                            Forms\Components\TextInput::make('workspace_seo.twitter_title')->maxLength(255),
                            Forms\Components\Textarea::make('workspace_seo.twitter_description')->rows(3),
                            Forms\Components\TextInput::make('workspace_seo.twitter_image_url')->url()->columnSpanFull(),
                            Forms\Components\TextInput::make('workspace_seo.robots')->maxLength(64),
                            Forms\Components\Textarea::make('workspace_seo.jsonld_overrides_json_text')
                                ->rows(6)
                                ->rules(['nullable', 'json'])
                                ->columnSpanFull(),
                        ])
                        ->columns(1),
                    ContentGovernanceForm::make(),
                ])->columnSpan(['xl' => 4]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable()->sortable()->wrap(),
                Tables\Columns\TextColumn::make('method_code')->label('Code')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('locale')->badge(),
                Tables\Columns\IconColumn::make('is_indexable')->boolean()->label('Indexable'),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMethodPages::route('/'),
            'create' => Pages\CreateMethodPage::route('/create'),
            'edit' => Pages\EditMethodPage::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->with('seoMeta');
    }

    public static function releaseRecord(MethodPage $record, string $source = 'resource_table'): void
    {
        if (! ContentAccess::canRelease()) {
            throw new AuthorizationException('You do not have permission to release method pages.');
        }

        if ($record->status === MethodPage::STATUS_PUBLISHED) {
            return;
        }

        if ((EditorialReviewAudit::latestState('method', $record)['state'] ?? null) !== EditorialReviewAudit::STATE_APPROVED) {
            throw new AuthorizationException('This method page must be approved in editorial review before it can be published.');
        }

        ContentPublishGateService::assertReadyForRelease('method', $record);

        $record->forceFill([
            'status' => MethodPage::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => $record->published_at ?? now(),
        ])->save();

        ContentReleaseAudit::log('method', $record->fresh(), $source);

        Notification::make()
            ->title('Method page released')
            ->success()
            ->send();
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
}
