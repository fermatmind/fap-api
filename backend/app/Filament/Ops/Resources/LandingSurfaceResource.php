<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\LandingSurfaceResource\Pages;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\OpsEdit;
use App\Filament\Ops\Support\OpsTable;
use App\Models\LandingSurface;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LandingSurfaceResource extends Resource
{
    protected static ?string $model = LandingSurface::class;

    protected static ?string $slug = 'landing-surfaces';

    protected static ?string $recordTitleAttribute = 'surface_key';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Landing Surfaces';

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
        return __('ops.nav.landing_surfaces');
    }

    public static function getModelLabel(): string
    {
        return __('ops.nav.landing_surfaces');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ops.nav.landing_surfaces');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('org_id')
                ->default(0),
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
                                                Forms\Components\TextInput::make('surface_key')
                                                    ->label(__('ops.edit.fields.surface_key'))
                                                    ->required()
                                                    ->maxLength(128),
                                                Forms\Components\Select::make('locale')
                                                    ->label(__('ops.edit.fields.locale'))
                                                    ->required()
                                                    ->native(false)
                                                    ->options(OpsEdit::localeOptions())
                                                    ->default('zh-CN'),
                                                Forms\Components\TextInput::make('title')
                                                    ->label(__('ops.resources.articles.fields.title'))
                                                    ->maxLength(255),
                                                Forms\Components\Textarea::make('description')
                                                    ->label(__('ops.edit.fields.description'))
                                                    ->rows(3)
                                                    ->columnSpanFull(),
                                                Forms\Components\TextInput::make('schema_version')
                                                    ->label(__('ops.edit.fields.schema_version'))
                                                    ->default('v1')
                                                    ->maxLength(32),
                                            ])
                                            ->columns(2),
                                    ]),
                                Forms\Components\Tabs\Tab::make(__('ops.edit.tabs.surface_payload'))
                                    ->schema([
                                        Forms\Components\Section::make(__('ops.edit.sections.surface_payload'))
                                            ->description(__('ops.edit.descriptions.surface_payload'))
                                            ->schema([
                                                Forms\Components\Textarea::make('payload_json')
                                                    ->label(__('ops.edit.sections.surface_payload'))
                                                    ->rows(18)
                                                    ->formatStateUsing(fn (mixed $state): string => self::jsonForEdit($state))
                                                    ->dehydrateStateUsing(fn (mixed $state): array => self::jsonFromEdit($state))
                                                    ->columnSpanFull(),
                                            ]),
                                    ]),
                                Forms\Components\Tabs\Tab::make(__('ops.edit.tabs.page_blocks'))
                                    ->schema([
                                        Forms\Components\Section::make(__('ops.edit.sections.page_blocks'))
                                            ->description(__('ops.edit.descriptions.page_blocks'))
                                            ->schema([
                                                Forms\Components\Repeater::make('blocks')
                                                    ->relationship()
                                                    ->reorderableWithButtons()
                                                    ->collapsible()
                                                    ->itemLabel(fn (array $state): ?string => $state['block_key'] ?? null)
                                                    ->schema([
                                                        Forms\Components\TextInput::make('block_key')
                                                            ->required()
                                                            ->maxLength(128),
                                                        Forms\Components\TextInput::make('block_type')
                                                            ->required()
                                                            ->default('json')
                                                            ->maxLength(64),
                                                        Forms\Components\TextInput::make('title')
                                                            ->maxLength(255),
                                                        Forms\Components\TextInput::make('sort_order')
                                                            ->numeric()
                                                            ->default(0),
                                                        Forms\Components\Toggle::make('is_enabled')
                                                            ->default(true),
                                                        Forms\Components\Textarea::make('payload_json')
                                                            ->label(__('ops.edit.sections.surface_payload'))
                                                            ->rows(8)
                                                            ->formatStateUsing(fn (mixed $state): string => self::jsonForEdit($state))
                                                            ->dehydrateStateUsing(fn (mixed $state): array => self::jsonFromEdit($state))
                                                            ->columnSpanFull(),
                                                    ])
                                                    ->columns(2)
                                                    ->columnSpanFull(),
                                            ]),
                                    ]),
                            ]),
                    ])->columnSpan(['xl' => 8])->extraAttributes(['class' => 'ops-edit-workspace-main-column']),
                    Forms\Components\Group::make([
                        Forms\Components\Section::make(__('ops.edit.sections.status_visibility'))
                            ->description(__('ops.edit.descriptions.status_visibility'))
                            ->extraAttributes(['class' => 'ops-edit-workspace-section ops-edit-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('ops_status_visibility')->label(__('ops.edit.sections.status_visibility'))->content(fn (?LandingSurface $record) => OpsEdit::statusVisibility($record)),
                                Forms\Components\Select::make('status')
                                    ->label(__('ops.table.status'))
                                    ->required()
                                    ->native(false)
                                    ->options(OpsEdit::statusOptions([LandingSurface::STATUS_DRAFT, LandingSurface::STATUS_PUBLISHED]))
                                    ->default(LandingSurface::STATUS_PUBLISHED),
                                Forms\Components\Toggle::make('is_public')
                                    ->label(__('ops.table.public'))
                                    ->default(true),
                                Forms\Components\Toggle::make('is_indexable')
                                    ->default(true),
                                Forms\Components\DateTimePicker::make('published_at'),
                                Forms\Components\DateTimePicker::make('scheduled_at'),
                            ]),
                        Forms\Components\Section::make(__('ops.edit.sections.publish_readiness'))
                            ->description(__('ops.edit.descriptions.publish_readiness'))
                            ->extraAttributes(['class' => 'ops-edit-workspace-section ops-edit-workspace-section--rail'])
                            ->schema([
                                Forms\Components\Placeholder::make('ops_publish_readiness')->label(__('ops.edit.sections.publish_readiness'))->content(fn (?LandingSurface $record) => OpsEdit::publishReadiness($record, [
                                    'surface_key' => __('ops.edit.fields.surface_key'),
                                    'locale' => __('ops.edit.fields.locale'),
                                    'payload_json' => __('ops.edit.sections.surface_payload'),
                                ])),
                            ]),
                        Forms\Components\Section::make(__('ops.edit.sections.audit'))
                            ->description(__('ops.edit.descriptions.audit'))
                            ->extraAttributes(['class' => 'ops-edit-workspace-section ops-edit-workspace-section--rail'])
                            ->schema([Forms\Components\Placeholder::make('ops_audit')->label(__('ops.edit.sections.audit'))->content(fn (?LandingSurface $record) => OpsEdit::audit($record))]),
                    ])->columnSpan(['xl' => 4])->extraAttributes(['class' => 'ops-edit-workspace-rail-column']),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('surface_key')
                    ->label(__('ops.nav.landing_surfaces'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (LandingSurface $record): ?string => $record->title),
                OpsTable::locale(),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('ops.resources.articles.fields.title'))
                    ->searchable()
                    ->limit(48)
                    ->toggleable(isToggledHiddenByDefault: true),
                OpsTable::status(),
                Tables\Columns\IconColumn::make('is_public')
                    ->label(__('ops.table.public'))
                    ->boolean()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_indexable')
                    ->label(__('ops.table.indexable'))
                    ->boolean()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label(__('ops.table.updated'))
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('ops.table.status'))
                    ->options([
                        LandingSurface::STATUS_DRAFT => __('ops.status.draft'),
                        LandingSurface::STATUS_PUBLISHED => __('ops.status.published'),
                    ]),
                Tables\Filters\SelectFilter::make('locale')
                    ->label(__('ops.table.locale'))
                    ->options([
                        'zh-CN' => 'zh-CN',
                        'en' => 'en',
                    ]),
            ])
            ->defaultSort('surface_key')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLandingSurfaces::route('/'),
            'create' => Pages\CreateLandingSurface::route('/create'),
            'edit' => Pages\EditLandingSurface::route('/{record}/edit'),
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

    private static function jsonForEdit(mixed $state): string
    {
        if (is_string($state)) {
            return $state;
        }

        return json_encode($state ?: [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * @return array<string,mixed>
     */
    private static function jsonFromEdit(mixed $state): array
    {
        if (is_array($state)) {
            return $state;
        }

        $decoded = json_decode((string) $state, true);

        return is_array($decoded) ? $decoded : [];
    }
}
