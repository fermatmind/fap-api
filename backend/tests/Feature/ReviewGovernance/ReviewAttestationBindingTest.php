<?php

declare(strict_types=1);

namespace Tests\Feature\ReviewGovernance;

use App\Models\ReviewAttestation;
use App\Models\ReviewAttestationTargetEvidence;
use App\Services\ReviewGovernance\ReviewAttestationFactory;
use App\Services\ReviewGovernance\ReviewAttestationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

final class ReviewAttestationBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('review_governance.mode', 'solo_owner');
        config()->set('review_governance.solo_owner_admin_user_id', 1);
    }

    public function test_preflight_is_read_only_and_bind_expands_exact_idempotent_evidence(): void
    {
        $targets = $this->targets();
        $attestation = app(ReviewAttestationFactory::class)->make(
            'package',
            'cms:sample',
            'approved_with_exceptions',
            $targets,
            exceptions: [['target_identity' => 'article:2', 'reason' => 'private correction required']],
        );
        $service = app(ReviewAttestationService::class);

        $preflight = $service->preflight($attestation, array_reverse($targets));
        $this->assertSame('PASS_SOLO_OWNER_ATTESTATION_PREFLIGHT', $preflight['status']);
        $this->assertSame(0, $preflight['database_writes']);
        $this->assertDatabaseCount('review_attestations', 0);
        $this->assertDatabaseCount('review_attestation_target_evidences', 0);

        $first = $service->bind($attestation, array_reverse($targets));
        $this->assertDatabaseCount('review_attestations', 1);
        $this->assertDatabaseCount('review_attestation_target_evidences', 2);
        $this->assertSame(
            ['article:1' => 'approved', 'article:2' => 'excepted'],
            $first->targetEvidences->sortBy('target_identity')->pluck('target_decision', 'target_identity')->all(),
        );

        try {
            $first->decision = 'rejected';
            $first->save();
            $this->fail('Bound attestation was mutable.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
        try {
            $first->targetEvidences->firstOrFail()->delete();
            $this->fail('Expanded target evidence was deletable.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('cannot be deleted', $exception->getMessage());
        }

        $second = $service->bind($attestation, $targets);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('review_attestations', 1);
        $this->assertDatabaseCount('review_attestation_target_evidences', 2);
    }

    public function test_target_evidence_failure_rolls_back_the_whole_transaction(): void
    {
        $targets = $this->targets();
        $attestation = app(ReviewAttestationFactory::class)->make('batch', 'cms:rollback', 'approved_all', $targets);
        $event = 'eloquent.creating: '.ReviewAttestationTargetEvidence::class;
        Event::listen($event, static function (ReviewAttestationTargetEvidence $evidence): void {
            if ($evidence->target_identity === 'article:2') {
                throw new RuntimeException('synthetic target evidence failure');
            }
        });

        try {
            app(ReviewAttestationService::class)->bind($attestation, $targets);
            $this->fail('Synthetic target evidence failure did not abort the bind.');
        } catch (RuntimeException $exception) {
            $this->assertSame('synthetic target evidence failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertSame(0, ReviewAttestation::query()->count());
        $this->assertSame(0, ReviewAttestationTargetEvidence::query()->count());
    }

    /**
     * @return list<array{target_identity: string, target_sha256: string}>
     */
    private function targets(): array
    {
        return [
            ['target_identity' => 'article:1', 'target_sha256' => str_repeat('a', 64)],
            ['target_identity' => 'article:2', 'target_sha256' => str_repeat('b', 64)],
        ];
    }
}
