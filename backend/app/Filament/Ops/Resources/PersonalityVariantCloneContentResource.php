<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\PersonalityVariantCloneContentResource\Pages;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantCloneContent;
use App\PersonalityCms\DesktopClone\PersonalityDesktopCloneAssetSlotSupport;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class PersonalityVariantCloneContentResource extends Resource
{
    protected static ?string $model = PersonalityProfileVariantCloneContent::class;

    protected static ?string $slug = 'personality-desktop-clone';

    protected static ?string $navigationIcon = 'heroicon-o-window';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Personality Desktop Clone';

    protected static ?string $modelLabel = 'Personality Desktop Clone Content';

    protected static ?string $pluralModelLabel = 'Personality Desktop Clone Content';

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

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('personality_profile_variant_id')
                ->label('MBTI full code variant')
                ->required()
                ->searchable()
                ->native(false)
                ->options(fn (): array => self::variantOptions())
                ->helperText('Bound to existing PersonalityProfileVariant owner rows only. Import fails if the variant does not exist.'),
            Forms\Components\Select::make('template_key')
                ->required()
                ->native(false)
                ->options([
                    PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1 => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
                ])
                ->default(PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1)
                ->helperText('Current clone template key is fixed to mbti_desktop_clone_v1.'),
            Forms\Components\Select::make('status')
                ->required()
                ->native(false)
                ->options(self::statusOptions())
                ->default(PersonalityProfileVariantCloneContent::STATUS_DRAFT),
            Forms\Components\TextInput::make('schema_version')
                ->required()
                ->default('v1')
                ->maxLength(32),
            Forms\Components\DateTimePicker::make('published_at')
                ->helperText('When status=published and this field is empty, backend auto-assigns current timestamp.'),
            Forms\Components\Textarea::make('content_json')
                ->required()
                ->rules(['required', 'json'])
                ->rows(20)
                ->default('{}')
                ->formatStateUsing(fn (mixed $state): string => self::encodeJson($state))
                ->dehydrateStateUsing(fn (mixed $state): array => self::decodeJsonText($state, 'content_json') ?? [])
                ->helperText('Authoritative desktop clone content JSON for this fullCode variant.'),
            Forms\Components\Repeater::make('asset_slots_json')
                ->required()
                ->default(self::defaultAssetSlots())
                ->formatStateUsing(fn (mixed $state): array => PersonalityDesktopCloneAssetSlotSupport::normalizeAssetSlots(
                    is_array($state) ? $state : [],
                ))
                ->dehydrateStateUsing(fn (mixed $state): array => PersonalityDesktopCloneAssetSlotSupport::normalizeAssetSlots(
                    is_array($state) ? $state : [],
                ))
                ->itemLabel(static fn (array $state): string => (string) ($state['slot_id'] ?? 'asset-slot'))
                ->schema([
                    Forms\Components\Select::make('slot_id')
                        ->required()
                        ->native(false)
                        ->options(PersonalityDesktopCloneAssetSlotSupport::slotIdOptions())
                        ->helperText('slot_id is fixed by desktop clone template schema.'),
                    Forms\Components\TextInput::make('label')
                        ->required()
                        ->maxLength(120),
                    Forms\Components\TextInput::make('aspect_ratio')
                        ->required()
                        ->maxLength(16)
                        ->helperText('Use ratio format like 236:160 / 636:148.'),
                    Forms\Components\Select::make('status')
                        ->required()
                        ->native(false)
                        ->options(self::assetStatusOptions()),
                    Forms\Components\TextInput::make('asset_ref.provider')
                        ->label('asset_ref.provider')
                        ->maxLength(32),
                    Forms\Components\TextInput::make('asset_ref.path')
                        ->label('asset_ref.path')
                        ->maxLength(2048),
                    Forms\Components\TextInput::make('asset_ref.url')
                        ->label('asset_ref.url')
                        ->maxLength(2048),
                    Forms\Components\TextInput::make('asset_ref.version')
                        ->label('asset_ref.version')
                        ->maxLength(120),
                    Forms\Components\TextInput::make('asset_ref.checksum')
                        ->label('asset_ref.checksum')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('alt')
                        ->label('alt')
                        ->maxLength(255),
                    Forms\Components\KeyValue::make('meta')
                        ->label('meta')
                        ->keyLabel('key')
                        ->valueLabel('value'),
                ])
                ->columns(2)
                ->reorderable(false)
                ->collapsible()
                ->helperText('Authoritative asset slot owner fields: slot_id/label/aspect_ratio/status/asset_ref/alt/meta.'),
            Forms\Components\Textarea::make('meta_json')
                ->rules(['nullable', 'json'])
                ->rows(8)
                ->default('')
                ->formatStateUsing(fn (mixed $state): string => self::encodeJson($state))
                ->dehydrateStateUsing(fn (mixed $state): ?array => self::decodeJsonText($state, 'meta_json'))
                ->helperText('Optional metadata. Keep runtime-only fields outside this JSON.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('variant.runtime_type_code')
                    ->label('Full code')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('variant.profile.locale')
                    ->label('Locale')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('template_key')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('schema_version')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => StatusBadge::color($state)),
                Tables\Columns\TextColumn::make('published_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Not published'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(self::statusOptions()),
                Tables\Filters\SelectFilter::make('template_key')
                    ->options([
                        PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1 => PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1,
                    ]),
            ])
            ->defaultSort('updated_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPersonalityVariantCloneContents::route('/'),
            'create' => Pages\CreatePersonalityVariantCloneContent::route('/create'),
            'edit' => Pages\EditPersonalityVariantCloneContent::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'variant.profile',
            ])
            ->whereHas('variant.profile', function (Builder $query): void {
                $query->withoutGlobalScopes()
                    ->where('org_id', 0)
                    ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI);
            });
    }

    /**
     * @return array<string, string>
     */
    private static function statusOptions(): array
    {
        return [
            PersonalityProfileVariantCloneContent::STATUS_DRAFT => PersonalityProfileVariantCloneContent::STATUS_DRAFT,
            PersonalityProfileVariantCloneContent::STATUS_PUBLISHED => PersonalityProfileVariantCloneContent::STATUS_PUBLISHED,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function assetStatusOptions(): array
    {
        return array_combine(
            PersonalityDesktopCloneAssetSlotSupport::allowedStatuses(),
            PersonalityDesktopCloneAssetSlotSupport::allowedStatuses(),
        ) ?: [];
    }

    /**
     * @return array<int, string>
     */
    private static function variantOptions(): array
    {
        return PersonalityProfileVariant::query()
            ->with('profile')
            ->whereHas('profile', function (Builder $query): void {
                $query->withoutGlobalScopes()
                    ->where('org_id', 0)
                    ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
                    ->whereIn('locale', PersonalityProfile::SUPPORTED_LOCALES);
            })
            ->orderBy('runtime_type_code')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(static function (PersonalityProfileVariant $variant): array {
                $locale = (string) ($variant->profile?->locale ?? 'unknown');

                return [
                    (int) $variant->id => (string) $variant->runtime_type_code.' · '.$locale,
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function defaultAssetSlots(): array
    {
        return [
            [
                'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_HERO_ILLUSTRATION,
                'label' => 'Hero illustration',
                'aspect_ratio' => '236:160',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'asset_ref' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_TRAITS_ILLUSTRATION,
                'label' => 'Traits illustration',
                'aspect_ratio' => '636:148',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'asset_ref' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_TRAITS_SUMMARY_ILLUSTRATION,
                'label' => 'Traits summary illustration',
                'aspect_ratio' => '240:118',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'asset_ref' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_CAREER_ILLUSTRATION,
                'label' => 'Career illustration',
                'aspect_ratio' => '636:148',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'asset_ref' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_GROWTH_ILLUSTRATION,
                'label' => 'Growth illustration',
                'aspect_ratio' => '636:148',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'asset_ref' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_RELATIONSHIPS_ILLUSTRATION,
                'label' => 'Relationships illustration',
                'aspect_ratio' => '636:148',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'asset_ref' => null,
                'alt' => null,
                'meta' => null,
            ],
            [
                'slot_id' => PersonalityDesktopCloneAssetSlotSupport::SLOT_ID_FINAL_OFFER_ILLUSTRATION,
                'label' => 'Final offer illustration',
                'aspect_ratio' => '252:220',
                'status' => PersonalityDesktopCloneAssetSlotSupport::STATUS_PLACEHOLDER,
                'asset_ref' => null,
                'alt' => null,
                'meta' => null,
            ],
        ];
    }

    private static function encodeJson(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJsonText(mixed $value, string $field): ?array
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages([
                $field => 'This field must contain valid JSON.',
            ]);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                $field => 'This field must decode to a JSON object or array.',
            ]);
        }

        return $decoded;
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
