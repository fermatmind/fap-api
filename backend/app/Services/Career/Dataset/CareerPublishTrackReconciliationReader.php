<?php

declare(strict_types=1);

namespace App\Services\Career\Dataset;

use RuntimeException;

final class CareerPublishTrackReconciliationReader
{
    /**
     * @var array<string, true>
     */
    private const ALLOWED_TRACKS = [
        'stable' => true,
        'candidate' => true,
        'review_needed' => true,
        'hold' => true,
    ];

    public function defaultPath(): string
    {
        return base_path('docs/career/publish_track_reconciliation.json');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function bySlug(?string $path = null): array
    {
        $resolved = $path === null || trim($path) === ''
            ? $this->defaultPath()
            : (str_starts_with($path, '/') ? $path : base_path($path));

        if (! is_file($resolved)) {
            throw new RuntimeException(sprintf('Career publish-track reconciliation not found at [%s].', $resolved));
        }

        $decoded = json_decode((string) file_get_contents($resolved), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Career publish-track reconciliation is not valid JSON: [%s].', $resolved));
        }

        foreach (['schema_version', 'authority_kind', 'count_expected', 'count_actual', 'items'] as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw new RuntimeException(sprintf('Career publish-track reconciliation missing field [%s].', $field));
            }
        }

        if (($decoded['authority_kind'] ?? null) !== 'career_publish_track_reconciliation') {
            throw new RuntimeException('Career publish-track reconciliation authority_kind is invalid.');
        }

        $items = $decoded['items'];
        if (! is_array($items)) {
            throw new RuntimeException('Career publish-track reconciliation items must be an array.');
        }

        $expected = (int) $decoded['count_expected'];
        $actual = (int) $decoded['count_actual'];
        if ($expected !== $actual || $actual !== count($items)) {
            throw new RuntimeException('Career publish-track reconciliation count metadata must match items length.');
        }

        $bySlug = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new RuntimeException(sprintf('Career publish-track reconciliation item [%d] is invalid.', $index));
            }

            foreach (['canonical_slug', 'publish_track', 'reason', 'evidence_refs'] as $field) {
                if (! array_key_exists($field, $item)) {
                    throw new RuntimeException(sprintf('Career publish-track reconciliation item [%d] missing [%s].', $index, $field));
                }
            }

            $slug = strtolower(trim((string) $item['canonical_slug']));
            $track = trim((string) $item['publish_track']);
            if ($slug === '' || isset($bySlug[$slug])) {
                throw new RuntimeException(sprintf('Career publish-track reconciliation contains blank or duplicate slug [%s].', $slug));
            }
            if (! isset(self::ALLOWED_TRACKS[$track])) {
                throw new RuntimeException(sprintf('Career publish-track reconciliation has unsupported track [%s].', $track));
            }
            if (trim((string) $item['reason']) === '' || ! is_array($item['evidence_refs']) || $item['evidence_refs'] === []) {
                throw new RuntimeException(sprintf('Career publish-track reconciliation evidence is incomplete for [%s].', $slug));
            }

            $bySlug[$slug] = $item;
        }

        ksort($bySlug, SORT_STRING);

        return $bySlug;
    }
}
