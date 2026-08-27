<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\PageFamily\PageFamilyPolicyRegistry;
use App\Services\SeoIntel\UrlTruth\BoundedPublicUrlEvidenceProbe;
use App\Services\SeoIntel\UrlTruth\ControlledUrlTruthReconciliationService;
use App\Services\SeoIntel\UrlTruth\EffectivePublicUrlEvaluator;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use App\Services\SeoIntel\UrlTruthInventoryRecordWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform05ControlledUrlTruthReconciliationTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::purge('seo_intel');
        parent::tearDown();
    }

    #[Test]
    public function dry_run_and_controlled_write_preserve_history_and_prove_exact_input_idempotency(): void
    {
        $this->prepareSchema();
        config([
            'seo_intel.enabled' => true,
            'seo_intel.write_enabled' => true,
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
        ]);
        $writer = new UrlTruthInventoryRecordWriter;
        $writer->write([$this->record('orphan')]);
        $service = new ControlledUrlTruthReconciliationService(
            new EffectivePublicUrlEvaluator,
            $writer,
            new BoundedPublicUrlEvidenceProbe,
            new PageFamilyPolicyRegistry,
        );
        $authority = [
            $this->record('alpha'),
            $this->record('bravo'),
            $this->record('private', true),
        ];

        $dryRun = $service->run($authority, ['revision' => 'fixture-v1'], false, false, 100, 10);

        $this->assertSame('success', $dryRun['status']);
        $this->assertSame('dry_run', $dryRun['mode']);
        $this->assertSame(2, data_get($dryRun, 'artifact.record_count'));
        $this->assertSame(2, data_get($dryRun, 'plan.counts.added'));
        $this->assertSame(1, data_get($dryRun, 'plan.counts.retired'));
        $this->assertSame(1, data_get($dryRun, 'plan.counts.rejected'));
        $this->assertFalse($dryRun['writes_committed']);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_urls')->count());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($dryRun, 'artifact.artifact_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($dryRun, 'artifact.content_digest'));

        $execute = $service->run($authority, ['revision' => 'fixture-v1'], true, false, 100, 10);

        $this->assertSame('success', $execute['status']);
        $this->assertSame('controlled_write', $execute['mode']);
        $this->assertTrue($execute['writes_committed']);
        $this->assertSame(data_get($dryRun, 'artifact.artifact_hash'), data_get($execute, 'artifact.artifact_hash'));
        $this->assertSame(1, $execute['batch_count']);
        $this->assertSame('career', data_get($execute, 'batches.0.family'));
        $this->assertSame('canary', data_get($execute, 'batches.0.stage'));
        $this->assertTrue((bool) data_get($execute, 'batches.0.database_readback_ok'));
        $this->assertSame('measurement_hold', data_get($execute, 'consumer_evidence.state'));
        $this->assertSame(0, data_get($execute, 'idempotent_rerun.added'));
        $this->assertSame(0, data_get($execute, 'idempotent_rerun.duplicate'));
        $this->assertSame(0, data_get($execute, 'idempotent_rerun.unexpected_updated'));
        $this->assertSame(0, data_get($execute, 'idempotent_rerun.private_leakage'));
        $this->assertSame(0, data_get($execute, 'idempotent_rerun.current_binding_conflicts'));
        $this->assertTrue((bool) data_get($execute, 'idempotent_rerun.passed'));
        $this->assertSame(2, DB::connection('seo_intel')->table('seo_url_entities')->whereNotNull('current_binding_key')->count());
        $this->assertSame(
            'retired_authority',
            DB::connection('seo_intel')->table('seo_urls')->where('entity_id_or_slug', 'orphan')->value('indexability_state'),
        );
        $this->assertFalse((bool) data_get($execute, 'boundaries.search_submission_allowed', true));
        $this->assertFalse((bool) data_get($execute, 'boundaries.hard_delete', true));
    }

    #[Test]
    public function conflicting_current_authority_bindings_fail_closed_without_writes(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        $service = new ControlledUrlTruthReconciliationService(
            new EffectivePublicUrlEvaluator,
            new UrlTruthInventoryRecordWriter,
            new BoundedPublicUrlEvidenceProbe,
            new PageFamilyPolicyRegistry,
        );
        $first = $this->record('same');
        $second = new UrlTruthInventoryRecord(
            canonicalUrl: 'https://fermatmind.com/en/career/jobs/alternate',
            locale: 'en',
            pageEntityType: $first->pageEntityType,
            entityIdOrSlug: $first->entityIdOrSlug,
            sourceAuthority: $first->sourceAuthority,
            cluster: 'career',
            entitySource: $first->entitySource,
            authorityStatus: 'published_approved',
            metadata: $first->metadata,
            attributes: $first->attributes,
        );

        $receipt = $service->run([$first, $second], ['revision' => 'fixture-v1'], true, false, 100, 10);

        $this->assertSame('blocked', $receipt['status']);
        $this->assertSame(['authority_binding_conflict'], $receipt['issues']);
        $this->assertFalse($receipt['writes_committed']);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_urls')->count());
    }

    #[Test]
    public function sitemap_only_urls_plan_a_detector_issue_and_never_create_url_truth(): void
    {
        $this->prepareSchema();
        config([
            'seo_intel.enabled' => true,
            'seo_intel.write_enabled' => true,
            'seo_intel.public_canonical_host' => 'https://fermatmind.com',
            'app.public_api_url' => 'https://api.fermatmind.com',
        ]);
        Http::fake(static function ($request) {
            $url = $request->url();
            if ($url === 'https://fermatmind.com/sitemap.xml') {
                return Http::response('<?xml version="1.0"?><urlset><url><loc>https://fermatmind.com/en/career/jobs/alpha</loc></url><url><loc>https://fermatmind.com/en/sitemap-only</loc></url></urlset>', 200);
            }
            if ($url === 'https://api.fermatmind.com/api/v0.5/seo/sitemap-source') {
                return Http::response(['data' => []], 200);
            }

            return Http::response('', 200);
        });
        $service = new ControlledUrlTruthReconciliationService(
            new EffectivePublicUrlEvaluator,
            new UrlTruthInventoryRecordWriter,
            new BoundedPublicUrlEvidenceProbe,
            new PageFamilyPolicyRegistry,
        );

        $receipt = $service->run([$this->record('alpha')], ['revision' => 'fixture-v1'], true, true, 100, 10);

        $this->assertSame('success', $receipt['status']);
        $this->assertSame(1, data_get($receipt, 'sitemap_authority_detector.sitemap_without_authority_count'));
        $this->assertSame(1, data_get($receipt, 'sitemap_authority_detector.planned_issues'));
        $this->assertTrue((bool) data_get($receipt, 'sitemap_authority_detector.writes_committed'));
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_urls')->count());
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_issue_queue')->where('issue_type', 'public_collection_split')->count());
        $this->assertFalse((bool) data_get($receipt, 'boundaries.sitemap_can_create_authority', true));
    }

    #[Test]
    public function deploy_runs_the_controlled_reconcile_after_the_read_only_snapshot(): void
    {
        $deploy = (string) file_get_contents(dirname(__DIR__, 4).'/deploy.php');

        $this->assertStringContainsString("task('seo:url-truth-controlled-reconcile'", $deploy);
        $this->assertStringNotContainsString("after('seo:url-truth-reconciliation-receipt', 'seo:url-truth-controlled-reconcile');", $deploy);
        $this->assertStringContainsString('seo-intel:url-truth-controlled-reconcile', $deploy);
        $this->assertStringContainsString('$rerun["private_leakage"] ?? null', $deploy);
        $this->assertStringContainsString('$detector["sitemap_without_authority_count"] ?? null', $deploy);
        $this->assertStringContainsString('"controlled_materialization"', $deploy);
        $this->assertStringNotContainsString('request-indexing', $deploy);
    }

    private function prepareSchema(): void
    {
        config([
            'database.default' => 'seo_intel',
            'seo_intel.connection' => 'seo_intel',
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('seo_intel');
        foreach ([
            '2026_05_17_000100_create_seo_urls_table.php',
            '2026_05_17_000200_create_seo_url_entities_table.php',
            '2026_05_17_001700_create_seo_issue_queue_table.php',
            '2026_08_25_010000_expand_detector_queue_materialization.php',
            '2026_08_25_020000_expand_url_truth_current_bindings.php',
        ] as $migrationFile) {
            $migration = require dirname(__DIR__, 3).'/database/migrations/seo_intel/'.$migrationFile;
            $migration->up();
        }
    }

    private function record(string $slug, bool $private = false): UrlTruthInventoryRecord
    {
        return new UrlTruthInventoryRecord(
            canonicalUrl: 'https://fermatmind.com/en/career/jobs/'.$slug,
            locale: 'en',
            pageEntityType: 'career_job',
            entityIdOrSlug: $slug,
            sourceAuthority: 'career_runtime_publish_projection',
            cluster: 'career',
            entitySource: 'career_directory_authority',
            authorityStatus: 'published_approved',
            isPrivateFlow: $private,
            metadata: [
                'publication_state' => 'published',
                'robots' => 'index,follow',
                'canonical_self' => true,
                'page_family' => 'career',
                'authority_revision' => 'fixture-v1',
            ],
            attributes: ['authority_revision' => 'fixture-v1'],
        );
    }
}
