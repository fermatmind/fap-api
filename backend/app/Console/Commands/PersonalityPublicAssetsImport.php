<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\DTO\Personality\PersonalityPublicContentAssetData;
use App\Models\PersonalityPublicContentAsset;
use App\Services\Cms\PersonalityPublicContentAssetContract;
use Illuminate\Console\Command;
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

    public function handle(PersonalityPublicContentAssetContract $contract): int
    {
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
                $attributes = $asset->toModelAttributes();
                $summary['indexable_count'] += $asset->indexEligible ? 1 : 0;
                $summary['sitemap_eligible_count'] += $asset->sitemapEligible ? 1 : 0;
                $summary['llms_eligible_count'] += $asset->llmsEligible ? 1 : 0;

                if (! $schemaReady) {
                    $summary['will_create']++;

                    continue;
                }

                $existing = $this->findExisting($asset);

                if (! $existing instanceof PersonalityPublicContentAsset) {
                    $summary['will_create']++;
                    if ($writeMode) {
                        PersonalityPublicContentAsset::query()->create($attributes);
                    }

                    continue;
                }

                if ($this->attributesMatch($existing, $attributes)) {
                    $summary['will_skip']++;

                    continue;
                }

                $summary['will_update']++;
                if ($writeMode) {
                    $existing->fill($attributes);
                    $existing->save();
                }
            }

            foreach ($summary as $key => $value) {
                $this->line($key.'='.(is_bool($value) ? ($value ? '1' : '0') : (string) $value));
            }

            if ($errors !== []) {
                $this->line('validation_errors='.json_encode($errors, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return 1;
            }

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
        $filtered = [];
        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $framework = PersonalityPublicContentAsset::normalizeToken((string) ($asset['framework'] ?? ''));
            if ($selectedFrameworks !== [] && ! in_array($framework, $selectedFrameworks, true)) {
                continue;
            }

            $asset['source_package'] = trim((string) ($asset['source_package'] ?? $sourcePackage));
            $asset['source_hash'] = trim((string) ($asset['source_hash'] ?? $sourceHash));
            $filtered[] = $asset;
        }

        return $filtered;
    }

    private function findExisting(PersonalityPublicContentAssetData $asset): ?PersonalityPublicContentAsset
    {
        return PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', $asset->orgId)
            ->where('framework', $asset->framework)
            ->where('entity_type', $asset->entityType)
            ->where('entity_key', $asset->entityKey)
            ->where('locale', $asset->locale)
            ->first();
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
