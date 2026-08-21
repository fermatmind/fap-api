<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use JsonException;

final class CareerPresentationSourceRegistry
{
    public const CONTRACT_VERSION = 'career.presentation_v1.source_registry.v1';

    public const RELATIVE_PATH = 'content_assets/career/current/presentation-source-registry.json';

    /** @return array<string,mixed> */
    public function load(string $backendRoot, array $manifest): array
    {
        $path = rtrim($backendRoot, '/').'/'.self::RELATIVE_PATH;
        if (! is_file($path) || is_link($path)) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_REGISTRY_MISSING');
        }
        $declared = data_get($manifest, 'presentation_v1.source_registry');
        if (! is_array($declared)
            || ($declared['path'] ?? null) !== 'presentation-source-registry.json'
            || ! is_string($declared['sha256'] ?? null)
            || ! hash_equals((string) $declared['sha256'], (string) hash_file('sha256', $path))) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_REGISTRY_HASH_MISMATCH');
        }
        try {
            $registry = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_REGISTRY_INVALID');
        }
        if (! is_array($registry)
            || ($registry['contract_version'] ?? null) !== self::CONTRACT_VERSION
            || ! is_array($registry['onet_multiple_occupations'] ?? null)
            || ! is_array($registry['bls_projections'] ?? null)
            || count($registry['onet_multiple_occupations']) !== 2
            || count($registry['bls_projections']) !== 5) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_REGISTRY_INVALID');
        }

        $onet = [];
        foreach ($registry['onet_multiple_occupations'] as $record) {
            if (! is_array($record)
                || ! $this->slug($record['canonical_slug'] ?? null)
                || ! $this->soc($record['summary_soc'] ?? null)
                || ($record['scope'] ?? null) !== 'multiple_official_occupations'
                || ! $this->date($record['reviewed_at'] ?? null)
                || ! $this->date($record['effective_at'] ?? null)
                || ! is_array($record['child_occupations'] ?? null)
                || count($record['child_occupations']) !== 2) {
                throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_REGISTRY_INVALID');
            }
            foreach ($record['child_occupations'] as $child) {
                if (! is_array($child)
                    || ! $this->onet($child['code'] ?? null)
                    || ! $this->text($child['title'] ?? null)
                    || ($child['official_url'] ?? null) !== 'https://www.onetonline.org/link/details/'.$child['code']) {
                    throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_REGISTRY_INVALID');
                }
            }
            $slug = (string) $record['canonical_slug'];
            if (isset($onet[$slug])) {
                throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_REGISTRY_INVALID');
            }
            $onet[$slug] = $record;
        }

        $bls = [];
        foreach ($registry['bls_projections'] as $record) {
            if (! is_array($record)
                || ! $this->slug($record['canonical_slug'] ?? null)
                || ! $this->text($record['source_key'] ?? null)
                || ! $this->text($record['title'] ?? null)
                || ! $this->officialUrl($record['official_url'] ?? null)
                || ! in_array($record['source_scope'] ?? null, ['exact', 'combined_official', 'parent_occupation_proxy'], true)
                || ($record['data_year'] ?? null) !== 2024
                || ! $this->date($record['reviewed_at'] ?? null)
                || ! $this->date($record['effective_at'] ?? null)
                || ! $this->date($record['valid_through'] ?? null)
                || ! is_array($record['metrics'] ?? null)
                || array_keys($record['metrics']) !== ['中位年薪', '就业增长', '在岗人数', '年均职位空缺']) {
                throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_REGISTRY_INVALID');
            }
            foreach ($record['metrics'] as $value) {
                if (! $this->text($value)) {
                    throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_REGISTRY_INVALID');
                }
            }
            $slug = (string) $record['canonical_slug'];
            if (isset($bls[$slug])) {
                throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_REGISTRY_INVALID');
            }
            $bls[$slug] = $record;
        }
        if (str_contains(CareerCurrentAuthorityPackage::encodeCanonical($registry), '17-3012.00')) {
            throw new CareerTenBlockCompileFailure('PRESENTATION_V1_SOURCE_REGISTRY_INVALID');
        }

        return ['document' => $registry, 'onet' => $onet, 'bls' => $bls];
    }

    private function slug(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $value) === 1;
    }

    private function soc(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9]{2}-[0-9]{4}\z/', $value) === 1;
    }

    private function onet(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9]{2}-[0-9]{4}\.[0-9]{2}\z/', $value) === 1;
    }

    private function date(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) === 1;
    }

    private function text(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function officialUrl(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, 'https://www.bls.gov/');
    }
}
