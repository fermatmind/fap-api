<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\Runtime\RuntimeClusterRecoveryMaterializer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform07RuntimeClusterRecoveryMaterializerTest extends TestCase
{
    #[Test]
    public function same_template_failure_materializes_one_existing_queue_cluster(): void
    {
        $result = $this->materializer()->materialize([
            'state' => 'incident',
            'incidents' => [
                $this->incident('identity-a', 3),
                $this->incident('identity-b', 2),
            ],
        ], $this->revisions());

        $this->assertCount(1, $result['clusters']);
        $this->assertSame('seo_issue_queue', data_get($result, 'clusters.0.queue_target'));
        $this->assertSame(2, data_get($result, 'clusters.0.observation_count'));
        $this->assertSame(5, data_get($result, 'clusters.0.affected_count'));
        $this->assertTrue(data_get($result, 'boundaries.existing_issue_queue_reused'));
        $this->assertFalse(data_get($result, 'boundaries.parallel_queue_created'));
    }

    #[Test]
    public function fresh_aligned_success_records_verifiable_recovery(): void
    {
        $open = $this->materializer()->materialize([
            'state' => 'incident',
            'incidents' => [$this->incident('identity-a', 1)],
        ], $this->revisions())['clusters'];

        $recovered = $this->materializer()->materialize([
            'state' => 'success',
            'incidents' => [],
        ], $this->revisions(), $open);

        $this->assertSame('recovered', data_get($recovered, 'clusters.0.status'));
        $this->assertTrue(data_get($recovered, 'clusters.0.evidence.recovery.verified'));
        $this->assertTrue(data_get($recovered, 'clusters.0.evidence.recovery.checks.fresh_probe_success'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($recovered, 'clusters.0.evidence.recovery.evidence_hash'));
    }

    #[Test]
    public function missing_or_drifted_revision_evidence_cannot_close_a_cluster(): void
    {
        $open = $this->materializer()->materialize([
            'state' => 'incident',
            'incidents' => [$this->incident('identity-a', 1)],
        ], $this->revisions())['clusters'];
        $hold = $this->revisions();
        $hold['state'] = 'MEASUREMENT_HOLD';

        $result = $this->materializer()->materialize(['state' => 'success', 'incidents' => []], $hold, $open);

        $this->assertSame('MEASUREMENT_HOLD', $result['state']);
        $this->assertSame('open', data_get($result, 'clusters.0.status'));
        $this->assertFalse(data_get($result, 'clusters.0.evidence.recovery.verified'));
    }

    #[Test]
    public function a_new_authority_revision_reopens_recurrent_template_failure(): void
    {
        $prior = $this->materializer()->materialize([
            'state' => 'incident',
            'incidents' => [$this->incident('identity-a', 1)],
        ], $this->revisions())['clusters'][0];
        $prior['status'] = 'recovered';
        $next = $this->revisions('authority-r2');

        $result = $this->materializer()->materialize([
            'state' => 'incident',
            'incidents' => [$this->incident('identity-c', 1)],
        ], $next, [$prior]);

        $this->assertTrue(data_get($result, 'clusters.0.reopened'));
        $this->assertSame(1, data_get($result, 'clusters.0.recurrence_count'));
        $this->assertSame($prior['cluster_uid'], data_get($result, 'clusters.0.evidence.recurrence_from_cluster_uid'));
        $this->assertNotSame($prior['cluster_uid'], data_get($result, 'clusters.0.cluster_uid'));
    }

    private function materializer(): RuntimeClusterRecoveryMaterializer
    {
        return new RuntimeClusterRecoveryMaterializer;
    }

    /** @return array<string,mixed> */
    private function incident(string $identity, int $affected): array
    {
        return [
            'incident_key' => $identity,
            'detector' => 'http_5xx',
            'root_cause' => 'article-template-render',
            'page_family' => 'articles_topics',
            'locale' => 'en',
            'affected_count' => $affected,
        ];
    }

    /** @return array<string,mixed> */
    private function revisions(string $authority = 'authority-r1'): array
    {
        return [
            'state' => 'aligned',
            'revisions' => [
                'authority_revision' => $authority,
                'api_render_revision' => $authority,
                'cache_revision' => $authority,
            ],
        ];
    }
}
