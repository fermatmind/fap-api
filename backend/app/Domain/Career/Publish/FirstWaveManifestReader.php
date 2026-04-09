<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use RuntimeException;

final class FirstWaveManifestReader
{
    public function defaultPath(): string
    {
        return base_path('docs/career/first_wave_manifest.json');
    }

    /**
     * @return array{
     *   manifest_version:string,
     *   generated_at:string,
     *   wave_name:string,
     *   selection_policy_version:string,
     *   count_expected:int,
     *   count_actual:int,
     *   occupations:list<array<string,mixed>>
     * }
     */
    public function read(?string $path = null): array
    {
        $resolved = $path === null || trim($path) === ''
            ? $this->defaultPath()
            : (str_starts_with($path, '/') ? $path : base_path($path));

        if (! is_file($resolved)) {
            throw new RuntimeException(sprintf('First-wave manifest not found at [%s].', $resolved));
        }

        $decoded = json_decode((string) file_get_contents($resolved), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('First-wave manifest is not valid JSON: [%s].', $resolved));
        }

        $occupations = $decoded['occupations'] ?? null;
        if (! is_array($occupations)) {
            throw new RuntimeException('First-wave manifest must contain an occupations list.');
        }

        $requiredMetadata = [
            'manifest_version',
            'generated_at',
            'wave_name',
            'selection_policy_version',
            'count_expected',
            'count_actual',
        ];

        foreach ($requiredMetadata as $field) {
            if (($decoded[$field] ?? null) === null || $decoded[$field] === '') {
                throw new RuntimeException(sprintf('First-wave manifest missing metadata field [%s].', $field));
            }
        }

        $expected = (int) $decoded['count_expected'];
        $actual = (int) $decoded['count_actual'];
        if ($expected !== 10 || $actual !== 10 || count($occupations) !== 10) {
            throw new RuntimeException('First-wave manifest must contain exactly 10 occupations.');
        }

        $seenSlugs = [];
        $seenOccupationIds = [];

        foreach ($occupations as $index => $occupation) {
            if (! is_array($occupation)) {
                throw new RuntimeException(sprintf('First-wave manifest occupation at index [%d] is invalid.', $index));
            }

            foreach ([
                'occupation_uuid',
                'canonical_slug',
                'canonical_title_en',
                'family_uuid',
                'crosswalk_mode',
                'wave_classification',
                'publish_reason_codes',
                'trust_seed',
                'reviewer_seed',
                'index_seed',
                'claim_seed',
            ] as $field) {
                if (! array_key_exists($field, $occupation)) {
                    throw new RuntimeException(sprintf('First-wave manifest occupation [%s] missing [%s].', $occupation['canonical_slug'] ?? $index, $field));
                }
            }

            $slug = (string) $occupation['canonical_slug'];
            $occupationId = (string) $occupation['occupation_uuid'];

            if ($slug === '' || $occupationId === '') {
                throw new RuntimeException(sprintf('First-wave manifest occupation at index [%d] contains blank identifiers.', $index));
            }
            if (isset($seenSlugs[$slug])) {
                throw new RuntimeException(sprintf('First-wave manifest contains duplicate slug [%s].', $slug));
            }
            if (isset($seenOccupationIds[$occupationId])) {
                throw new RuntimeException(sprintf('First-wave manifest contains duplicate occupation UUID [%s].', $occupationId));
            }

            $seenSlugs[$slug] = true;
            $seenOccupationIds[$occupationId] = true;
        }

        return $decoded;
    }
}
