<?php

declare(strict_types=1);

namespace Tests\Unit\ReviewGovernance;

use App\DTO\ReviewGovernance\ReviewTargetSet;
use App\Services\ReviewGovernance\ReviewAttestationCanonicalizer;
use App\Services\ReviewGovernance\ReviewAttestationFactory;
use App\Services\ReviewGovernance\ReviewAttestationService;
use App\Services\ReviewGovernance\ReviewAttestationValidationException;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

final class ReviewAttestationContractTest extends TestCase
{
    private const SHA_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SHA_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const SHA_C = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', 1);
        config()->set('review_governance.attestation.schema_version', 'solo-owner-review-attestation.v1');
        config()->set('review_governance.attestation.review_source', 'owner_operator_attestation');
        config()->set('review_governance.attestation.statement_version', 'solo-owner-attestation.v1');
    }

    public function test_canonical_serialization_and_target_set_are_deterministic(): void
    {
        $canonicalizer = app(ReviewAttestationCanonicalizer::class);
        $this->assertSame(
            '{"a":{"c":3,"d":4},"b":2}',
            $canonicalizer->encode(['b' => 2, 'a' => ['d' => 4, 'c' => 3]]),
        );

        $forward = ReviewTargetSet::fromArray($this->targets(), $canonicalizer);
        $reverse = ReviewTargetSet::fromArray(array_reverse($this->targets()), $canonicalizer);
        $this->assertSame($forward->sha256, $reverse->sha256);
        $this->assertSame(['article:1', 'article:2'], $forward->identities());

        $at = CarbonImmutable::parse('2026-07-17T12:00:00Z');
        $factory = app(ReviewAttestationFactory::class);
        $first = $factory->make('batch', 'cms:sample', 'approved_all', $this->targets(), self::SHA_C, [], $at);
        $second = $factory->make('batch', 'cms:sample', 'approved_all', array_reverse($this->targets()), self::SHA_C, [], $at);
        $this->assertSame($first['target_set_sha256'], $second['target_set_sha256']);
        $this->assertSame($first['evidence_sha256'], $second['evidence_sha256']);
    }

    public function test_duplicate_missing_extra_and_hash_drift_fail_closed(): void
    {
        $factory = app(ReviewAttestationFactory::class);
        $service = app(ReviewAttestationService::class);
        $attestation = $factory->make('batch', 'cms:sample', 'approved_all', $this->targets());

        try {
            ReviewTargetSet::fromArray([$this->targets()[0], $this->targets()[0]], app(ReviewAttestationCanonicalizer::class));
            $this->fail('Duplicate target identity was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('duplicate identity', $exception->getMessage());
        }

        foreach ([
            'missing' => [$this->targets()[0]],
            'extra' => [...$this->targets(), ['target_identity' => 'article:3', 'target_sha256' => self::SHA_C]],
            'hash drift' => [
                $this->targets()[0],
                ['target_identity' => 'article:2', 'target_sha256' => self::SHA_C],
            ],
        ] as $label => $targets) {
            try {
                $service->preflight($attestation, $targets);
                $this->fail(ucfirst($label).' target set was accepted.');
            } catch (ReviewAttestationValidationException $exception) {
                $this->assertStringContainsString('target', strtolower($exception->getMessage()));
            }
        }
    }

    public function test_target_identity_and_hash_reject_non_string_values(): void
    {
        $canonicalizer = app(ReviewAttestationCanonicalizer::class);

        foreach ([123, true, false] as $identity) {
            try {
                ReviewTargetSet::fromArray([
                    ['target_identity' => $identity, 'target_sha256' => self::SHA_A],
                ], $canonicalizer);
                $this->fail('Non-string target identity was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('exact string', $exception->getMessage());
            }
        }

        try {
            ReviewTargetSet::fromArray([
                ['target_identity' => 'article:1', 'target_sha256' => 123],
            ], $canonicalizer);
            $this->fail('Non-string target SHA-256 was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('exact string', $exception->getMessage());
        }
    }

    public function test_actor_package_evidence_and_exception_drift_fail_closed(): void
    {
        $factory = app(ReviewAttestationFactory::class);
        $service = app(ReviewAttestationService::class);
        $base = $factory->make('batch', 'cms:sample', 'approved_all', $this->targets(), self::SHA_C);

        foreach ([
            'actor' => ['attested_by_admin_user_id', 2],
            'package' => ['package_sha256', self::SHA_B],
            'evidence' => ['evidence_sha256', self::SHA_A],
        ] as [$field, $value]) {
            $payload = $base;
            $payload[$field] = $value;
            $this->expectValidationFailure(fn () => $service->preflight($payload, $this->targets(), self::SHA_C));
        }

        $invalidException = $factory->make(
            'batch',
            'cms:sample',
            'approved_with_exceptions',
            $this->targets(),
            exceptions: [['target_identity' => 'article:unknown', 'reason' => 'private exception']],
        );
        $this->expectValidationFailure(fn () => $service->preflight($invalidException, $this->targets()));
    }

    /**
     * @return list<array{target_identity: string, target_sha256: string}>
     */
    private function targets(): array
    {
        return [
            ['target_identity' => 'article:1', 'target_sha256' => self::SHA_A],
            ['target_identity' => 'article:2', 'target_sha256' => self::SHA_B],
        ];
    }

    private function expectValidationFailure(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Invalid attestation was accepted.');
        } catch (ReviewAttestationValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
