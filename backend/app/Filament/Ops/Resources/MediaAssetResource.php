<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\MediaAssetResource\Pages;
use App\Filament\Ops\Support\ContentAccess;
use App\Filament\Ops\Support\OpsTable;
use App\Models\MediaAsset;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MediaAssetResource extends Resource
{
    protected static ?string $model = MediaAsset::class;

    protected static ?string $slug = 'media-assets';

    protected static ?string $recordTitleAttribute = 'asset_key';

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Media Library';

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
        return __('ops.nav.media_library');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Hidden::make('org_id')
                ->default(0),
            Forms\Components\Section::make('Asset')
                ->schema([
                    Forms\Components\FileUpload::make('uploaded_source')
                        ->label('Upload source image')
                        ->disk('public')
                        ->directory('media-library/sources/inbox')
                        ->visibility('public')
                        ->image()
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                        ->maxSize(10240)
                        ->dehydrated(false)
                        ->afterStateUpdated(function (mixed $state, Forms\Set $set): void {
                            $path = is_array($state) ? (string) reset($state) : (string) $state;
                            if ($path !== '') {
                                $set('disk', 'public');
                                $set('path', $path);
                            }
                        })
                        ->helperText('Uploading a source image generates hero, card, thumbnail, og, and preload variants after save.')
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('asset_key')
                        ->required()
                        ->maxLength(160),
                    Forms\Components\TextInput::make('disk')
                        ->default('public_static')
                        ->maxLength(64),
                    Forms\Components\TextInput::make('path')
                        ->maxLength(512),
                    Forms\Components\TextInput::make('url')
                        ->maxLength(1024)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('mime_type')
                        ->maxLength(128),
                    Forms\Components\TextInput::make('width')
                        ->numeric(),
                    Forms\Components\TextInput::make('height')
                        ->numeric(),
                    Forms\Components\TextInput::make('bytes')
                        ->numeric(),
                    Forms\Components\TextInput::make('alt')
                        ->maxLength(255)
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('caption')
                        ->rows(3)
                        ->columnSpanFull(),
                    Forms\Components\TextInput::make('credit')
                        ->maxLength(255),
                    Forms\Components\Select::make('status')
                        ->required()
                        ->native(false)
                        ->options([
                            MediaAsset::STATUS_DRAFT => 'Draft',
                            MediaAsset::STATUS_PUBLISHED => 'Published',
                        ])
                        ->default(MediaAsset::STATUS_PUBLISHED),
                    Forms\Components\Toggle::make('is_public')
                        ->default(true),
                ])
                ->columns(2),
            Forms\Components\Section::make('Metadata')
                ->schema([
                    Forms\Components\Textarea::make('payload_json')
                        ->label('Asset metadata')
                        ->rows(8)
                        ->formatStateUsing(fn (mixed $state): string => self::jsonForEdit($state))
                        ->dehydrateStateUsing(fn (mixed $state): array => self::jsonFromEdit($state))
                        ->columnSpanFull(),
                ]),
            Forms\Components\Section::make('Variants')
                ->description('Generated automatically from the source image. Edit only for emergency metadata correction.')
                ->schema([
                    Forms\Components\Repeater::make('variants')
                        ->relationship()
                        ->reorderableWithButtons()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => $state['variant_key'] ?? null)
                        ->schema([
                            Forms\Components\TextInput::make('variant_key')
                                ->required()
                                ->maxLength(64),
                            Forms\Components\TextInput::make('path')
                                ->maxLength(512),
                            Forms\Components\TextInput::make('url')
                                ->maxLength(1024)
                                ->columnSpanFull(),
                            Forms\Components\TextInput::make('mime_type')
                                ->maxLength(128),
                            Forms\Components\TextInput::make('width')
                                ->numeric(),
                            Forms\Components\TextInput::make('height')
                                ->numeric(),
                            Forms\Components\TextInput::make('bytes')
                                ->numeric(),
                            Forms\Components\Textarea::make('payload_json')
                                ->label('Variant metadata')
                                ->rows(6)
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
                Tables\Columns\ImageColumn::make('url')
                    ->label('')
                    ->size(44)
                    ->square()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('asset_key')
                    ->label(__('ops.nav.media_library'))
                    ->searchable()
                    ->sortable()
                    ->description(fn (MediaAsset $record): ?string => $record->alt),
                Tables\Columns\TextColumn::make('mime_type')
                    ->label(__('ops.table.asset_type'))
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('alt')
                    ->limit(56)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                OpsTable::status(),
                Tables\Columns\IconColumn::make('is_public')
                    ->label(__('ops.table.public'))
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('width')
                    ->label(__('ops.table.dimensions'))
                    ->state(fn (MediaAsset $record): string => $record->width && $record->height ? "{$record->width} x {$record->height}" : '-')
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
                        MediaAsset::STATUS_DRAFT => __('ops.status.draft'),
                        MediaAsset::STATUS_PUBLISHED => __('ops.status.published'),
                    ]),
            ])
            ->defaultSort('asset_key')
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
            'index' => Pages\ListMediaAssets::route('/'),
            'create' => Pages\CreateMediaAsset::route('/create'),
            'edit' => Pages\EditMediaAsset::route('/{record}/edit'),
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
