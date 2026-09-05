<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Detector\BoundedDetectorRunner;
use App\Services\SeoIntel\Detector\DetectorQueueMaterializer;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform04DetectorQueueMaterializerTest extends TestCase
{
    private const CONNECTION = 'seo_detector_materializer_test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.'.self::CONNECTION => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge(self::CONNECTION);
        $this->createIssueQueue();
        $this->createOpportunityQueue();
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::CONNECTION);
        DB::disconnect('seo_intel');
        parent::tearDown();
    }

    #[Test]
    public function invalid_timestamp_holds_cannot_materialize_either_queue(): void
    {
        foreach (SeoPlatform04BoundedDetectorRunnerTest::invalidTimestampInputs() as $label => $input) {
            $jobs = [
                $this->job('http_404', ['observed_status' => 404]),
                $this->job('high_impressions_low_ctr', ['high_impressions' => true, 'low_ctr' => true, 'ctr_opportunity_evidence' => true]),
            ];
            foreach ($jobs as &$job) {
                unset($job['evidence']['evidence_observed_at']);
                $job['evidence'] += $input;
            }
            unset($job);
            $artifact = (new BoundedDetectorRunner)->run($jobs, $this->runOptions());
            $this->assertSame(2, $artifact['outcome_counts']['measurement_hold'], $label);
            $receipt = $this->materializer()->materialize($artifact, execute: true, now: $artifact['evaluated_at']);
            $this->assertSame(2, $receipt['counts']['measurement_holds'], $label);
            $this->assertFalse($receipt['writes_committed']);
            $this->assertSame(0, DB::connection(self::CONNECTION)->table('seo_issue_queue')->count());
            $this->assertSame(0, DB::connection(self::CONNECTION)->table('seo_detector_opportunities')->count());
        }
    }

    #[Test]
    public function dry_run_plans_both_queues_without_writing(): void
    {
        $artifact = $this->artifact();
        $receipt = $this->materializer()->materialize($artifact, execute: false, now: '2026-08-25T00:20:00Z');

        $this->assertSame('dry_run', $receipt['mode']);
        $this->assertSame(1, data_get($receipt, 'counts.planned_issues'));
        $this->assertSame(1, data_get($receipt, 'counts.planned_opportunities'));
        $this->assertSame(1, data_get($receipt, 'counts.measurement_holds'));
        $this->assertFalse($receipt['writes_attempted']);
        $this->assertFalse($receipt['writes_committed']);
        $this->assertSame(0, DB::connection(self::CONNECTION)->table('seo_issue_queue')->count());
        $this->assertSame(0, DB::connection(self::CONNECTION)->table('seo_detector_opportunities')->count());
    }

    #[Test]
    public function controlled_materialization_routes_outputs_and_idempotent_rerun_creates_no_duplicates(): void
    {
        $artifact = $this->artifact();
        $materializer = $this->materializer();
        $first = $materializer->materialize($artifact, execute: true, now: '2026-08-25T00:20:00Z');
        $second = $materializer->materialize($artifact, execute: true, now: '2026-08-25T00:30:00Z');

        $this->assertSame(2, data_get($first, 'counts.created'));
        $this->assertSame(1, data_get($first, 'counts.measurement_holds'));
        $this->assertTrue($first['writes_committed']);
        $this->assertSame(2, data_get($second, 'counts.no_change'));
        $this->assertSame(0, data_get($second, 'counts.created'));
        $this->assertFalse($second['writes_committed']);
        $this->assertSame(1, DB::connection(self::CONNECTION)->table('seo_issue_queue')->count());
        $this->assertSame(1, DB::connection(self::CONNECTION)->table('seo_detector_opportunities')->count());

        $issue = DB::connection(self::CONNECTION)->table('seo_issue_queue')->first();
        $opportunity = DB::connection(self::CONNECTION)->table('seo_detector_opportunities')->first();
        $this->assertSame('http_404', $issue->detector_id);
        $this->assertSame('high_impressions_low_ctr', $opportunity->detector_id);
        $this->assertNull($issue->canonical_url);
        $this->assertSame($artifact['artifact_hash'], $issue->artifact_hash);
        $this->assertSame($artifact['artifact_hash'], $opportunity->artifact_hash);
        $encoded = json_encode([$issue, $opportunity], JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private phrase', $encoded);
        $this->assertStringNotContainsString('/results/private', $encoded);
        $this->assertFalse(data_get($first, 'boundaries.search_submission_allowed'));
        $this->assertTrue(data_get($first, 'boundaries.read_only_gsc'));
    }

    #[Test]
    public function evidence_change_updates_in_place_without_resetting_first_detection(): void
    {
        $materializer = $this->materializer();
        $firstArtifact = $this->artifact();
        $materializer->materialize($firstArtifact, execute: true, now: '2026-08-25T00:20:00Z');
        $detectedAt = (string) DB::connection(self::CONNECTION)->table('seo_issue_queue')->value('detected_at');
        $changedArtifact = $this->artifact(
            ['affected_url_count' => 7],
            evaluatedAt: '2026-08-25T00:50:00Z',
        );

        $receipt = $materializer->materialize($changedArtifact, execute: true, now: '2026-08-25T01:00:00Z');
        $issue = DB::connection(self::CONNECTION)->table('seo_issue_queue')->first();

        $this->assertSame(2, data_get($receipt, 'counts.updated'));
        $this->assertSame(0, data_get($receipt, 'counts.created'));
        $this->assertSame(7, $issue->affected_url_count);
        $this->assertSame($detectedAt, (string) $issue->detected_at);
        $this->assertSame(1, DB::connection(self::CONNECTION)->table('seo_issue_queue')->count());
        $this->assertSame(1, DB::connection(self::CONNECTION)->table('seo_detector_opportunities')->count());
    }

    #[Test]
    public function zero_affected_recovery_closes_clusters_and_recurrence_reopens_history(): void
    {
        $materializer = $this->materializer();
        $failureArtifact = $this->artifact();
        $materializer->materialize($failureArtifact, execute: true, now: '2026-08-25T00:20:00Z');
        $recoveryArtifact = $this->recoveryArtifact(evaluatedAt: '2026-08-25T00:50:00Z');
        $recovery = $materializer->materialize($recoveryArtifact, execute: true, now: '2026-08-25T01:00:00Z');

        $this->assertSame(2, data_get($recovery, 'counts.closed'));
        $this->assertSame('resolved', DB::connection(self::CONNECTION)->table('seo_issue_queue')->value('status'));
        $this->assertSame('resolved', DB::connection(self::CONNECTION)->table('seo_detector_opportunities')->value('status'));

        $staleReplay = $materializer->materialize($failureArtifact, execute: true, now: '2026-08-25T01:30:00Z');
        $this->assertSame(2, data_get($staleReplay, 'counts.no_change'));
        $this->assertSame('resolved', DB::connection(self::CONNECTION)->table('seo_issue_queue')->value('status'));

        $recurrenceArtifact = $this->artifact(evaluatedAt: '2026-08-25T01:50:00Z');
        $recurrence = $materializer->materialize($recurrenceArtifact, execute: true, now: '2026-08-25T02:00:00Z');
        $issue = DB::connection(self::CONNECTION)->table('seo_issue_queue')->first();
        $opportunity = DB::connection(self::CONNECTION)->table('seo_detector_opportunities')->first();

        $this->assertSame(2, data_get($recurrence, 'counts.reopened'));
        $this->assertSame('open', $issue->status);
        $this->assertSame('open', $opportunity->status);
        $this->assertSame(1, $issue->reopen_count);
        $this->assertSame(1, $opportunity->reopen_count);
        $this->assertCount(3, data_get(json_decode($issue->metadata_json, true), 'lifecycle_history'));
    }

    #[Test]
    public function recovery_with_nonzero_affected_scope_is_deferred(): void
    {
        $materializer = $this->materializer();
        $materializer->materialize($this->artifact(), execute: true, now: '2026-08-25T00:20:00Z');
        $recovery = $this->recoveryArtifact(affectedUrlCount: 1, evaluatedAt: '2026-08-25T00:50:00Z');
        $receipt = $materializer->materialize($recovery, execute: true, now: '2026-08-25T01:00:00Z');

        $this->assertSame(2, data_get($receipt, 'counts.recovery_deferred'));
        $this->assertSame(0, data_get($receipt, 'counts.closed'));
        $this->assertSame('open', DB::connection(self::CONNECTION)->table('seo_issue_queue')->value('status'));
        $this->assertSame('open', DB::connection(self::CONNECTION)->table('seo_detector_opportunities')->value('status'));
    }

    #[Test]
    public function tampered_or_partial_artifacts_fail_before_any_write(): void
    {
        $artifact = $this->artifact();
        $artifact['processed_url_count'] = 999;

        try {
            $this->materializer()->materialize($artifact, execute: true);
            $this->fail('Tampered artifact must fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('artifact', strtolower($exception->getMessage()));
        }

        $partial = $this->artifact();
        $partial['complete'] = false;
        $partial['artifact_hash'] = (new BoundedDetectorRunner)->artifactHash($partial);
        $this->expectException(InvalidArgumentException::class);
        $this->materializer()->materialize($partial, execute: true);
    }

    #[Test]
    public function queue_writes_are_atomic_when_a_target_fails(): void
    {
        Schema::connection(self::CONNECTION)->drop('seo_detector_opportunities');

        try {
            $this->materializer()->materialize($this->artifact(), execute: true, now: '2026-08-25T00:20:00Z');
            $this->fail('Missing opportunity target must fail the transaction.');
        } catch (QueryException) {
            $this->assertSame(0, DB::connection(self::CONNECTION)->table('seo_issue_queue')->count());
        }
    }

    #[Test]
    public function expand_migration_resumes_after_issue_columns_commit_but_opportunity_table_fails(): void
    {
        config([
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('seo_intel');
        $this->createIssueQueue('seo_intel');
        $migration = require base_path(
            'database/migrations/seo_intel/2026_08_25_010000_expand_detector_queue_materialization.php'
        );

        $migration->up();

        $this->assertTrue(Schema::connection('seo_intel')->hasColumn('seo_issue_queue', 'detector_id'));
        $this->assertTrue(Schema::connection('seo_intel')->hasTable('seo_detector_opportunities'));
    }

    #[Test]
    public function expired_evidence_and_indirect_high_severity_fail_before_writes(): void
    {
        try {
            $this->materializer()->materialize(
                $this->artifact(),
                execute: true,
                now: '2026-08-26T00:01:00Z',
            );
            $this->fail('Expired detector evidence must fail before writes.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('freshness', strtolower($exception->getMessage()));
        }

        $indirect = $this->artifact();
        $indirect['results'][0]['severity'] = 'P1';
        $indirect['results'][0]['evidence_state'] = 'insufficient_evidence';
        $indirect['artifact_hash'] = (new BoundedDetectorRunner)->artifactHash($indirect);

        $this->expectException(InvalidArgumentException::class);
        $this->materializer()->materialize($indirect, execute: true, now: '2026-08-25T00:20:00Z');
    }

    #[Test]
    public function future_dated_evidence_fails_before_writes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('freshness');

        $this->materializer()->materialize(
            $this->artifact(evaluatedAt: '2026-08-25T00:10:00Z'),
            execute: true,
            now: '2026-08-25T00:09:59Z',
        );
    }

    /** @param array<string, mixed> $sharedEvidence */
    private function artifact(
        array $sharedEvidence = [],
        string $evaluatedAt = '2026-08-25T00:10:00Z',
    ): array {
        return (new BoundedDetectorRunner)->run([
            $this->job('http_404', array_replace([
                'observed_status' => 404,
                'root_cause_or_error_code' => 'shared_template_missing',
            ], $sharedEvidence)),
            $this->job('high_impressions_low_ctr', array_replace([
                'query_segment' => 'non_branded',
                'gsc_quality_gate_pass' => true,
                'complete_window' => true,
                'impressions' => 100,
                'ctr' => 0.01,
                'policy_impression_threshold' => 50,
                'policy_ctr_threshold' => 0.02,
                'root_cause_or_error_code' => 'snippet_intent_gap',
            ], $sharedEvidence)),
            $this->job('review_overdue', [
                'source_state' => 'unavailable',
                'days_since_review' => 100,
                'family_review_cycle_days' => 90,
            ]),
        ], $this->runOptions($evaluatedAt));
    }

    private function recoveryArtifact(
        int $affectedUrlCount = 0,
        string $evaluatedAt = '2026-08-25T00:50:00Z',
    ): array {
        return (new BoundedDetectorRunner)->run([
            $this->job('http_404', [
                'observed_status' => 200,
                'affected_url_count' => $affectedUrlCount,
                'root_cause_or_error_code' => 'shared_template_missing',
            ]),
            $this->job('high_impressions_low_ctr', [
                'query_segment' => 'non_branded',
                'gsc_quality_gate_pass' => true,
                'complete_window' => true,
                'impressions' => 100,
                'ctr' => 0.05,
                'policy_impression_threshold' => 50,
                'policy_ctr_threshold' => 0.02,
                'affected_url_count' => $affectedUrlCount,
                'root_cause_or_error_code' => 'snippet_intent_gap',
            ]),
        ], $this->runOptions($evaluatedAt));
    }

    /** @param array<string, mixed> $evidence */
    private function job(string $detectorId, array $evidence): array
    {
        return [
            'detector_id' => $detectorId,
            'evidence' => array_replace([
                'source_state' => 'available',
                'evidence_complete' => true,
                'direct_evidence' => true,
                'page_family' => 'articles_topics',
                'locale' => 'en',
                'indexability_state' => 'indexable',
                'canonical_url_hash' => hash('sha256', $detectorId),
                'query_hash' => hash('sha256', 'private phrase'),
                'authority_revision' => 'authority-r1',
                'url_truth_revision' => 'url-truth-r1',
                'policy_version' => 'seo-page-family-policy.v1',
                'evidence_observed_at' => '2026-08-25T00:00:00Z',
                'private_negative_set_checked' => true,
                'affected_url_count' => 1,
                'raw_query' => 'private phrase must not escape',
                'private_url' => 'https://example.test/results/private',
            ], $evidence),
        ];
    }

    /** @return array<string, mixed> */
    private function runOptions(string $evaluatedAt = '2026-08-25T00:10:00Z'): array
    {
        return [
            'dry_run' => false,
            'max_urls' => 100,
            'timeout_ms' => 1_000,
            'expected_policy_version' => 'seo-page-family-policy.v1',
            'expected_authority_revision' => 'authority-r1',
            'now' => $evaluatedAt,
        ];
    }

    private function materializer(): DetectorQueueMaterializer
    {
        return new DetectorQueueMaterializer(connectionName: self::CONNECTION);
    }

    private function createIssueQueue(string $connection = self::CONNECTION): void
    {
        Schema::connection($connection)->create('seo_issue_queue', function (Blueprint $table): void {
            $table->id();
            $table->string('issue_uid', 128)->unique();
            $table->string('issue_type', 80);
            $table->string('detector_id', 80)->nullable();
            $table->string('detector_version', 32)->nullable();
            $table->string('severity', 32);
            $table->string('source_system', 64);
            $table->string('source_engine', 64)->nullable();
            $table->char('canonical_url_hash', 64)->nullable();
            $table->char('query_hash', 64)->nullable();
            $table->text('canonical_url')->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('page_entity_type', 80)->nullable();
            $table->string('entity_id_or_slug')->nullable();
            $table->string('cluster', 80)->nullable();
            $table->string('cluster_uid', 64)->nullable();
            $table->string('authority_revision', 160)->nullable();
            $table->string('url_truth_revision', 160)->nullable();
            $table->string('policy_version', 160)->nullable();
            $table->unsignedInteger('affected_url_count')->default(1);
            $table->string('status', 32);
            $table->string('lifecycle_state', 32);
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('summary', 512)->nullable();
            $table->string('recommendation', 512)->nullable();
            $table->char('evidence_hash', 64)->nullable();
            $table->char('artifact_hash', 64)->nullable();
            $table->timestamp('last_evidence_at')->nullable();
            $table->unsignedInteger('reopen_count')->default(0);
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    private function createOpportunityQueue(): void
    {
        Schema::connection(self::CONNECTION)->create('seo_detector_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->string('opportunity_uid', 128)->unique();
            $table->string('detector_id', 80);
            $table->string('detector_version', 32);
            $table->string('cluster_uid', 64);
            $table->char('canonical_url_hash', 64)->nullable();
            $table->char('query_hash', 64)->nullable();
            $table->string('locale', 16)->nullable();
            $table->string('page_family', 80);
            $table->string('authority_revision', 160);
            $table->string('url_truth_revision', 160);
            $table->string('policy_version', 160);
            $table->string('status', 32);
            $table->string('lifecycle_state', 32);
            $table->unsignedInteger('affected_url_count');
            $table->char('evidence_hash', 64);
            $table->char('artifact_hash', 64);
            $table->json('metadata_json')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('last_evidence_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedInteger('reopen_count')->default(0);
            $table->timestamps();
        });
    }
}
