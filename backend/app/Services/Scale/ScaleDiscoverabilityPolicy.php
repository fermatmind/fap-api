<?php

declare(strict_types=1);

namespace App\Services\Scale;

final class ScaleDiscoverabilityPolicy
{
    /**
     * Public assessment access remains available, but search discoverability stays
     * held until a separate clinical indexability review explicitly releases it.
     *
     * @var list<string>
     */
    private const HELD_SCALE_CODES = [
        'CLINICAL_COMBO_68',
        'SDS_20',
    ];

    /**
     * @param  array<string, mixed>  $row
     */
    public function isIndexable(array $row): bool
    {
        if ($this->isClinicalScreeningHold($row)) {
            return false;
        }

        $registryIndexable = null;
        if (array_key_exists('is_indexable', $row) && $row['is_indexable'] !== null) {
            $registryIndexable = (bool) $row['is_indexable'];
            if (! $registryIndexable) {
                return false;
            }
        }

        $policy = $this->toArray($row['view_policy_json'] ?? null);
        if (array_key_exists('indexable', $policy)) {
            if (! (bool) $policy['indexable']) {
                return false;
            }
        }

        $robots = strtolower(trim((string) ($policy['robots'] ?? '')));
        if ($robots !== '' && str_contains($robots, 'noindex')) {
            return false;
        }

        return $registryIndexable ?? true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function isPubliclyDiscoverable(array $row): bool
    {
        if (! (bool) ($row['is_public'] ?? true) || ! (bool) ($row['is_active'] ?? true)) {
            return false;
        }

        if (! $this->isIndexable($row)) {
            return false;
        }

        $policy = $this->toArray($row['view_policy_json'] ?? null);
        $visibility = $policy['public'] ?? $policy['is_public'] ?? $policy['visibility'] ?? null;

        if (is_bool($visibility)) {
            return $visibility;
        }

        if (is_string($visibility)) {
            return ! in_array(strtolower(trim($visibility)), ['private', 'internal', 'hidden'], true);
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function isClinicalScreeningHold(array $row): bool
    {
        $scaleCode = strtoupper(trim((string) ($row['code'] ?? $row['scale_code'] ?? '')));

        return in_array($scaleCode, self::HELD_SCALE_CODES, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }
}
