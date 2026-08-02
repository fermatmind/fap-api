<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use DomainException;

/**
 * Deterministic, exact target identity set for one promotion adapter phase.
 *
 * @phpstan-type TargetIdentity array<string, int|string>
 */
final readonly class PromotionTargetSet
{
    /** @var list<array<string, int|string>> */
    private array $identities;

    /** @param list<array<string, int|string>> $identities */
    private function __construct(array $identities)
    {
        if ($identities === []) {
            throw new DomainException('promotion_target_set_empty');
        }

        $normalized = [];
        foreach ($identities as $identity) {
            if ($identity === []) {
                throw new DomainException('promotion_target_identity_invalid');
            }
            ksort($identity, SORT_STRING);
            foreach ($identity as $key => $value) {
                if (! is_string($key) || $key === '' || ! is_int($value) && ! is_string($value)) {
                    throw new DomainException('promotion_target_identity_invalid');
                }
            }
            $canonical = PromotionContextFactory::canonicalJson($identity);
            if (isset($normalized[$canonical])) {
                throw new DomainException('promotion_target_identity_duplicate');
            }
            $normalized[$canonical] = $identity;
        }
        ksort($normalized, SORT_STRING);
        $this->identities = array_values($normalized);
    }

    /** @param list<array<string, int|string>> $identities */
    public static function fromIdentities(array $identities): self
    {
        return new self($identities);
    }

    public function count(): int
    {
        return count($this->identities);
    }

    public function assertExpectedCount(int $expectedCount): void
    {
        if ($this->count() !== $expectedCount) {
            throw new DomainException('promotion_target_count_mismatch');
        }
    }

    /** @return list<array<string, int|string>> */
    public function identities(): array
    {
        return $this->identities;
    }

    public function fingerprint(): string
    {
        return hash('sha256', PromotionContextFactory::canonicalJson([
            'schema_version' => 'fermatmind.content_promotion_target_set.v2',
            'identities' => $this->identities,
        ]));
    }
}
