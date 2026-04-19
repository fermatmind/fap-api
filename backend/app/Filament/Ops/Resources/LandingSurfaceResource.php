<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\LandingSurfaceResource\Pages;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\StatusBadge;
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
        return 'Landing Surfaces';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('org_id')
                ->default(0),
            Forms\Components\Section::make('Surface')
                ->schema([
                    Forms\Components\TextInput::make('surface_key')
                        ->required()
                        ->maxLength(128)
                        ->helperText('Stable key used by the public API, for example home, tests, career_home.'),
                    Forms\Components\Select::make('locale')
                        ->required()
                        ->native(false)
                        ->options([
                            'zh-CN' => 'zh-CN',
                            'en' => 'en',
                        ])
                        ->default('zh-CN'),
                    Forms\Components\TextInput::make('title')
                        ->maxLength(255),
                    Forms\Components\Textarea::make('description')
                        ->rows(3)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('schema_version')
                        ->default('v1')
                        ->maxLength(32),
                    Forms\Components\Select::make('status')
                        ->required()
                        ->native(false)
                        ->options([
                            LandingSurface::STATUS_DRAFT => 'Draft',
                            LandingSurface::STATUS_PUBLISHED => 'Published',
                        ])
                        ->default(LandingSurface::STATUS_PUBLISHED),
                    Forms\Components\Toggle::make('is_public')
                        ->default(true),
                    Forms\Components\Toggle::make('is_indexable')
                        ->default(true),
                    Forms\Components\DateTimePicker::make('published_at'),
                    Forms\Components\DateTimePicker::make('scheduled_at'),
                ])
                ->columns(2),
            Forms\Components\Section::make('Payload')
                ->description('Store module order, titles, CTAs, featured items, and SEO JSON used by the frontend renderer.')
                ->schema([
                    Forms\Components\Textarea::make('payload_json')
                        ->label('Surface payload')
                        ->rows(18)
                        ->formatStateUsing(fn (mixed $state): string => self::jsonForEdit($state))
                        ->dehydrateStateUsing(fn (mixed $state): array => self::jsonFromEdit($state))
                        ->columnSpanFull(),
                ]),
            Forms\Components\Section::make('Page blocks')
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
                                ->label('Block payload')
                                ->rows(8)
                                ->formatStateUsing(fn (mixed $state): string => self::jsonForEdit($state))
                                ->dehydrateStateUsing(fn (mixed $state): array => self::jsonFromEdit($state))
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('surface_key')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('locale')
                    ->sortable(),
                Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->limit(48),
                Tables\Columns\TextColumn::make('status')
                    ->formatStateUsing(fn (?string $state): string => ucfirst((string) $state))
                    ->badge()
                    ->color(fn (?string $state): string => StatusBadge::color($state))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_public')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
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
