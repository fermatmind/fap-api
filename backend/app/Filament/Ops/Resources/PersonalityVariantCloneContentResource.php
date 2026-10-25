<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\PersonalityVariantCloneContentResource\Pages;
use App\Filament\Ops\Support\StatusBadge;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantCloneContent;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityResultIntroduction;
use App\PersonalityCms\DesktopClone\PersonalityDesktopCloneAssetSlotSupport;
use App\PersonalityCms\DesktopClone\PersonalityVariantCloneContentValidator;
use App\Support\Idempotency\IdempotencyKey;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component as LivewireComponent;

class PersonalityVariantCloneContentResource extends Resource
{
    private const ADOPTION_KEY = 'mbti_reviewed_clone_adoption_v1';

    /** Accept already hydrated, reviewed English bytes without regenerating editorial fields. */
    public function adoptReviewedClone(int $profileId, int $variantId, array $input): PersonalityProfileVariantCloneContent
    {
        $adminId = $this->publishingAdminId();
        $clone = $this->reviewedDocument($input, 'clone');
        $intro = $this->reviewedDocument($input, 'intro');
        $code = $clone['full_code'] ?? null;
        $this->requireAdoption(is_string($code) && preg_match('/^[EI][SN][TF][JP]-[AT]$/D', $code) === 1
            && ($clone['base_code'] ?? null) === substr($code, 0, 4)
            && ($clone['locale'] ?? null) === 'en'
            && ($clone['template_key'] ?? null) === PersonalityProfileVariantCloneContent::TEMPLATE_KEY_MBTI_DESKTOP_CLONE_V1
            && ($clone['schema_version'] ?? null) === 'v1'
            && data_get($clone, 'content_json.hero.profile_identity.code') === $code
            && ($intro['schema'] ?? null) === 'mbti_result_introduction.candidate.v1'
            && data_get($intro, 'candidate.full_code') === $code
            && data_get($intro, 'candidate.locale') === 'en', 'Package identity does not match the selected English clone.');
        $paragraphs = data_get($intro, 'candidate.paragraphs');
        $this->requireAdoption(is_array($paragraphs) && array_is_list($paragraphs) && count($paragraphs) === 2
            && count(array_filter($paragraphs, fn ($p): bool => is_string($p) && trim($p) !== '')) === 2,
            'The independent introduction must contain exactly two paragraphs.');
        $this->requireAdoption(is_array($clone['content_json'] ?? null) && is_array($clone['asset_slots_json'] ?? null)
            && (! isset($clone['meta_json']) || is_array($clone['meta_json'])), 'Invalid clone payload.');
        $assets = app(PersonalityVariantCloneContentValidator::class)->assertValid($clone['content_json'], $clone['asset_slots_json'], 'published');
        $this->requireAdoption($assets === $clone['asset_slots_json'], 'Asset normalization would change the reviewed package.');

        return DB::transaction(function () use ($profileId, $variantId, $input, $clone, $intro, $code, $paragraphs, $adminId): PersonalityProfileVariantCloneContent {
            $profile = PersonalityProfile::withoutGlobalScopes()->whereKey($profileId)->lockForUpdate()->first();
            $variant = PersonalityProfileVariant::withoutGlobalScopes()->whereKey($variantId)->lockForUpdate()->first();
            $this->requireAdoption($profile !== null && $variant !== null && $profile->org_id === 0 && $variant->org_id === 0
                && $variant->personality_profile_id === $profile->id && $variant->runtime_type_code === $code
                && $profile->type_code === $clone['base_code'] && $profile->canonical_type_code === $clone['base_code']
                && $variant->canonical_type_code === $clone['base_code'] && $variant->variant_code === substr($code, -1)
                && $profile->locale === 'en' && $profile->scale_code === 'MBTI' && $profile->status === 'published'
                && $profile->is_public && $variant->is_published
                && ($profile->published_at === null || $profile->published_at->lte(now()))
                && ($variant->published_at === null || $variant->published_at->lte(now())), 'Selected profile or variant is not eligible.');
            $currentIntro = PersonalityResultIntroduction::where('full_code', $code)->where('locale', 'en')->lockForUpdate()->first();
            $record = PersonalityProfileVariantCloneContent::where('personality_profile_variant_id', $variantId)
                ->where('template_key', $clone['template_key'])->lockForUpdate()->first();
            $existing = $this->adoptionRevision($variantId);
            if ($record !== null && $currentIntro !== null && $existing !== null) {
                $snapshot = $existing->snapshot_json[self::ADOPTION_KEY];
                if (($snapshot['clone_sha256'] ?? null) === $input['clone_sha256'] && ($snapshot['intro_sha256'] ?? null) === $input['intro_sha256']
                    && ($snapshot['after_hash'] ?? null) === $this->pairHash($record, $currentIntro)) {
                    return $record;
                }
            }
            $this->requireAdoption($record === null, 'Clone already exists; recovery will not overwrite it.');
            $this->requireAdoption($currentIntro !== null && $currentIntro->status === 'published' && $currentIntro->published_at?->lte(now())
                && $currentIntro->revision === data_get($intro, 'source.revision')
                && $currentIntro->content_hash === data_get($intro, 'source.content_hash')
                && $currentIntro->content_hash === $this->paragraphHash($currentIntro->paragraphs), 'Introduction prestate changed.');
            $beforeIntro = $currentIntro->only(['full_code', 'locale', 'paragraphs', 'content_hash', 'revision', 'status', 'published_at']);
            $beforeIntro['published_at'] = $currentIntro->published_at?->toISOString();
            $record = PersonalityProfileVariantCloneContent::create([
                'personality_profile_variant_id' => $variantId, 'template_key' => $clone['template_key'],
                'schema_version' => $clone['schema_version'], 'status' => 'published', 'published_at' => now(),
                'content_json' => $clone['content_json'], 'asset_slots_json' => $clone['asset_slots_json'], 'meta_json' => $clone['meta_json'] ?? null,
            ]);
            $currentIntro->update(['paragraphs' => $paragraphs, 'content_hash' => $this->paragraphHash($paragraphs), 'revision' => $currentIntro->revision + 1]);
            $record->refresh();
            $currentIntro->refresh();
            $this->requireAdoption($this->sameTypedJson($record->content_json, $clone['content_json']) && $this->sameTypedJson($record->asset_slots_json, $clone['asset_slots_json'])
                && $this->sameTypedJson($record->meta_json, $clone['meta_json'] ?? null) && $currentIntro->paragraphs === $paragraphs
                && $currentIntro->content_hash === $this->paragraphHash($paragraphs)
                && $currentIntro->revision === $beforeIntro['revision'] + 1, 'Stored package readback differs from reviewed values.');
            $this->createAdoptionRevision($variantId, $adminId, [
                'role' => 'adoption', 'clone_id' => $record->id,
                'clone_sha256' => $input['clone_sha256'], 'intro_sha256' => $input['intro_sha256'],
                'clone_before' => null, 'intro_before' => $beforeIntro,
                'clone_candidate' => $clone, 'intro_candidate' => $intro,
                'after_hash' => $this->pairHash($record, $currentIntro),
            ]);

            return $record;
        });
    }

    /** Restore the prior introduction and deactivate the newly created clone; never delete history. */
    public function restoreReviewedClone(PersonalityProfileVariantCloneContent $record, string $expectedHash): void
    {
        $adminId = $this->publishingAdminId();
        DB::transaction(function () use ($record, $expectedHash, $adminId): void {
            $profileId = PersonalityProfileVariant::withoutGlobalScopes()->whereKey($record->personality_profile_variant_id)->value('personality_profile_id');
            $profile = PersonalityProfile::withoutGlobalScopes()->whereKey($profileId)->lockForUpdate()->firstOrFail();
            $variant = PersonalityProfileVariant::withoutGlobalScopes()->whereKey($record->personality_profile_variant_id)->lockForUpdate()->firstOrFail();
            $this->requireAdoption($variant->personality_profile_id === $profile->id && $profile->org_id === 0 && $variant->org_id === 0 && $profile->locale === 'en' && $profile->scale_code === 'MBTI', 'Invalid recovery owner.');
            $intro = PersonalityResultIntroduction::where('full_code', $variant->runtime_type_code)->where('locale', 'en')->lockForUpdate()->firstOrFail();
            $record = PersonalityProfileVariantCloneContent::whereKey($record->id)->lockForUpdate()->firstOrFail();
            $revision = $this->adoptionRevision($variant->id);
            $snapshot = $revision?->snapshot_json[self::ADOPTION_KEY] ?? [];
            $this->requireAdoption(($snapshot['clone_id'] ?? null) === $record->id && ($snapshot['after_hash'] ?? null) === $expectedHash
                && $this->pairHash($record, $intro) === $expectedHash, 'A newer version owns the clone or introduction; recovery refused.');
            $before = $snapshot['intro_before'];
            $this->requireAdoption($before['full_code'] === $intro->full_code && $before['locale'] === 'en'
                && $this->paragraphHash($before['paragraphs']) === $before['content_hash'], 'Recovery snapshot is invalid.');
            $record->update(['status' => 'draft']);
            $intro->update(['paragraphs' => $before['paragraphs'], 'content_hash' => $before['content_hash'],
                'status' => $before['status'], 'published_at' => $before['published_at'], 'revision' => $intro->revision + 1]);
            $record->refresh();
            $intro->refresh();
            $this->requireAdoption($record->status === 'draft' && $record->published_at === null && $intro->paragraphs === $before['paragraphs']
                && $intro->content_hash === $before['content_hash'], 'Recovery readback failed.');
            $this->createAdoptionRevision($variant->id, $adminId, ['role' => 'restore', 'clone_id' => $record->id,
                'restored_revision_id' => $revision->id, 'after_hash' => $this->pairHash($record, $intro)]);
        });
    }

    public function reviewedCloneRecoveryHash(PersonalityProfileVariantCloneContent $record): ?string
    {
        $this->publishingAdminId();
        $revision = $this->adoptionRevision($record->personality_profile_variant_id);
        $snapshot = $revision?->snapshot_json[self::ADOPTION_KEY] ?? [];

        return ($snapshot['clone_id'] ?? null) === $record->id ? ($snapshot['after_hash'] ?? null) : null;
    }

    private function adoptionRevision(int $variantId): ?PersonalityProfileVariantRevision
    {
        return PersonalityProfileVariantRevision::where('personality_profile_variant_id', $variantId)
            ->where('note', 'Reviewed English clone adoption')->orderByDesc('revision_no')->first();
    }

    private function createAdoptionRevision(int $variantId, int $adminId, array $snapshot): void
    {
        PersonalityProfileVariantRevision::create(['personality_profile_variant_id' => $variantId,
            'revision_no' => (int) PersonalityProfileVariantRevision::where('personality_profile_variant_id', $variantId)->max('revision_no') + 1,
            'snapshot_json' => [self::ADOPTION_KEY => $snapshot], 'note' => $snapshot['role'] === 'adoption' ? 'Reviewed English clone adoption' : 'Reviewed English clone restore',
            'created_by_admin_user_id' => $adminId, 'created_at' => now()]);
    }

    private function pairHash(PersonalityProfileVariantCloneContent $clone, PersonalityResultIntroduction $intro): string
    {
        $cloneState = $clone->only(['id', 'personality_profile_variant_id', 'template_key', 'status', 'schema_version', 'content_json', 'asset_slots_json', 'meta_json']);
        $introState = $intro->only(['id', 'full_code', 'locale', 'paragraphs', 'content_hash', 'revision', 'status']);

        return IdempotencyKey::hashPayload(['clone' => [...$cloneState, 'published_at' => $clone->published_at?->toISOString()],
            'intro' => [...$introState, 'published_at' => $intro->published_at?->toISOString()]]);
    }

    private function sameTypedJson(mixed $left, mixed $right): bool
    {
        if (! is_array($left) || ! is_array($right)) {
            return $left === $right;
        }
        if (array_is_list($left) !== array_is_list($right) || count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $key => $value) {
            if (! array_key_exists($key, $right) || ! $this->sameTypedJson($value, $right[$key])) {
                return false;
            }
        }

        return true;
    }

    private function paragraphHash(array $paragraphs): string
    {
        return hash('sha256', json_encode($paragraphs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function reviewedDocument(array $input, string $kind): array
    {
        $encoded = (string) ($input[$kind.'_package_base64'] ?? '');
        $this->requireAdoption(strlen($encoded) <= 1400000, 'Reviewed file exceeds the bounded package size.');
        $bytes = base64_decode($encoded, true);
        $this->requireAdoption(is_string($bytes) && strlen($bytes) <= 1048576 && preg_match('/^[a-f0-9]{64}$/D', (string) ($input[$kind.'_sha256'] ?? '')) === 1
            && hash_equals($input[$kind.'_sha256'], hash('sha256', $bytes)), 'Reviewed '.$kind.' file SHA-256 mismatch.');
        try {
            $document = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $document = null;
        }
        $this->requireAdoption(is_array($document), 'Invalid reviewed '.$kind.' JSON.');

        return $document;
    }

    private function requireAdoption(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['reviewed_package' => $message]);
        }
    }

    private function publishingAdminId(): int
    {
        $admin = auth((string) config('admin.guard', 'admin'))->user();
        abort_unless($admin !== null && ($admin->hasPermission(PermissionNames::ADMIN_CONTENT_PUBLISH) || $admin->hasPermission(PermissionNames::ADMIN_OWNER)), 403);

        return (int) $admin->getAuthIdentifier();
    }

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $model = PersonalityProfileVariantCloneContent::class;

    protected static ?string $slug = 'personality-desktop-clone';

    protected static ?string $navigationIcon = 'heroicon-o-window';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Personality Desktop Clone';

    protected static ?string $modelLabel = 'Personality Desktop Clone Content';

    protected static ?string $pluralModelLabel = 'Personality Desktop Clone Content';

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.personality_desktop_clone');
    }

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
                ->options(fn (LivewireComponent $livewire): array => self::variantOptions(
                    $livewire instanceof Pages\CreatePersonalityVariantCloneContent
                        ? $livewire->personalityProfileId
                        : null,
                ))
                ->in(fn (Forms\Components\Select $component): array => array_keys($component->getOptions()))
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
                    Forms\Components\Textarea::make('meta')
                        ->label('meta')
                        ->rules(['nullable', 'json'])
                        ->formatStateUsing(fn (mixed $state): string => self::encodeJson($state))
                        ->dehydrateStateUsing(fn (mixed $state): ?array => self::decodeJsonText($state, 'asset_slots_json.meta'))
                        ->helperText('JSON preserves booleans, numbers, and ordered lists.'),
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
            ->whereHas('variant', function (Builder $variantQuery): void {
                $variantQuery->withoutGlobalScopes()
                    ->whereHas('profile', function (Builder $profileQuery): void {
                        $profileQuery->withoutGlobalScopes()
                            ->where('org_id', 0)
                            ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI);
                    });
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
    public static function variantOptions(?int $personalityProfileId = null): array
    {
        $personalityProfileId ??= max(0, (int) request()->query('profile', 0));

        return PersonalityProfileVariant::query()
            ->withoutGlobalScopes()
            ->with(['profile' => static function (BelongsTo $query): void {
                $query->withoutGlobalScopes()
                    ->where('org_id', 0)
                    ->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
                    ->whereIn('locale', PersonalityProfile::SUPPORTED_LOCALES);
            }])
            ->when(
                $personalityProfileId > 0,
                fn (Builder $query): Builder => $query->where('personality_profile_id', $personalityProfileId),
            )
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
                $profile = $variant->profile;
                if (! $profile instanceof PersonalityProfile) {
                    return [];
                }

                return [
                    (int) $variant->id => sprintf(
                        '%s · %s · profile_org=%d · profile=%d · variant_org=%d · variant=%d · is_published=%s · published_at=%s',
                        $variant->runtime_type_code,
                        $profile->locale,
                        $profile->org_id,
                        $profile->id,
                        $variant->org_id,
                        $variant->id,
                        $variant->is_published ? 'true' : 'false',
                        $variant->published_at?->toIso8601String() ?? 'unset',
                    ),
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
