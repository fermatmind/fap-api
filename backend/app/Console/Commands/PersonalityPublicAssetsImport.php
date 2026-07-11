<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTO\Personality\PersonalityPublicContentAssetData;
use App\Models\PersonalityPublicContentAsset;
use App\Services\Cms\PersonalityPublicContentAssetContract;
use App\Services\SEO\SeoDiscoverabilityCacheInvalidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class PersonalityPublicAssetsImport extends Command
{
    protected $signature = 'personality-public-assets:import
        {--source=content_assets/personality_public/big_five_v1_seed.json : JSON content asset package}
        {--framework=* : Limit import to one or more frameworks}
        {--write : Write validated assets to the database}
        {--allow-indexable : Permit index_eligible/sitemap_eligible/llms_eligible assets in this import}';

    protected $description = 'Validate and optionally import public Big Five / Enneagram personality content asset contracts.';

    public function handle(
        PersonalityPublicContentAssetContract $contract,
        SeoDiscoverabilityCacheInvalidator $cacheInvalidator,
    ): int {
        try {
            $sourcePath = $this->resolveSourcePath((string) $this->option('source'));
            $payload = $this->readPayload($sourcePath);
            $sourceHash = sha1((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $selectedFrameworks = $this->selectedFrameworks();
            $assets = $this->filterAssets(
                is_array($payload['assets'] ?? null) ? $payload['assets'] : [],
                $selectedFrameworks,
                (string) ($payload['package'] ?? basename($sourcePath)),
                $sourceHash,
            );

            $result = $contract->validateMany($assets);
            /** @var list<PersonalityPublicContentAssetData> $valid */
            $valid = $result['valid'];
            $errors = $result['errors'];

            if (! (bool) $this->option('allow-indexable')) {
                foreach ($valid as $asset) {
                    if ($asset->indexEligible || $asset->sitemapEligible || $asset->llmsEligible) {
                        throw new RuntimeException(
                            'Refusing indexable personality content asset import without --allow-indexable.'
                        );
                    }
                }
            }

            $summary = [
                'source' => $sourcePath,
                'package' => (string) ($payload['package'] ?? ''),
                'dry_run' => ! (bool) $this->option('write'),
                'assets_found' => count($assets),
                'valid_count' => count($valid),
                'errors_count' => count($errors),
                'will_create' => 0,
                'will_update' => 0,
                'will_skip' => 0,
                'indexable_count' => 0,
                'sitemap_eligible_count' => 0,
                'llms_eligible_count' => 0,
            ];
            $writeMode = (bool) $this->option('write');
            $schemaReady = Schema::hasTable((new PersonalityPublicContentAsset)->getTable());

            if ($writeMode && ! $schemaReady) {
                throw new RuntimeException('personality_public_content_assets table is missing; run migrations before --write.');
            }

            foreach ($valid as $asset) {
                $summary['indexable_count'] += $asset->indexEligible ? 1 : 0;
                $summary['sitemap_eligible_count'] += $asset->sitemapEligible ? 1 : 0;
                $summary['llms_eligible_count'] += $asset->llmsEligible ? 1 : 0;
            }

            if ($errors !== []) {
                $this->printSummary($summary);
                $this->line('validation_errors='.json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return 1;
            }

            $plan = $writeMode
                ? DB::transaction(function () use ($valid): array {
                    $lockedPlan = $this->buildPersistencePlan($valid, true, true);
                    $this->applyPersistencePlan($lockedPlan);

                    return $lockedPlan;
                })
                : $this->buildPersistencePlan($valid, $schemaReady, false);

            foreach ($plan as $operation) {
                $summary['will_'.$operation['operation']]++;
            }
            if ($writeMode) {
                $summary['discoverability_cache_keys_flushed'] = implode(
                    ',',
                    $cacheInvalidator->flushPersonalityPublicContentDiscoverabilityCaches(),
                );
            }
            $this->printSummary($summary);

            $this->info($writeMode ? 'import complete' : 'dry-run complete');

            return 0;
        } catch (\Throwable $throwable) {
            $this->error($throwable->getMessage());

            return 1;
        }
    }

    private function resolveSourcePath(string $path): string
    {
        $normalized = trim($path);
        if ($normalized === '') {
            throw new RuntimeException('Missing --source path.');
        }

        $resolved = str_starts_with($normalized, '/')
            ? $normalized
            : base_path($normalized);

        if (! File::isFile($resolved)) {
            throw new RuntimeException('Source file not found: '.$resolved);
        }

        return $resolved;
    }

    /**
     * @return array<string,mixed>
     */
    private function readPayload(string $sourcePath): array
    {
        $decoded = json_decode((string) File::get($sourcePath), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Source file must contain a JSON object.');
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function selectedFrameworks(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $value): string => PersonalityPublicContentAsset::normalizeToken($value),
            (array) $this->option('framework')
        )));
    }

    /**
     * @param  array<int,mixed>  $assets
     * @param  list<string>  $selectedFrameworks
     * @return list<array<string,mixed>>
     */
    private function filterAssets(array $assets, array $selectedFrameworks, string $sourcePackage, string $sourceHash): array
    {
        $canonicalFacetDetailPersistenceKeys = [];
        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            if (($asset['framework'] ?? null) !== PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE
                || ($asset['entity_type'] ?? null) !== PersonalityPublicContentAsset::ENTITY_FACET_DETAIL) {
                continue;
            }

            $canonicalFacetDetailPersistenceKeys[$this->persistenceKey($asset)] = true;
        }

        $filtered = [];
        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $framework = PersonalityPublicContentAsset::normalizeToken((string) ($asset['framework'] ?? ''));
            if ($selectedFrameworks !== [] && ! in_array($framework, $selectedFrameworks, true)) {
                continue;
            }

            $entityType = (string) ($asset['entity_type'] ?? '');
            if ($framework === PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE
                && $entityType === PersonalityPublicContentAsset::ENTITY_FACET
                && isset($canonicalFacetDetailPersistenceKeys[$this->persistenceKey($asset)])) {
                // The table has a unique persisted slug per framework/locale. facet_detail is the
                // current canonical route entity; the legacy facet stub cannot coexist with it.
                continue;
            }

            $asset['source_package'] = trim((string) ($asset['source_package'] ?? $sourcePackage));
            $asset['source_hash'] = trim((string) ($asset['source_hash'] ?? $sourceHash));
            $filtered[] = $asset;
        }

        return $filtered;
    }

    /**
     * @param  array<string,mixed>  $asset
     */
    private function persistenceKey(array $asset): string
    {
        return implode('|', [
            max(0, (int) ($asset['org_id'] ?? 0)),
            PersonalityPublicContentAsset::normalizeToken((string) ($asset['framework'] ?? '')),
            PersonalityPublicContentAsset::normalizeSlug((string) ($asset['slug'] ?? '')),
            PersonalityPublicContentAsset::normalizeLocale((string) ($asset['locale'] ?? 'en')),
        ]);
    }

    /**
     * @param  list<PersonalityPublicContentAssetData>  $assets
     * @return list<array{attributes:array<string,mixed>,operation:'create'|'update'|'skip',existing:?PersonalityPublicContentAsset}>
     */
    private function buildPersistencePlan(array $assets, bool $schemaReady, bool $lockForUpdate): array
    {
        $incomingIdentities = [];
        $incomingSlugs = [];

        foreach ($assets as $asset) {
            $identity = $this->assetIdentity($asset);
            $slugIdentity = $this->assetSlugIdentity($asset);

            if (isset($incomingIdentities[$identity])) {
                throw new RuntimeException('Duplicate incoming personality asset identity: '.$identity);
            }

            if (isset($incomingSlugs[$slugIdentity])) {
                throw new RuntimeException(sprintf(
                    'Incoming personality assets share persisted slug identity %s: %s conflicts with %s.',
                    $slugIdentity,
                    $identity,
                    $incomingSlugs[$slugIdentity],
                ));
            }

            $incomingIdentities[$identity] = true;
            $incomingSlugs[$slugIdentity] = $identity;
        }

        $plan = [];
        foreach ($assets as $asset) {
            $attributes = $asset->toModelAttributes();
            if (! $schemaReady) {
                $plan[] = [
                    'attributes' => $attributes,
                    'operation' => 'create',
                    'existing' => null,
                ];

                continue;
            }

            $existingQuery = PersonalityPublicContentAsset::query()
                ->withoutGlobalScopes()
                ->where('org_id', $asset->orgId)
                ->where('framework', $asset->framework)
                ->where('entity_type', $asset->entityType)
                ->where('entity_key', $asset->entityKey)
                ->where('locale', $asset->locale);
            if ($lockForUpdate) {
                $existingQuery->lockForUpdate();
            }
            $existing = $existingQuery->first();

            $slugOwnerQuery = PersonalityPublicContentAsset::query()
                ->withoutGlobalScopes()
                ->where('org_id', $asset->orgId)
                ->where('framework', $asset->framework)
                ->where('slug', $asset->slug)
                ->where('locale', $asset->locale);
            if ($lockForUpdate) {
                $slugOwnerQuery->lockForUpdate();
            }
            $slugOwner = $slugOwnerQuery->first();

            if ($slugOwner instanceof PersonalityPublicContentAsset
                && (! $existing instanceof PersonalityPublicContentAsset || $slugOwner->getKey() !== $existing->getKey())) {
                throw new RuntimeException(sprintf(
                    'Persistence slug conflict for %s: incoming identity %s conflicts with persisted identity %s.',
                    $this->assetSlugIdentity($asset),
                    $this->assetIdentity($asset),
                    $this->modelIdentity($slugOwner),
                ));
            }

            $operation = ! $existing instanceof PersonalityPublicContentAsset
                ? 'create'
                : ($this->attributesMatch($existing, $attributes) ? 'skip' : 'update');
            $plan[] = [
                'attributes' => $attributes,
                'operation' => $operation,
                'existing' => $existing,
            ];
        }

        return $plan;
    }

    /**
     * @param  list<array{attributes:array<string,mixed>,operation:'create'|'update'|'skip',existing:?PersonalityPublicContentAsset}>  $plan
     */
    private function applyPersistencePlan(array $plan): void
    {
        foreach ($plan as $operation) {
            if ($operation['operation'] === 'create') {
                PersonalityPublicContentAsset::query()->create($operation['attributes']);

                continue;
            }

            if ($operation['operation'] === 'update') {
                $existing = $operation['existing'];
                if (! $existing instanceof PersonalityPublicContentAsset) {
                    throw new RuntimeException('Atomic personality asset import lost its update target.');
                }
                $existing->fill($operation['attributes']);
                $existing->save();
            }
        }
    }

    private function assetIdentity(PersonalityPublicContentAssetData $asset): string
    {
        return implode('|', [
            $asset->orgId,
            $asset->framework,
            $asset->entityType,
            $asset->entityKey,
            $asset->locale,
        ]);
    }

    private function assetSlugIdentity(PersonalityPublicContentAssetData $asset): string
    {
        return implode('|', [
            $asset->orgId,
            $asset->framework,
            $asset->slug,
            $asset->locale,
        ]);
    }

    private function modelIdentity(PersonalityPublicContentAsset $asset): string
    {
        return implode('|', [
            (int) $asset->org_id,
            (string) $asset->framework,
            (string) $asset->entity_type,
            (string) $asset->entity_key,
            (string) $asset->locale,
        ]);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function printSummary(array $summary): void
    {
        foreach ($summary as $key => $value) {
            $this->line($key.'='.(is_bool($value) ? ($value ? '1' : '0') : (string) $value));
        }
    }

    /**
     * @param  array<string,mixed>  $attributes
     */
    private function attributesMatch(PersonalityPublicContentAsset $existing, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            if ($this->comparable($existing->{$key}) !== $this->comparable($value)) {
                return false;
            }
        }

        return true;
    }

    private function comparable(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sP');
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $value) === 1) {
            return (new \DateTimeImmutable($value))->format('Y-m-d\TH:i:sP');
        }

        if (is_array($value)) {
            $this->sortAssociativeRecursive($value);

            return $value;
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function sortAssociativeRecursive(array &$value): void
    {
        foreach ($value as &$child) {
            if (is_array($child)) {
                $this->sortAssociativeRecursive($child);
            }
        }

        if (array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }
    }
}
