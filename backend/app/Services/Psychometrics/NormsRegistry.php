<?php

namespace App\Services\Psychometrics;

use App\Services\ContentPackResolver;
use App\Support\Stats\JsonSchemaGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NormsRegistry
{
    public function __construct(private ContentPackResolver $resolver)
    {
    }

    public function resolve(
        string $scaleCode,
        string $region,
        string $locale,
        ?string $dirVersion = null,
        ?array $demographics = null,
        ?string $pinnedVersion = null
    ): array {
        $norms = $this->loadNormsFile($scaleCode, $region, $locale, $dirVersion);
        if (!is_array($norms)) {
            return [
                'ok' => false,
                'error' => 'NORMS_NOT_FOUND',
            ];
        }

        $errors = JsonSchemaGuard::validateNormsFile($norms);
        if (!empty($errors)) {
            return [
                'ok' => false,
                'error' => 'NORMS_INVALID',
                'errors' => $errors,
            ];
        }

        $meta = is_array($norms['meta'] ?? null) ? $norms['meta'] : [];
        $bucketKeys = is_array($meta['bucket_keys'] ?? null) ? $meta['bucket_keys'] : [];

        $selectedVersion = $pinnedVersion
            ?? $this->resolveLatestDbVersion($scaleCode, $region, $locale, (string)($meta['norm_id'] ?? ''))
            ?? (string)($meta['version'] ?? '');

        $bucket = $this->pickBucket($norms, $region, $locale, $demographics ?? []);

        return [
            'ok' => true,
            'norm_id' => (string) ($meta['norm_id'] ?? ''),
            'version' => $selectedVersion,
            'file_version' => (string) ($meta['version'] ?? ''),
            'checksum' => (string) ($meta['checksum'] ?? $this->checksum($norms)),
            'bucket_keys' => $bucketKeys,
            'bucket' => $bucket,
            'meta' => $meta,
        ];
    }

    public function listAvailableNorms(string $scaleCode, ?string $region = null, ?string $locale = null): array
    {
        $region = $region !== null ? $this->normRegion($region) : null;
        $locale = $locale !== null ? $this->normLocale($locale) : null;

        $items = [];

        if (\App\Support\SchemaBaseline::hasTable('scale_norms_versions')) {
            $query = DB::table('scale_norms_versions')->where('scale_code', $scaleCode);
            $rows = $query->orderBy('created_at', 'desc')->get();

            foreach ($rows as $row) {
                $items[] = [
                    'source' => 'db',
                    'scale_code' => (string) $row->scale_code,
                    'norm_id' => (string) ($row->norm_id ?? ''),
                    'region' => (string) ($row->region ?? ''),
                    'locale' => (string) ($row->locale ?? ''),
                    'version' => (string) ($row->version ?? ''),
                    'checksum' => (string) ($row->checksum ?? ''),
                    'created_at' => (string) ($row->created_at ?? ''),
                ];
            }
        }

        $pack = $this->loadNormsFile($scaleCode, $region ?? '', $locale ?? '', null);
        if (is_array($pack)) {
            $meta = is_array($pack['meta'] ?? null) ? $pack['meta'] : [];
            $items[] = [
                'source' => 'pack',
                'scale_code' => $scaleCode,
                'norm_id' => (string) ($meta['norm_id'] ?? ''),
                'region' => (string) ($meta['region'] ?? $region ?? ''),
                'locale' => (string) ($meta['locale'] ?? $locale ?? ''),
                'version' => (string) ($meta['version'] ?? ''),
                'checksum' => (string) ($meta['checksum'] ?? $this->checksum($pack)),
                'bucket_keys' => $meta['bucket_keys'] ?? [],
            ];
        }

        return $items;
    }

    private function loadNormsFile(string $scaleCode, string $region, string $locale, ?string $dirVersion): ?array
    {
        $region = $this->normRegion($region);
        $locale = $this->normLocale($locale);
        $version = $dirVersion ?: (string) config('content_packs.default_dir_version', '');

        try {
            $resolved = $this->resolver->resolve($scaleCode, $region, $locale, $version);
        } catch (\Throwable $e) {
            return null;
        }

        $loader = $resolved->loaders['readJson'] ?? null;
        if (!is_callable($loader)) {
            return null;
        }

        $norms = $loader('norms.json');
        return is_array($norms) ? $norms : null;
    }

    private function pickBucket(array $norms, string $region, string $locale, array $demographics): ?array
    {
        $region = $this->normRegion($region);
        $locale = $this->normLocale($locale);

        $buckets = is_array($norms['buckets'] ?? null) ? $norms['buckets'] : [];
        if (empty($buckets)) {
            return null;
        }

        $query = $demographics;
        $query['region'] = $region;
        $query['locale'] = $locale;

        $best = null;
        $bestScore = -1;

        foreach ($buckets as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }

            $keys = is_array($bucket['keys'] ?? null) ? $bucket['keys'] : [];
            $score = 0;
            $ok = true;

            foreach ($keys as $k => $v) {
                if (!array_key_exists($k, $query)) {
                    $ok = false;
                    break;
                }

                $qv = $query[$k];
                if (is_string($qv)) {
                    $qv = $this->normalizeValue($k, $qv);
                }

                if ((string) $qv !== (string) $v) {
                    $ok = false;
                    break;
                }

                $score += 1;
            }

            if ($ok && $score > $bestScore) {
                $best = $bucket;
                $bestScore = $score;
            }
        }

        if ($best !== null) {
            return $best;
        }

        $meta = is_array($norms['meta'] ?? null) ? $norms['meta'] : [];
        $defaultKeys = is_array($meta['default_bucket'] ?? null) ? $meta['default_bucket'] : [];

        foreach ($buckets as $bucket) {
            if (!is_array($bucket)) {
                continue;
            }
            $keys = is_array($bucket['keys'] ?? null) ? $bucket['keys'] : [];
            if ($keys == $defaultKeys) {
                return $bucket;
            }
        }

        return $buckets[0];
    }

    private function resolveLatestDbVersion(string $scaleCode, string $region, string $locale, string $normId): ?string
    {
        if (!\App\Support\SchemaBaseline::hasTable('scale_norms_versions')) {
            return null;
        }

        $rows = DB::table('scale_norms_versions')
            ->where('scale_code', $scaleCode)
            ->when($normId !== '', fn ($q) => $q->where('norm_id', $normId))
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $region = $this->normRegion($region);
        $locale = $this->normLocale($locale);

        $candidates = [];
        foreach ($rows as $row) {
            $rowRegion = $row->region !== null ? $this->normRegion((string) $row->region) : null;
            $rowLocale = $row->locale !== null ? $this->normLocale((string) $row->locale) : null;

            if ($rowRegion !== null && $rowRegion !== $region) {
                continue;
            }
            if ($rowLocale !== null && $rowLocale !== $locale) {
                continue;
            }

            $specificity = 0;
            if ($rowRegion !== null) {
                $specificity += 1;
            }
            if ($rowLocale !== null) {
                $specificity += 1;
            }

            $candidates[] = [
                'version' => (string) ($row->version ?? ''),
                'specificity' => $specificity,
                'created_at' => (string) ($row->created_at ?? ''),
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function ($a, $b) {
            if ($a['specificity'] !== $b['specificity']) {
                return $b['specificity'] <=> $a['specificity'];
            }
            $vc = version_compare($b['version'], $a['version']);
            if ($vc !== 0) {
                return $vc;
            }
            return strcmp($b['created_at'], $a['created_at']);
        });

        return (string) ($candidates[0]['version'] ?? '');
    }

    private function checksum(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function normalizeValue(string $key, string $value): string
    {
        if ($key === 'region') {
            return $this->normRegion($value);
        }
        if ($key === 'locale') {
            return $this->normLocale($value);
        }

        return (string) $value;
    }

    private function normRegion(string $region): string
    {
        $region = strtoupper(trim($region));
        return str_replace('-', '_', $region);
    }

    private function normLocale(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return '';
        }

        $locale = str_replace('_', '-', $locale);
        $parts = explode('-', $locale);
        if (count($parts) === 1) {
            return strtolower($parts[0]);
        }

        return strtolower($parts[0]) . '-' . strtoupper($parts[1]);
    }
}
