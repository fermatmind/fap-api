<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoAgentEvidence\Dependency\SeoEvidenceDependencySnapshotBuilder;
use App\Services\SeoAgentEvidence\Dependency\SeoEvidenceDependencySnapshotVerifier;
use App\Services\SeoAgentEvidence\Sources\SeoPlatformDependencyEvidenceAdapter;
use Tests\TestCase;

final class SeoPlatform11BDependencySnapshotTest extends TestCase
{
    public function test_dependency_hold_is_a_valid_exact_sha_snapshot_and_mutations_fail(): void
    {
        $sha = str_repeat('a', 40);
        $dependencies = app(SeoPlatformDependencyEvidenceAdapter::class)->snapshot($sha);
        $snapshot = app(SeoEvidenceDependencySnapshotBuilder::class)->build($sha, $dependencies);
        $verifier = app(SeoEvidenceDependencySnapshotVerifier::class);
        $this->assertSame('DEPENDENCY_HOLD', $snapshot['status']);
        $this->assertFalse($snapshot['execution_allowed']);
        $this->assertTrue($verifier->verify($snapshot, $sha)['valid']);
        $this->assertFalse($verifier->verify($snapshot, str_repeat('b', 40))['valid']);
        $snapshot['registry_hash'] = str_repeat('0', 64);
        $this->assertFalse($verifier->verify($snapshot, $sha)['valid']);
    }

    public function test_missing_and_malformed_dependency_evidence_fail_closed_as_hold(): void
    {
        $sha = str_repeat('c', 40);
        $builder = app(SeoEvidenceDependencySnapshotBuilder::class);
        $missing = $builder->build($sha, []);
        $this->assertSame('DEPENDENCY_HOLD', $missing['status']);
        $this->assertCount(6, $missing['dependencies']);
        $this->assertContains('seo-platform-06', $missing['blockers']);
        $this->assertTrue(app(SeoEvidenceDependencySnapshotVerifier::class)->verify($missing, $sha)['valid']);

        $malformed = $builder->build($sha, [[
            'dependency_id' => 'seo-platform-08',
            'status' => 'verified',
            'source_state' => 'available',
            'private_boundary_proven' => false,
            'evidence_code' => 'UNTRUSTED_401',
        ]]);
        $ledger = collect($malformed['dependencies'])->firstWhere('dependency_id', 'seo-platform-08');
        $this->assertSame('held', $ledger['status']);
        $this->assertFalse($malformed['execution_allowed']);
    }
}
