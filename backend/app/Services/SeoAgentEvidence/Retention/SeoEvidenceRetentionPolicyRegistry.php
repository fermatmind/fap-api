<?php

declare(strict_types=1);

namespace App\Services\SeoAgentEvidence\Retention;

use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceCanonicalHasher;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class SeoEvidenceRetentionPolicyRegistry
{
    public const VERSION = '1.0.0';

    private const DAYS = [
        'dependency_contract' => 400,
        'first_party_aggregate' => 90,
        'public_runtime_observation' => 30,
        'external_structured_fact' => 30,
        'external_short_excerpt' => 7,
        'security_rejection_metadata' => 30,
        'deletion_receipt' => 400,
    ];

    public function __construct(private readonly SeoEvidenceCanonicalHasher $hasher) {}

    public function hash(): string
    {
        return $this->hasher->hash(['version' => self::VERSION, 'classes_days' => self::DAYS]);
    }

    public function expiresAt(string $class, CarbonImmutable $capturedAt): string
    {
        if (! isset(self::DAYS[$class])) {
            throw new InvalidArgumentException('Unsupported evidence retention class.');
        }

        return $capturedAt->utc()->addDays(self::DAYS[$class])->format('Y-m-d\TH:i:s\Z');
    }

    public function isPersistable(string $class): bool
    {
        return isset(self::DAYS[$class]) && $class !== 'deletion_receipt';
    }
}
