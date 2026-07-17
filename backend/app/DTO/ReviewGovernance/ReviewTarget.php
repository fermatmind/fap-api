<?php

declare(strict_types=1);

namespace App\DTO\ReviewGovernance;

use InvalidArgumentException;

final readonly class ReviewTarget
{
    public function __construct(
        public string $identity,
        public string $sha256,
    ) {
        if ($identity === '' || strlen($identity) > 191 || trim($identity) !== $identity) {
            throw new InvalidArgumentException('Review target identity must be a non-empty, trimmed value of at most 191 characters.');
        }
        if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            throw new InvalidArgumentException('Review target SHA-256 must be an exact lowercase hash.');
        }
    }

    /**
     * @param  array<string, mixed>  $target
     */
    public static function fromArray(array $target): self
    {
        $unexpected = array_diff(array_keys($target), ['target_identity', 'target_sha256']);
        if ($unexpected !== []) {
            throw new InvalidArgumentException('Review target contains unexpected fields: '.implode(', ', $unexpected).'.');
        }

        return new self(
            identity: (string) ($target['target_identity'] ?? ''),
            sha256: (string) ($target['target_sha256'] ?? ''),
        );
    }

    /**
     * @return array{target_identity: string, target_sha256: string}
     */
    public function toArray(): array
    {
        return [
            'target_identity' => $this->identity,
            'target_sha256' => $this->sha256,
        ];
    }
}
