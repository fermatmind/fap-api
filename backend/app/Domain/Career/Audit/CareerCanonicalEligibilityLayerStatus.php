<?php

declare(strict_types=1);

namespace App\Domain\Career\Audit;

use InvalidArgumentException;

final class CareerCanonicalEligibilityLayerStatus
{
    /**
     * @param  list<string>  $reasons
     * @param  list<mixed>  $evidence
     */
    public function __construct(
        public readonly string $layer,
        public readonly string $status,
        public readonly array $reasons = [],
        public readonly array $evidence = [],
        public readonly ?string $source = null,
    ) {
        CareerCanonicalEligibilityLayer::assertValid($this->layer);
        CareerCanonicalEligibilityStatus::assertValid($this->status);
        self::assertList('reasons', $this->reasons);
        self::assertList('evidence', $this->evidence);

        if ($this->source !== null && trim($this->source) === '') {
            throw new InvalidArgumentException('Career canonical eligibility layer source must be null or a non-empty string.');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): self
    {
        return new self(
            layer: self::requiredString($value, 'layer'),
            status: self::requiredString($value, 'status'),
            reasons: self::optionalList($value, 'reasons'),
            evidence: self::optionalList($value, 'evidence'),
            source: array_key_exists('source', $value) ? self::nullableString($value['source'], 'source') : null,
        );
    }

    /**
     * @return array{layer: string, status: string, reasons: list<string>, evidence: list<mixed>, source: string|null}
     */
    public function toArray(): array
    {
        return [
            'layer' => $this->layer,
            'status' => $this->status,
            'reasons' => $this->reasons,
            'evidence' => $this->evidence,
            'source' => $this->source,
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function requiredString(array $value, string $key): string
    {
        if (! array_key_exists($key, $value) || ! is_string($value[$key]) || trim($value[$key]) === '') {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility layer status requires non-empty [%s].', $key));
        }

        return $value[$key];
    }

    private static function nullableString(mixed $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility layer status [%s] must be null or a non-empty string.', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return list<mixed>
     */
    private static function optionalList(array $value, string $key): array
    {
        if (! array_key_exists($key, $value)) {
            return [];
        }

        if (! is_array($value[$key])) {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility layer status [%s] must be a list.', $key));
        }

        self::assertList($key, $value[$key]);

        return $value[$key];
    }

    private static function assertList(string $key, array $value): void
    {
        if (! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('Career canonical eligibility layer status [%s] must be a list.', $key));
        }
    }
}
