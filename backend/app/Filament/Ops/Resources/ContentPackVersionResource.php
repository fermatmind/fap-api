<?php

declare(strict_types=1);

namespace App\Filament\Ops\Resources;

use App\Filament\Ops\Resources\ContentPackVersionResource\Pages;
use App\Models\ContentPackVersion;
use App\Services\Content\ContentControlPlaneService;
use App\Support\Rbac\PermissionNames;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class ContentPackVersionResource extends Resource
{
    protected static ?string $model = ContentPackVersion::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Content Pack Versions';

    /**
     * @var array<string,array<string,mixed>>
     */
    private static array $controlPlaneCache = [];

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

    public static function getNavigationGroup(): ?string
    {
        return __('ops.group.content');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.nav.content_pack_versions');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Content control plane')
                ->description('DB-backed authoring metadata coordinates draft, review, release candidate, and rollback flow. Runtime truth still stays on compiled/versioned artifacts.')
                ->schema([
                    Forms\Components\Grid::make(3)
                        ->schema([
                            Forms\Components\Placeholder::make('cp.authoring_scope')
                                ->label('Authoring scope')
                                ->content(fn (?ContentPackVersion $record): string => (string) self::controlPlaneValue($record, 'authoring_scope', 'backend_filament_ops')),
                            Forms\Components\Placeholder::make('cp.content_object_type')
                                ->label('Content object type')
                                ->content(fn (?ContentPackVersion $record): string => (string) self::controlPlaneValue($record, 'content_object_type', 'content_pack_authoring_bundle')),
                            Forms\Components\Placeholder::make('cp.locale_scope')
                                ->label('Locale scope')
                                ->content(fn (?ContentPackVersion $record): string => (string) self::controlPlaneValue($record, 'locale_scope', 'pending')),
                            Forms\Components\Placeholder::make('cp.draft_state')
                                ->label('Draft state')
                                ->content(fn (?ContentPackVersion $record): string => (string) self::controlPlaneValue($record, 'draft_state', 'draft_pending_ingest')),
                            Forms\Components\Placeholder::make('cp.review_state')
                                ->label('Review state')
                                ->content(fn (?ContentPackVersion $record): string => (string) self::controlPlaneValue($record, 'review_state', 'review_pending_ingest')),
                            Forms\Components\Placeholder::make('cp.revision_no')
                                ->label('Revision no')
                                ->content(fn (?ContentPackVersion $record): string => (string) self::controlPlaneValue($record, 'revision_no', 1)),
                            Forms\Components\Placeholder::make('cp.compile_status')
                                ->label('Compile status')
                                ->content(fn (?ContentPackVersion $record): string => (string) self::controlPlaneValue($record, 'compile_status', 'pending_compile')),
                            Forms\Components\Placeholder::make('cp.governance_status')
                                ->label('Governance status')
                                ->content(fn (?ContentPackVersion $record): string => (string) self::controlPlaneValue($record, 'governance_status', 'not_applicable')),
                            Forms\Components\Placeholder::make('cp.release_candidate_status')
                                ->label('Release candidate')
                                ->content(fn (?ContentPackVersion $record): string => (string) self::controlPlaneValue($record, 'release_candidate_status', 'not_ready')),
                        ]),
                    Forms\Components\Grid::make(2)
                        ->schema([
                            Forms\Components\Placeholder::make('cp.preview_target')
                                ->label('Preview target')
                                ->content(fn (?ContentPackVersion $record): string => (string) self::controlPlaneValue($record, 'preview_target', 'ops://content-pack-versions/pending'))
                                ->columnSpanFull(),
                            Forms\Components\Placeholder::make('cp.publish_target')
                                ->label('Publish target')
                                ->content(fn (?ContentPackVersion $record): string => (string) self::controlPlaneValue($record, 'publish_target', 'default/pending/pending/pending'))
                                ->columnSpanFull(),
                            Forms\Components\Placeholder::make('cp.rollback_target')
                                ->label('Rollback target')
                                ->content(fn (?ContentPackVersion $record): string => self::renderRollbackTarget($record))
                                ->columnSpanFull(),
                            Forms\Components\Placeholder::make('cp.runtime_artifact_ref')
                                ->label('Runtime artifact ref')
                                ->content(fn (?ContentPackVersion $record): string => self::renderRuntimeArtifactRef($record))
                                ->columnSpanFull(),
                        ]),
                    Forms\Components\Placeholder::make('cp.experiment_scope')
                        ->label('Experiment / overlay scope')
                        ->content(fn (?ContentPackVersion $record): HtmlString => self::renderExperimentScope($record))
                        ->columnSpanFull(),
                    Forms\Components\Placeholder::make('cp.content_inventory_v1')
                        ->label('MBTI content inventory')
                        ->content(fn (?ContentPackVersion $record): HtmlString => self::renderInventoryContract($record))
                        ->columnSpanFull(),
                    Forms\Components\Placeholder::make('cp.fragment_object_groups_v1')
                        ->label('Objectized fragment groups')
                        ->content(fn (?ContentPackVersion $record): HtmlString => self::renderFragmentObjectGroups($record))
                        ->columnSpanFull(),
                    Forms\Components\Placeholder::make('cp.content_object_inventory')
                        ->label('First-wave managed objects')
                        ->content(fn (?ContentPackVersion $record): HtmlString => self::renderObjectInventory($record))
                        ->columnSpanFull(),
                    Forms\Components\Placeholder::make('cp.content_objects_v1')
                        ->label('Object-level contracts')
                        ->content(fn (?ContentPackVersion $record): HtmlString => self::renderObjectContracts($record))
                        ->columnSpanFull(),
                ])
                ->columns(1),
            Forms\Components\Grid::make(2)
                ->schema([
                    Forms\Components\TextInput::make('region')->required()->maxLength(32),
                    Forms\Components\TextInput::make('locale')->required()->maxLength(32),
                    Forms\Components\TextInput::make('pack_id')->required()->maxLength(128),
                    Forms\Components\TextInput::make('content_package_version')->required()->maxLength(64),
                    Forms\Components\TextInput::make('dir_version_alias')->required()->maxLength(128),
                    Forms\Components\TextInput::make('sha256')->required()->maxLength(64),
                    Forms\Components\TextInput::make('source_type')->required()->maxLength(32),
                    Forms\Components\TextInput::make('created_by')->maxLength(64),
                ]),
            Forms\Components\Textarea::make('source_ref')->required()->rows(2),
            Forms\Components\Textarea::make('extracted_rel_path')->required()->rows(2),
            Forms\Components\Textarea::make('manifest_json')
                ->label('Manifest JSON')
                ->required()
                ->rows(12)
                ->formatStateUsing(function (mixed $state): string {
                    if (is_array($state)) {
                        return (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    }

                    return is_string($state) && trim($state) !== '' ? $state : '{}';
                })
                ->dehydrateStateUsing(function (string $state): array {
                    $decoded = json_decode($state, true);

                    return is_array($decoded) ? $decoded : [];
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('pack_id')->searchable(),
                Tables\Columns\TextColumn::make('content_package_version')->label('Version')->searchable(),
                Tables\Columns\TextColumn::make('control_plane_draft_state')
                    ->label('Draft')
                    ->badge()
                    ->state(fn (ContentPackVersion $record): string => (string) self::controlPlaneValue($record, 'draft_state', 'draft_pending_ingest'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('control_plane_review_state')
                    ->label('Review')
                    ->badge()
                    ->state(fn (ContentPackVersion $record): string => (string) self::controlPlaneValue($record, 'review_state', 'review_pending_ingest'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('control_plane_release_candidate_status')
                    ->label('Release Candidate')
                    ->badge()
                    ->state(fn (ContentPackVersion $record): string => (string) self::controlPlaneValue($record, 'release_candidate_status', 'not_ready'))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('dir_version_alias')->label('Dir Alias')->searchable(),
                Tables\Columns\TextColumn::make('region')->sortable(),
                Tables\Columns\TextColumn::make('locale')->sortable(),
                Tables\Columns\TextColumn::make('sha256')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListContentPackVersions::route('/'),
            'create' => Pages\CreateContentPackVersion::route('/create'),
            'edit' => Pages\EditContentPackVersion::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function controlPlane(?ContentPackVersion $record): array
    {
        if (! $record instanceof ContentPackVersion) {
            return [
                'authoring_scope' => 'backend_filament_ops',
                'content_object_type' => 'content_pack_authoring_bundle',
                'draft_state' => 'draft_pending_ingest',
                'revision_no' => 1,
                'review_state' => 'review_pending_ingest',
                'preview_target' => 'ops://content-pack-versions/pending',
                'compile_status' => 'pending_compile',
                'governance_status' => 'not_applicable',
                'release_candidate_status' => 'not_ready',
                'publish_target' => 'default/pending/pending/pending',
                'rollback_target' => null,
                'locale_scope' => 'pending',
                'experiment_scope' => [
                    'stable_files' => 0,
                    'experiment_files' => 0,
                    'commercial_overlay_files' => 0,
                    'experiment_keys' => [],
                    'overlay_targets' => [],
                ],
                'content_inventory_v1' => [
                    'inventory_status' => 'not_applicable',
                    'inventory_contract_version' => 0,
                    'inventory_fingerprint' => null,
                    'governance_profile' => null,
                    'fragment_family_count' => 0,
                    'fragment_family_keys' => [],
                    'fragment_object_group_count' => 0,
                    'fragment_object_group_keys' => [],
                    'selection_tag_count' => 0,
                    'selection_tag_keys' => [],
                    'section_family_count' => 0,
                    'section_family_keys' => [],
                ],
                'fragment_object_groups_v1' => [],
                'runtime_artifact_ref' => null,
                'content_object_inventory' => [],
                'content_objects_v1' => [],
            ];
        }

        $key = (string) $record->getKey();
        if (! isset(self::$controlPlaneCache[$key])) {
            self::$controlPlaneCache[$key] = app(ContentControlPlaneService::class)->forVersion($record)['content_control_plane_v1'];
        }

        return self::$controlPlaneCache[$key];
    }

    private static function controlPlaneValue(?ContentPackVersion $record, string $key, mixed $default = null): mixed
    {
        return self::controlPlane($record)[$key] ?? $default;
    }

    private static function renderRuntimeArtifactRef(?ContentPackVersion $record): string
    {
        $artifact = self::controlPlaneValue($record, 'runtime_artifact_ref');
        if (! is_array($artifact) || $artifact === []) {
            return 'No published runtime artifact yet. Draft metadata remains control-plane only until compile + publish succeed.';
        }

        $parts = array_filter([
            'release_id='.(string) ($artifact['release_id'] ?? ''),
            'dir_alias='.(string) ($artifact['dir_alias'] ?? ''),
            'pack_version='.(string) ($artifact['pack_version'] ?? ''),
            'storage_path='.(string) ($artifact['storage_path'] ?? ''),
        ], static fn (string $value): bool => ! str_ends_with($value, '='));

        return implode(' | ', $parts);
    }

    private static function renderRollbackTarget(?ContentPackVersion $record): string
    {
        $target = self::controlPlaneValue($record, 'rollback_target');
        if (! is_array($target) || $target === []) {
            return 'No prior runtime artifact recorded for rollback.';
        }

        return implode(' | ', array_filter([
            'release_id='.(string) ($target['release_id'] ?? ''),
            'dir_alias='.(string) ($target['dir_alias'] ?? ''),
            'pack_version='.(string) ($target['pack_version'] ?? ''),
        ], static fn (string $value): bool => ! str_ends_with($value, '=')));
    }

    private static function renderExperimentScope(?ContentPackVersion $record): HtmlString
    {
        $scope = self::controlPlaneValue($record, 'experiment_scope', []);
        if (! is_array($scope)) {
            $scope = [];
        }

        $experimentKeys = implode(', ', (array) ($scope['experiment_keys'] ?? []));
        $overlayTargets = implode(', ', (array) ($scope['overlay_targets'] ?? []));

        $items = [
            'stable_files='.(int) ($scope['stable_files'] ?? 0),
            'experiment_files='.(int) ($scope['experiment_files'] ?? 0),
            'commercial_overlay_files='.(int) ($scope['commercial_overlay_files'] ?? 0),
            'experiment_keys='.($experimentKeys !== '' ? $experimentKeys : 'none'),
            'overlay_targets='.($overlayTargets !== '' ? $overlayTargets : 'none'),
        ];

        return new HtmlString('<ul><li>'.implode('</li><li>', array_map('e', $items)).'</li></ul>');
    }

    private static function renderInventoryContract(?ContentPackVersion $record): HtmlString
    {
        $inventory = self::controlPlaneValue($record, 'content_inventory_v1', []);
        if (! is_array($inventory) || $inventory === []) {
            return new HtmlString('<span>No MBTI content inventory contract available yet.</span>');
        }

        $fragmentFamilyKeys = implode(', ', (array) ($inventory['fragment_family_keys'] ?? []));
        $fragmentObjectGroupKeys = implode(', ', (array) ($inventory['fragment_object_group_keys'] ?? []));
        $sectionFamilyKeys = implode(', ', (array) ($inventory['section_family_keys'] ?? []));
        $selectionTagKeys = implode(', ', (array) ($inventory['selection_tag_keys'] ?? []));

        $items = [
            'inventory_status='.(string) ($inventory['inventory_status'] ?? 'unknown'),
            'inventory_contract_version='.(int) ($inventory['inventory_contract_version'] ?? 0),
            'inventory_fingerprint='.(string) ($inventory['inventory_fingerprint'] ?? 'none'),
            'governance_profile='.(string) ($inventory['governance_profile'] ?? 'none'),
            'fragment_family_count='.(int) ($inventory['fragment_family_count'] ?? 0),
            'fragment_family_keys='.($fragmentFamilyKeys !== '' ? $fragmentFamilyKeys : 'none'),
            'fragment_object_group_count='.(int) ($inventory['fragment_object_group_count'] ?? 0),
            'fragment_object_group_keys='.($fragmentObjectGroupKeys !== '' ? $fragmentObjectGroupKeys : 'none'),
            'selection_tag_count='.(int) ($inventory['selection_tag_count'] ?? 0),
            'selection_tag_keys='.($selectionTagKeys !== '' ? $selectionTagKeys : 'none'),
            'section_family_count='.(int) ($inventory['section_family_count'] ?? 0),
            'section_family_keys='.($sectionFamilyKeys !== '' ? $sectionFamilyKeys : 'none'),
        ];

        return new HtmlString('<ul><li>'.implode('</li><li>', array_map('e', $items)).'</li></ul>');
    }

    private static function renderFragmentObjectGroups(?ContentPackVersion $record): HtmlString
    {
        $groups = self::controlPlaneValue($record, 'fragment_object_groups_v1', []);
        if (! is_array($groups) || $groups === []) {
            return new HtmlString('<span>No objectized fragment groups are visible yet.</span>');
        }

        $items = array_map(static function (mixed $item): string {
            if (! is_array($item)) {
                return '';
            }

            $sourceRefs = implode(', ', array_values(array_filter((array) ($item['source_refs'] ?? []), 'is_string')));
            $parts = [
                (string) ($item['object_group_key'] ?? 'unknown_group'),
                'family='.(string) ($item['fragment_family'] ?? 'unknown'),
                'authoring='.(string) ($item['authoring_scope'] ?? 'unknown'),
                'review_profile='.(string) ($item['review_state_profile'] ?? 'unknown'),
                'preview_target='.(string) ($item['preview_target_key'] ?? 'unknown'),
                'runtime_binding='.(string) ($item['runtime_binding'] ?? 'metadata_only'),
                'governance='.(string) ($item['governance_profile'] ?? 'unknown'),
                'source_refs='.($sourceRefs !== '' ? $sourceRefs : 'none'),
            ];

            return e(implode(' | ', $parts));
        }, $groups);
        $items = array_values(array_filter($items, static fn (string $item): bool => $item !== ''));

        return new HtmlString('<ul><li>'.implode('</li><li>', $items).'</li></ul>');
    }

    private static function renderObjectInventory(?ContentPackVersion $record): HtmlString
    {
        $inventory = self::controlPlaneValue($record, 'content_object_inventory', []);
        if (! is_array($inventory) || $inventory === []) {
            return new HtmlString('<span>No control-plane managed objects discovered yet.</span>');
        }

        $items = array_map(static function (mixed $item): string {
            if (! is_array($item)) {
                return '';
            }

            $type = (string) ($item['type'] ?? 'unknown');
            $enabled = (bool) ($item['enabled'] ?? false);
            $fragmentFamily = trim((string) ($item['fragment_family'] ?? ''));
            $runtimeBinding = trim((string) ($item['runtime_binding'] ?? ''));

            $parts = [
                $type,
                $enabled ? 'enabled' : 'not_in_scope',
            ];
            if ($fragmentFamily !== '') {
                $parts[] = 'family='.$fragmentFamily;
            }
            if ($runtimeBinding !== '') {
                $parts[] = 'runtime_binding='.$runtimeBinding;
            }

            return e(implode(' | ', $parts));
        }, $inventory);
        $items = array_values(array_filter($items, static fn (string $item): bool => $item !== ''));

        return new HtmlString('<ul><li>'.implode('</li><li>', $items).'</li></ul>');
    }

    private static function renderObjectContracts(?ContentPackVersion $record): HtmlString
    {
        $contracts = self::controlPlaneValue($record, 'content_objects_v1', []);
        if (! is_array($contracts) || $contracts === []) {
            return new HtmlString('<span>No object-level control-plane contracts available yet.</span>');
        }

        $items = array_map(static function (mixed $item): string {
            if (! is_array($item)) {
                return '';
            }

            $artifact = $item['runtime_artifact_ref'] ?? null;
            $artifactRef = 'runtime_artifact_ref=none';
            if (is_array($artifact) && $artifact !== []) {
                $artifactRef = 'runtime_artifact_ref='.(string) ($artifact['storage_path'] ?? ($artifact['dir_alias'] ?? 'published'));
            }

            $sourceRefs = implode(', ', array_values(array_filter((array) ($item['source_refs'] ?? []), 'is_string')));
            $parts = [
                (string) ($item['content_object_type'] ?? 'unknown'),
                'family='.(string) ($item['fragment_family'] ?? 'unknown'),
                'object_group='.(string) ($item['object_group_key'] ?? 'n/a'),
                'draft='.(string) ($item['draft_state'] ?? 'unknown'),
                'review='.(string) ($item['review_state'] ?? 'unknown'),
                'compile='.(string) ($item['compile_status'] ?? 'unknown'),
                'governance='.(string) ($item['governance_status'] ?? 'unknown'),
                'release_candidate='.(string) ($item['release_candidate_status'] ?? 'unknown'),
                'preview='.(string) ($item['preview_target'] ?? 'unknown'),
                'locale='.(string) ($item['locale_scope'] ?? 'unknown'),
                'runtime_binding='.(string) ($item['runtime_binding'] ?? 'metadata_only'),
                'source_refs='.($sourceRefs !== '' ? $sourceRefs : 'none'),
                $artifactRef,
            ];

            return e(implode(' | ', $parts));
        }, $contracts);
        $items = array_values(array_filter($items, static fn (string $item): bool => $item !== ''));

        return new HtmlString('<ul><li>'.implode('</li><li>', $items).'</li></ul>');
    }
}
