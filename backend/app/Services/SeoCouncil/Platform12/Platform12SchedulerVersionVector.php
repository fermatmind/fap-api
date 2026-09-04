<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12;

use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use InvalidArgumentException;

final readonly class Platform12SchedulerVersionVector
{
    private const FIELDS = [
        'catalog_hash',
        'policy_hash',
        'role_hash',
        'tool_hash',
        'schema_hash',
        'evidence_hash',
    ];

    public function __construct(private SeoRegistryHasher $hasher) {}

    /** @param array<string, mixed> $vector */
    public function hash(array $vector): string
    {
        return $this->hasher->hash($this->normalize($vector));
    }

    /**
     * @param  array<string, mixed>  $vector
     * @return array<string, string>
     */
    public function normalize(array $vector): array
    {
        $keys = array_keys($vector);
        $expected = self::FIELDS;
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('SCHEDULER_VERSION_VECTOR_INVALID');
        }

        $normalized = [];
        foreach (self::FIELDS as $field) {
            $value = strtolower(trim((string) $vector[$field]));
            if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
                throw new InvalidArgumentException('SCHEDULER_VERSION_VECTOR_INVALID');
            }
            $normalized[$field] = $value;
        }

        return $normalized;
    }
}
