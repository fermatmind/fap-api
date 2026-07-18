<?php

declare(strict_types=1);

namespace App\DTO\ReviewGovernance;

use App\Services\ReviewGovernance\ReviewAttestationCanonicalizer;
use InvalidArgumentException;

final readonly class ReviewTargetSet
{
    /**
     * @param  list<ReviewTarget>  $targets
     */
    private function __construct(
        public array $targets,
        public string $sha256,
    ) {}

    /**
     * @param  list<array<string, mixed>|ReviewTarget>  $targets
     */
    public static function fromArray(array $targets, ReviewAttestationCanonicalizer $canonicalizer): self
    {
        if ($targets === []) {
            throw new InvalidArgumentException('Review target set must not be empty.');
        }

        $byIdentity = [];
        foreach ($targets as $target) {
            $normalized = $target instanceof ReviewTarget ? $target : ReviewTarget::fromArray($target);
            if (isset($byIdentity[$normalized->identity])) {
                throw new InvalidArgumentException('Review target set contains a duplicate identity: '.$normalized->identity.'.');
            }
            $byIdentity[$normalized->identity] = $normalized;
        }

        ksort($byIdentity, SORT_STRING);
        $sorted = array_values($byIdentity);
        $fingerprintPayload = array_map(
            static fn (ReviewTarget $target): array => $target->toArray(),
            $sorted,
        );

        return new self(
            targets: $sorted,
            sha256: hash('sha256', $canonicalizer->encode($fingerprintPayload)),
        );
    }

    public function count(): int
    {
        return count($this->targets);
    }

    /**
     * @return list<string>
     */
    public function identities(): array
    {
        return array_map(
            static fn (ReviewTarget $target): string => $target->identity,
            $this->targets,
        );
    }
}
