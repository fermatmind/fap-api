<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CareerJobDisplayAsset;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CareerNormalizeLegacyDisplayAssets extends Command
{
    private const COMMAND_NAME = 'career:normalize-legacy-display-assets';

    private const VALIDATOR_VERSION = 'career_legacy_display_asset_normalizer_v0.1';

    private const ASSET_VERSION = 'v4.2';

    /** @var list<string> */
    private const AFFECTED_SLUGS = [
        'actors',
        'accountants-and-auditors',
        'actuaries',
        'architectural-and-engineering-managers',
        'biomedical-engineers',
        'civil-engineers',
        'data-scientists',
        'dentists',
        'financial-analysts',
        'high-school-teachers',
        'market-research-analysts',
        'registered-nurses',
    ];

    /** @var list<string> */
    private const RELEASE_GATE_SLUGS = [
        'accountants-and-auditors',
        'actuaries',
        'architectural-and-engineering-managers',
        'biomedical-engineers',
        'civil-engineers',
        'data-scientists',
        'dentists',
        'financial-analysts',
        'high-school-teachers',
        'market-research-analysts',
        'registered-nurses',
    ];

    /** @var array<string, int> */
    private const LINEAGE_SAFE_ROWS = [
        'actuaries' => 4,
        'architectural-and-engineering-managers' => 62,
        'biomedical-engineers' => 115,
        'civil-engineers' => 171,
        'dentists' => 1940,
        'financial-analysts' => 2054,
        'high-school-teachers' => 2195,
        'market-research-analysts' => 2310,
    ];

    /** @var list<string> */
    private const FORBIDDEN_PUBLIC_KEYS = [
        'release_gate',
        'release_gates',
        'qa_risk',
        'admin_review_state',
        'tracking_json',
        'raw_ai_exposure_score',
    ];

    /** @var list<string> */
    private const PUBLIC_PAYLOAD_COLUMNS = [
        'component_order_json',
        'page_payload_json',
        'seo_payload_json',
        'sources_json',
        'structured_data_json',
        'implementation_contract_json',
    ];

    protected $signature = 'career:normalize-legacy-display-assets
        {--slugs= : Comma-separated explicit slug allowlist}
        {--dry-run : Validate and report without writing}
        {--force : Required to update legacy display asset JSON fields}
        {--json : Emit machine-readable report}
        {--backup-path= : Optional path for a pre-force JSON backup of affected rows}';

    protected $description = 'Guarded normalization for legacy v4.2 career display assets.';

    public function handle(): int
    {
        $report = $this->baseReport();

        try {
            $force = (bool) $this->option('force');
            $dryRun = (bool) $this->option('dry-run');

            if ($force && $dryRun) {
                return $this->finish(array_merge($report, [
                    'mode' => 'invalid',
                    'decision' => 'fail',
                    'errors' => ['--dry-run and --force cannot be used together.'],
                ]), false);
            }

            $slugs = $this->requiredSlugs();
            try {
                $assets = $this->assetsBySlug($slugs);
            } catch (QueryException $queryException) {
                if ($force || ! app()->environment(['local', 'testing'])) {
                    throw $queryException;
                }

                return $this->finish(array_merge($report, [
                    'mode' => 'dry_run',
                    'requested_slugs' => $slugs,
                    'decision' => 'no_go',
                    'local_db_unavailable' => true,
                    'blocking_reasons' => [
                        'Local dry-run could not inspect career_job_display_assets; run target dry-run against production DB before --force.',
                    ],
                ]), true);
            }
            $items = [];
            $errors = [];

            foreach ($slugs as $slug) {
                $asset = $assets[$slug] ?? null;
                if (! $asset instanceof CareerJobDisplayAsset) {
                    $errors[] = "{$slug}: v4.2 display asset row was not found.";

                    continue;
                }

                $item = $this->planItem($asset);
                $errors = array_merge($errors, array_map(
                    static fn (string $error): string => "{$slug}: {$error}",
                    $item['errors'],
                ));
                $items[] = $item;
            }

            $report = array_merge($report, $this->summarize($items), [
                'mode' => $force ? 'force' : 'dry_run',
                'requested_slugs' => $slugs,
                'items' => $items,
                'validated_count' => count($items),
            ]);

            if ($errors !== []) {
                return $this->finish(array_merge($report, [
                    'decision' => 'fail',
                    'errors' => $errors,
                ]), false);
            }

            $report['decision'] = 'pass';

            if (! $force) {
                return $this->finish($report, true);
            }

            $backupPath = $this->backupPath();
            if ($backupPath !== null) {
                $this->writeBackup($backupPath, array_values($assets));
                $report['backup_path'] = $backupPath;
            }

            $updated = DB::transaction(function () use ($items): array {
                $updated = [];

                foreach ($items as $item) {
                    if (($item['would_update'] ?? false) !== true) {
                        continue;
                    }

                    $asset = CareerJobDisplayAsset::query()
                        ->where('canonical_slug', $item['slug'])
                        ->where('asset_version', self::ASSET_VERSION)
                        ->firstOrFail();

                    $asset->page_payload_json = $item['after']['page_payload_json'];
                    $asset->metadata_json = $item['after']['metadata_json'];
                    $asset->save();

                    $updated[] = [
                        'slug' => $item['slug'],
                        'row_id' => $asset->id,
                    ];
                }

                return $updated;
            });

            return $this->finish(array_merge($report, [
                'did_write' => count($updated) > 0,
                'updated_count' => count($updated),
                'updated_assets' => $updated,
            ]), true);
        } catch (Throwable $throwable) {
            return $this->finish(array_merge($report, [
                'decision' => 'fail',
                'errors' => [$throwable->getMessage()],
            ]), false);
        }
    }

    /**
     * @return list<string>
     */
    private function requiredSlugs(): array
    {
        $raw = trim((string) $this->option('slugs'));
        if ($raw === '') {
            throw new RuntimeException('--slugs is required and must be an explicit comma-separated allowlist.');
        }

        $parts = array_values(array_filter(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            explode(',', $raw),
        ), static fn (string $slug): bool => $slug !== ''));

        if ($parts === []) {
            throw new RuntimeException('--slugs is required and must include at least one slug.');
        }

        if (count($parts) !== count(array_unique($parts))) {
            throw new RuntimeException('--slugs must not include duplicates.');
        }

        $unsupported = array_values(array_filter(
            $parts,
            static fn (string $slug): bool => ! in_array($slug, self::AFFECTED_SLUGS, true),
        ));

        if ($unsupported !== []) {
            throw new RuntimeException('Unsupported slug(s) for legacy display asset normalization: '.implode(', ', $unsupported).'.');
        }

        return $parts;
    }

    /**
     * @param  list<string>  $slugs
     * @return array<string, CareerJobDisplayAsset>
     */
    private function assetsBySlug(array $slugs): array
    {
        return CareerJobDisplayAsset::query()
            ->whereIn('canonical_slug', $slugs)
            ->where('asset_version', self::ASSET_VERSION)
            ->get()
            ->keyBy('canonical_slug')
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function planItem(CareerJobDisplayAsset $asset): array
    {
        $slug = (string) $asset->canonical_slug;
        $beforePage = $this->arrayValue($asset->page_payload_json);
        $afterPage = $beforePage;
        $beforeMetadata = $this->arrayValue($asset->metadata_json);
        $afterMetadata = $beforeMetadata;
        $patches = [];
        $errors = [];
        $actorShapeNormalized = false;
        $releaseGatesRemoved = 0;
        $lineageBackfilled = false;
        $lineageHold = ! array_key_exists($slug, self::LINEAGE_SAFE_ROWS);

        if ($slug === 'actors') {
            [$afterPage, $actorShapeNormalized, $shapeErrors] = $this->normalizeActorsPage($beforePage);
            $errors = array_merge($errors, $shapeErrors);
            if ($actorShapeNormalized) {
                $patches[] = [
                    'path' => '$.page_payload_json',
                    'operation' => 'move_top_level_locales_to_page',
                    'before_shape' => array_keys($beforePage),
                    'after_shape' => array_keys($afterPage),
                    'before_hash' => $this->hash($beforePage),
                    'after_hash' => $this->hash($afterPage),
                ];
            }
        }

        if (in_array($slug, self::RELEASE_GATE_SLUGS, true)) {
            [$afterPage, $removed, $releaseGateErrors, $releaseGatePatches] = $this->stripReleaseGates($afterPage);
            $releaseGatesRemoved = $removed;
            $errors = array_merge($errors, $releaseGateErrors);
            $patches = array_merge($patches, $releaseGatePatches);
        }

        if (array_key_exists($slug, self::LINEAGE_SAFE_ROWS) && ! is_string(data_get($afterMetadata, 'row_fingerprint'))) {
            $afterMetadata['row_number'] = self::LINEAGE_SAFE_ROWS[$slug];
            $afterMetadata['row_fingerprint'] = $this->rowFingerprint($slug, self::LINEAGE_SAFE_ROWS[$slug], $asset, $afterPage);
            $lineageBackfilled = true;
            $patches[] = [
                'path' => '$.metadata_json.row_fingerprint',
                'operation' => 'add_verified_row_fingerprint',
                'source_row_number' => self::LINEAGE_SAFE_ROWS[$slug],
                'after_hash' => $this->hash($afterMetadata['row_fingerprint']),
            ];
        }

        $metadataReleaseGates = data_get($afterMetadata, 'release_gates');
        if (is_array($metadataReleaseGates)) {
            $truthy = array_filter($metadataReleaseGates, static fn (mixed $flag): bool => $flag !== false);
            if ($truthy !== []) {
                $errors[] = 'Internal metadata release_gates must remain false-valued.';
            }
        }

        $publicPayload = $this->publicPayload($asset, $afterPage);
        $forbidden = $this->forbiddenPublicKeys($publicPayload);
        if ($forbidden !== []) {
            $errors[] = 'Forbidden public payload keys remain after normalization: '.implode(', ', $forbidden).'.';
        }

        if ($this->containsProduct($publicPayload)) {
            $errors[] = 'Product schema remains in public payload after normalization.';
        }

        $wouldUpdate = $errors === [] && (
            $this->hash($beforePage) !== $this->hash($afterPage)
            || $this->hash($beforeMetadata) !== $this->hash($afterMetadata)
        );

        return [
            'slug' => $slug,
            'row_id' => $asset->id,
            'would_update' => $wouldUpdate,
            'actor_shape_normalized' => $actorShapeNormalized,
            'release_gates_removed_count' => $releaseGatesRemoved,
            'lineage_backfilled' => $lineageBackfilled,
            'lineage_hold' => $lineageHold && ! $lineageBackfilled,
            'public_payload_forbidden_keys_found' => $forbidden,
            'Product_absent' => ! $this->containsProduct($publicPayload),
            'patches' => $patches,
            'before' => [
                'page_payload_json' => $beforePage,
                'metadata_json' => $beforeMetadata,
                'guard_hashes' => $this->guardHashes($asset),
            ],
            'after' => [
                'page_payload_json' => $afterPage,
                'metadata_json' => $afterMetadata,
                'guard_hashes' => $this->guardHashes($asset),
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array{array<string, mixed>, bool, list<string>}
     */
    private function normalizeActorsPage(array $page): array
    {
        if (is_array(data_get($page, 'page.en')) && is_array(data_get($page, 'page.zh'))) {
            return [$page, false, []];
        }

        if (! is_array($page['en'] ?? null) || ! is_array($page['zh'] ?? null)) {
            return [$page, false, ['Actors page payload must contain top-level en and zh arrays or page.en/page.zh arrays.']];
        }

        $unknownTopLevel = array_values(array_diff(array_keys($page), ['en', 'zh']));
        if ($unknownTopLevel !== []) {
            return [$page, false, ['Actors page payload has unexpected top-level keys: '.implode(', ', $unknownTopLevel).'.']];
        }

        return [[
            'page' => [
                'en' => $page['en'],
                'zh' => $page['zh'],
            ],
        ], true, []];
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array{array<string, mixed>, int, list<string>, list<array<string, mixed>>}
     */
    private function stripReleaseGates(array $page): array
    {
        $errors = [];
        $patches = [];
        $removed = 0;

        foreach (['en', 'zh'] as $locale) {
            $path = "page.{$locale}.boundary_notice.release_gates";
            if (! data_get($page, $path)) {
                continue;
            }

            $value = data_get($page, $path);
            if (! is_array($value)) {
                $errors[] = "{$path} must be an array before it can be removed.";

                continue;
            }

            $truthy = array_filter($value, static fn (mixed $flag): bool => $flag !== false);
            if ($truthy !== []) {
                $errors[] = "{$path} contains non-false values and cannot be removed by this normalizer.";

                continue;
            }

            data_forget($page, $path);
            $removed++;
            $patches[] = [
                'path' => '$.page_payload_json.'.str_replace('.', '.', $path),
                'operation' => 'remove_false_release_gates',
                'before' => $value,
                'after' => null,
            ];
        }

        return [$page, $removed, $errors, $patches];
    }

    /**
     * @return array<string, int>
     */
    private function summarize(array $items): array
    {
        return [
            'would_update_count' => count(array_filter($items, static fn (array $item): bool => ($item['would_update'] ?? false) === true)),
            'updated_count' => 0,
            'skipped_count' => count(array_filter($items, static fn (array $item): bool => ($item['would_update'] ?? false) !== true)),
            'lineage_backfilled_count' => count(array_filter($items, static fn (array $item): bool => ($item['lineage_backfilled'] ?? false) === true)),
            'lineage_hold_count' => count(array_filter($items, static fn (array $item): bool => ($item['lineage_hold'] ?? false) === true)),
            'release_gates_removed_count' => array_sum(array_map(static fn (array $item): int => (int) ($item['release_gates_removed_count'] ?? 0), $items)),
            'actor_shape_normalized_count' => count(array_filter($items, static fn (array $item): bool => ($item['actor_shape_normalized'] ?? false) === true)),
            'failed_count' => count(array_filter($items, static fn (array $item): bool => ($item['errors'] ?? []) !== [])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseReport(): array
    {
        return [
            'command' => self::COMMAND_NAME,
            'validator_version' => self::VALIDATOR_VERSION,
            'mode' => 'dry_run',
            'read_only' => true,
            'writes_database' => false,
            'requested_slugs' => [],
            'validated_count' => 0,
            'items' => [],
            'would_update_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'lineage_backfilled_count' => 0,
            'lineage_hold_count' => 0,
            'release_gates_removed_count' => 0,
            'actor_shape_normalized_count' => 0,
            'did_write' => false,
            'release_gates_changed' => false,
            'decision' => 'fail',
            'backup_path' => null,
            'local_db_unavailable' => false,
            'blocking_reasons' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function finish(array $report, bool $success): int
    {
        if (($report['mode'] ?? null) === 'force') {
            $report['read_only'] = false;
            $report['writes_database'] = $success && (int) ($report['updated_count'] ?? 0) > 0;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($this->outputReport($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('mode='.$report['mode']);
            $this->line('validated_count='.(string) $report['validated_count']);
            $this->line('would_update_count='.(string) $report['would_update_count']);
            $this->line('updated_count='.(string) $report['updated_count']);
            $this->line('failed_count='.(string) ($report['failed_count'] ?? 0));
            $this->line('decision='.$report['decision']);
            if (isset($report['errors'])) {
                $this->line('errors='.json_encode($report['errors'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }

        return $success ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function outputReport(array $report): array
    {
        if (! isset($report['items']) || ! is_array($report['items'])) {
            return $report;
        }

        $report['items'] = array_map(static function (array $item): array {
            unset($item['before'], $item['after']);

            return $item;
        }, $report['items']);

        return $report;
    }

    private function backupPath(): ?string
    {
        $path = trim((string) ($this->option('backup-path') ?? ''));

        return $path === '' ? null : $path;
    }

    /**
     * @param  list<CareerJobDisplayAsset>  $assets
     */
    private function writeBackup(string $path, array $assets): void
    {
        $directory = dirname($path);
        if (! is_dir($directory)) {
            throw new RuntimeException('Backup directory does not exist: '.$directory);
        }

        $backup = array_map(static fn (CareerJobDisplayAsset $asset): array => [
            'id' => $asset->id,
            'canonical_slug' => $asset->canonical_slug,
            'asset_version' => $asset->asset_version,
            'surface_version' => $asset->surface_version,
            'template_version' => $asset->template_version,
            'asset_type' => $asset->asset_type,
            'asset_role' => $asset->asset_role,
            'status' => $asset->status,
            'component_order_json' => $asset->component_order_json,
            'page_payload_json' => $asset->page_payload_json,
            'seo_payload_json' => $asset->seo_payload_json,
            'sources_json' => $asset->sources_json,
            'structured_data_json' => $asset->structured_data_json,
            'implementation_contract_json' => $asset->implementation_contract_json,
            'metadata_json' => $asset->metadata_json,
        ], $assets);

        file_put_contents($path, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array<string, mixed>
     */
    private function arrayValue(?array $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicPayload(CareerJobDisplayAsset $asset, array $pagePayload): array
    {
        return [
            'component_order_json' => $asset->component_order_json,
            'page_payload_json' => $pagePayload,
            'seo_payload_json' => $asset->seo_payload_json,
            'sources_json' => $asset->sources_json,
            'structured_data_json' => $asset->structured_data_json,
            'implementation_contract_json' => $asset->implementation_contract_json,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function forbiddenPublicKeys(array $payload): array
    {
        $found = [];
        $walk = function (mixed $value, string $path) use (&$walk, &$found): void {
            if (! is_array($value)) {
                return;
            }

            foreach ($value as $key => $child) {
                $childPath = $path === '' ? (string) $key : $path.'.'.(string) $key;
                if (in_array((string) $key, self::FORBIDDEN_PUBLIC_KEYS, true)) {
                    $found[] = $childPath;
                }
                $walk($child, $childPath);
            }
        };

        foreach (self::PUBLIC_PAYLOAD_COLUMNS as $column) {
            $walk($payload[$column] ?? null, $column);
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function containsProduct(array $payload): bool
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) && str_contains($encoded, '"@type":"Product"');
    }

    /**
     * @return array<string, string|null>
     */
    private function guardHashes(CareerJobDisplayAsset $asset): array
    {
        return [
            'component_order_json' => $this->hash($asset->component_order_json),
            'seo_payload_json' => $this->hash($asset->seo_payload_json),
            'sources_json' => $this->hash($asset->sources_json),
            'structured_data_json' => $this->hash($asset->structured_data_json),
            'implementation_contract_json' => $this->hash($asset->implementation_contract_json),
            'status' => (string) $asset->status,
            'asset_version' => (string) $asset->asset_version,
            'template_version' => (string) $asset->template_version,
            'surface_version' => (string) $asset->surface_version,
            'asset_type' => (string) $asset->asset_type,
        ];
    }

    private function rowFingerprint(string $slug, int $rowNumber, CareerJobDisplayAsset $asset, array $pagePayload): string
    {
        $payload = [
            'slug' => $slug,
            'row_number' => $rowNumber,
            'payload_summary' => [
                'component_order_count' => is_array($asset->component_order_json) ? count($asset->component_order_json) : 0,
                'has_zh_page' => is_array(data_get($pagePayload, 'page.zh')),
                'has_en_page' => is_array(data_get($pagePayload, 'page.en')),
                'public_payload_forbidden_keys_found' => $this->forbiddenPublicKeys($this->publicPayload($asset, $pagePayload)),
                'release_gates' => [
                    'sitemap' => false,
                    'llms' => false,
                    'paid' => false,
                    'backlink' => false,
                ],
            ],
            'payload' => $this->publicPayload($asset, $pagePayload),
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($encoded) ? $encoded : serialize($payload));
    }

    private function hash(mixed $value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($encoded) ? $encoded : serialize($value));
    }
}
