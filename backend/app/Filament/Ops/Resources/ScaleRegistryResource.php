<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\ScaleRegistryResource\Pages;
use App\Filament\Ops\Resources\ScaleRegistryResource\RelationManagers\ScaleSlugsRelationManager;
use App\Models\ScaleRegistry;
use App\Support\OrgContext;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ScaleRegistryResource extends Resource
{
    protected static ?string $model = ScaleRegistry::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Scale Registry';

    public static function canViewAny(): bool
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

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Tabs::make('Scale')
                ->tabs([
                    Forms\Components\Tabs\Tab::make('Basic')
                        ->schema([
                            Forms\Components\Grid::make(2)
                                ->schema([
                                    Forms\Components\TextInput::make('code')
                                        ->label('Scale Code')
                                        ->required()
                                        ->maxLength(64),
                                    Forms\Components\TextInput::make('org_id')
                                        ->numeric()
                                        ->required()
                                        ->default(fn () => max(0, (int) app(OrgContext::class)->orgId())),
                                    Forms\Components\TextInput::make('driver_type')
                                        ->required()
                                        ->maxLength(32),
                                    Forms\Components\TextInput::make('assessment_driver')
                                        ->maxLength(64),
                                    Forms\Components\TextInput::make('default_pack_id')
                                        ->label('Default Pack Pointer')
                                        ->maxLength(128),
                                    Forms\Components\TextInput::make('default_dir_version')
                                        ->label('Default Dir Version Pointer')
                                        ->maxLength(128),
                                    Forms\Components\TextInput::make('default_region')->maxLength(32),
                                    Forms\Components\TextInput::make('default_locale')->maxLength(32),
                                ]),
                            Forms\Components\TagsInput::make('slugs_json')
                                ->label('Slugs JSON')
                                ->separator(','),
                            Forms\Components\Section::make('Visibility Policy')
                                ->schema([
                                    Forms\Components\Toggle::make('view_policy_json.public')
                                        ->label('Public')
                                        ->default(true),
                                    Forms\Components\Toggle::make('view_policy_json.indexable')
                                        ->label('Indexable')
                                        ->default(true),
                                    Forms\Components\TextInput::make('view_policy_json.robots')
                                        ->label('Robots Policy')
                                        ->placeholder('index,follow')
                                        ->maxLength(64),
                                ]),
                            Forms\Components\Grid::make(2)
                                ->schema([
                                    Forms\Components\Toggle::make('is_public')->default(true),
                                    Forms\Components\Toggle::make('is_active')->default(true),
                                    Forms\Components\Toggle::make('is_indexable')->default(true),
                                ]),
                        ]),
                    Forms\Components\Tabs\Tab::make('SEO (EN)')
                        ->schema([
                            Forms\Components\TextInput::make('primary_slug')
                                ->label('Primary Slug')
                                ->required()
                                ->maxLength(127),
                            Forms\Components\TextInput::make('seo_i18n_json.en.title')
                                ->label('SEO Title')
                                ->maxLength(60)
                                ->helperText('Recommended <= 60 chars'),
                            Forms\Components\Textarea::make('seo_i18n_json.en.description')
                                ->label('SEO Description')
                                ->rows(3)
                                ->maxLength(160)
                                ->helperText('Recommended <= 160 chars'),
                            Forms\Components\TextInput::make('seo_i18n_json.en.og_image_url')
                                ->label('OG Image URL')
                                ->maxLength(255),
                        ]),
                    Forms\Components\Tabs\Tab::make('SEO (ZH)')
                        ->schema([
                            Forms\Components\TextInput::make('seo_i18n_json.zh.title')
                                ->label('SEO 标题')
                                ->maxLength(60)
                                ->helperText('建议 <= 60 字'),
                            Forms\Components\Textarea::make('seo_i18n_json.zh.description')
                                ->label('SEO 描述')
                                ->rows(3)
                                ->maxLength(160)
                                ->helperText('建议 <= 160 字'),
                            Forms\Components\TextInput::make('seo_i18n_json.zh.og_image_url')
                                ->label('OG 图片 URL')
                                ->maxLength(255),
                        ]),
                    Forms\Components\Tabs\Tab::make('Content (EN)')
                        ->schema([
                            Forms\Components\Textarea::make('content_i18n_json.en.landing_copy')
                                ->label('Landing Copy')
                                ->rows(4),
                            Forms\Components\Textarea::make('content_i18n_json.en.faq')
                                ->label('FAQ (JSON/Text)')
                                ->rows(4),
                            Forms\Components\Textarea::make('content_i18n_json.en.disclaimer')
                                ->label('Disclaimer')
                                ->rows(3),
                            Forms\Components\Textarea::make('report_summary_i18n_json.en.summary')
                                ->label('Report Summary')
                                ->rows(3),
                        ]),
                    Forms\Components\Tabs\Tab::make('Content (ZH)')
                        ->schema([
                            Forms\Components\Textarea::make('content_i18n_json.zh.landing_copy')
                                ->label('落地页文案')
                                ->rows(4),
                            Forms\Components\Textarea::make('content_i18n_json.zh.faq')
                                ->label('FAQ（JSON/文本）')
                                ->rows(4),
                            Forms\Components\Textarea::make('content_i18n_json.zh.disclaimer')
                                ->label('免责声明')
                                ->rows(3),
                            Forms\Components\Textarea::make('report_summary_i18n_json.zh.summary')
                                ->label('报告摘要')
                                ->rows(3),
                        ]),
                    Forms\Components\Tabs\Tab::make('Legacy SEO')
                        ->schema([
                            Forms\Components\TextInput::make('seo_schema_json.title')
                                ->label('Legacy SEO Title')
                                ->maxLength(60),
                            Forms\Components\Textarea::make('seo_schema_json.description')
                                ->label('Legacy SEO Description')
                                ->rows(3)
                                ->maxLength(160),
                            Forms\Components\TextInput::make('seo_schema_json.og.title')
                                ->label('Legacy OG Title')
                                ->maxLength(90),
                            Forms\Components\Textarea::make('seo_schema_json.og.description')
                                ->label('Legacy OG Description')
                                ->rows(3)
                                ->maxLength(200),
                            Forms\Components\TextInput::make('seo_schema_json.robots')
                                ->label('Legacy Robots')
                                ->placeholder('index,follow')
                                ->maxLength(64),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('org_id')->sortable(),
                Tables\Columns\TextColumn::make('primary_slug')->searchable()->copyable(),
                Tables\Columns\IconColumn::make('is_public')->boolean()->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->sortable(),
                Tables\Columns\IconColumn::make('indexable')
                    ->label('Indexable')
                    ->boolean()
                    ->state(fn (ScaleRegistry $record): bool => (bool) ($record->is_indexable ?? ($record->view_policy_json['indexable'] ?? true))),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [
            ScaleSlugsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListScaleRegistries::route('/'),
            'create' => Pages\CreateScaleRegistry::route('/create'),
            'edit' => Pages\EditScaleRegistry::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $orgId = max(0, (int) app(OrgContext::class)->orgId());

        return parent::getEloquentQuery()
            ->whereIn('org_id', $orgId > 0 ? [0, $orgId] : [0]);
    }
}
