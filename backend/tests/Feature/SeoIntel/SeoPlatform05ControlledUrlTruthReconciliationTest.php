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

        $dryRun = $this->reconcile($service, $authority, ['revision' => 'fixture-v1'], false, false, 100, 10);

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

        $execute = $this->reconcile($service, $authority, ['revision' => 'fixture-v1'], true, false, 100, 10);

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

        $receipt = $this->reconcile($service, [$first, $second], ['revision' => 'fixture-v1'], true, false, 100, 10);

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

        $receipt = $this->reconcile($service, [$this->record('alpha')], ['revision' => 'fixture-v1'], true, true, 100, 10);

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
        $this->assertStringContainsString("after('seo:url-truth-reconciliation-receipt', 'seo:url-truth-controlled-reconcile');", $deploy);
        $this->assertStringContainsString('seo-intel:url-truth-controlled-reconcile', $deploy);
        $this->assertStringContainsString('$rerun["private_leakage"] ?? null', $deploy);
        $this->assertStringContainsString('$detector["sitemap_without_authority_count"] ?? null', $deploy);
        $this->assertStringContainsString('"controlled_materialization"', $deploy);
        $this->assertStringNotContainsString('request-indexing', $deploy);
    }

    public function test_stale_plan_and_missing_source_completeness_cannot_write(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        $service = app(ControlledUrlTruthReconciliationService::class);
        $records = [$this->record('alpha')];
        $metadata = ['revision' => 'v1', 'complete_authority_read' => true];
        $dry = $service->run($records, $metadata, false, false);
        $changed = [$this->record('bravo')];
        $result = $service->run($changed, $metadata, true, false, expectedPlanHash: $dry['plan']['plan_hash'],
            authorityReadback: fn () => [$changed, $metadata]);
        $this->assertContains('frozen_plan_mismatch', $result['issues']);
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_urls')->count());
        $result = $service->run($records, [], true, false);
        $this->assertContains('complete_authority_required', $result['issues']);
    }

    public function test_changed_authority_at_final_readback_rolls_back_every_write(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        $service = app(ControlledUrlTruthReconciliationService::class);
        $records = [$this->record('alpha')];
        $metadata = ['complete_authority_read' => true];
        $dry = $service->run($records, $metadata, false, false);
        $reads = 0;
        try {
            $service->run($records, $metadata, true, false, expectedPlanHash: $dry['plan']['plan_hash'],
                authorityReadback: function () use (&$reads, $records, $metadata): array {
                    return [++$reads === 1 ? $records : [$this->record('changed')], $metadata];
                });
            $this->fail('Source drift must abort the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('URL_TRUTH_AUTHORITY_CHANGED', $exception->getMessage());
        }
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_urls')->count());
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_url_entities')->count());
    }

    public static function rollbackFailures(): array
    {
        return [
            'middle batch' => ["CREATE TRIGGER break_write BEFORE INSERT ON seo_urls WHEN NEW.entity_id_or_slug = 'bravo' BEGIN SELECT RAISE(ABORT, 'synthetic_failure'); END"],
            'truth readback' => ["CREATE TRIGGER break_write AFTER INSERT ON seo_urls BEGIN UPDATE seo_urls SET authority_revision = 'wrong' WHERE id = NEW.id; END"],
            'binding readback' => ["CREATE TRIGGER break_write AFTER INSERT ON seo_url_entities BEGIN UPDATE seo_url_entities SET canonical_revision = 'wrong' WHERE id = NEW.id; END"],
            'second pass' => ["CREATE TRIGGER break_write AFTER UPDATE ON seo_urls WHEN NEW.entity_id_or_slug != 'orphan' BEGIN UPDATE seo_urls SET authority_revision = 'wrong' WHERE id = NEW.id; END"],
            'retirement' => ["CREATE TRIGGER break_write BEFORE UPDATE ON seo_urls WHEN NEW.indexability_state = 'retired_authority' BEGIN SELECT RAISE(ABORT, 'synthetic_failure'); END"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rollbackFailures')]
    public function test_validation_failures_rollback_the_entire_cohort_and_preserve_history(string $trigger): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        $writer = new UrlTruthInventoryRecordWriter;
        $writer->write([$this->record('orphan')]);
        $db = DB::connection('seo_intel');
        $before = [$db->table('seo_urls')->get()->toJson(), $db->table('seo_url_entities')->get()->toJson()];
        $db->unprepared($trigger);
        try {
            $this->reconcile(app(ControlledUrlTruthReconciliationService::class),
                [$this->record('alpha'), $this->record('bravo')], ['revision' => 'v1'], true, false, 100, 1);
            $this->fail('Synthetic failure must roll back the full cohort.');
        } catch (\RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, [$db->table('seo_urls')->get()->toJson(), $db->table('seo_url_entities')->get()->toJson()]);
        $this->assertSame(0, $db->table('seo_issue_queue')->count());
    }

    public function test_rebinding_retains_old_identity_and_second_execution_is_semantic_noop(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        $current = $this->record('alpha');
        $old = new UrlTruthInventoryRecord(canonicalUrl: $current->canonicalUrl, locale: 'en',
            pageEntityType: 'career_job', entityIdOrSlug: 'old-alpha', sourceAuthority: $current->sourceAuthority,
            entitySource: $current->entitySource, authorityStatus: 'published_approved', metadata: $current->metadata);
        (new UrlTruthInventoryRecordWriter)->write([$old]);
        $service = app(ControlledUrlTruthReconciliationService::class);
        $this->reconcile($service, [$current], [], true, false, 100, 1);
        $again = $this->reconcile($service, [$current], [], true, false, 100, 1);
        $db = DB::connection('seo_intel');
        $this->assertSame(2, $db->table('seo_url_entities')->count());
        $this->assertNull($db->table('seo_url_entities')->where('entity_id_or_slug', 'old-alpha')->value('current_binding_key'));
        $this->assertSame(1, $db->table('seo_url_entities')->whereNotNull('current_binding_key')->count());
        $this->assertSame(0, $again['plan']['counts']['added']);
        $this->assertSame(0, $again['plan']['counts']['updated']);
        $this->assertTrue($again['idempotent_rerun']['passed']);
    }

    public function test_detector_failure_rolls_back_truth_bindings_and_retirement(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true,
            'seo_intel.public_canonical_host' => 'https://fermatmind.com', 'app.public_api_url' => 'https://api.fermatmind.com']);
        Http::fake(static function ($request) {
            return str_ends_with($request->url(), '/sitemap.xml')
                ? Http::response('<urlset><url><loc>https://fermatmind.com/en/sitemap-only</loc></url></urlset>', 200)
                : Http::response(['data' => []], 200);
        });
        $db = DB::connection('seo_intel');
        $db->unprepared("CREATE TRIGGER break_detector BEFORE INSERT ON seo_issue_queue BEGIN SELECT RAISE(ABORT, 'synthetic_detector_failure'); END");
        try {
            $this->reconcile(app(ControlledUrlTruthReconciliationService::class), [$this->record('alpha')], [], true, true, 100, 1);
            $this->fail('Detector failure must roll back Truth.');
        } catch (\RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        foreach (['seo_urls', 'seo_url_entities', 'seo_issue_queue'] as $table) {
            $this->assertSame(0, $db->table($table)->count());
        }
    }

    public function test_same_url_with_conflicting_formal_identities_cannot_retire_existing_truth(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        (new UrlTruthInventoryRecordWriter)->write([$this->record('existing')]);
        $record = $this->record('alpha');
        $conflict = new UrlTruthInventoryRecord(canonicalUrl: $record->canonicalUrl, locale: $record->locale,
            pageEntityType: $record->pageEntityType, entityIdOrSlug: 'different-identity',
            sourceAuthority: $record->sourceAuthority, entitySource: $record->entitySource,
            authorityStatus: 'published_approved', metadata: $record->metadata, attributes: $record->attributes);
        try {
            $this->reconcile(app(ControlledUrlTruthReconciliationService::class), [$record, $conflict], [], true, false, 100, 1);
            $this->fail('Conflicting authority cannot produce a writable plan.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('PUBLIC_AUTHORITY_IDENTITY_CONFLICT', $exception->getMessage());
        }
        $this->assertSame('indexable', DB::connection('seo_intel')->table('seo_urls')->sole()->indexability_state);
        $this->assertSame(1, DB::connection('seo_intel')->table('seo_url_entities')->whereNotNull('current_binding_key')->count());
    }

    public function test_scoped_cli_write_configuration_is_restored_on_source_failure(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => false]);
        $this->artisan('seo-intel:url-truth-controlled-reconcile', ['--execute' => true,
            '--maintenance' => true, '--scoped-write' => true, '--no-http' => true, '--json' => true])->assertFailed();
        $this->assertFalse(config('seo_intel.write_enabled'));
        $this->assertSame(0, DB::connection('seo_intel')->table('seo_urls')->count());
    }

    private function reconcile(ControlledUrlTruthReconciliationService $service, array $records, array $metadata, bool $execute, bool $probe, int $max, int $batch): array
    {
        $metadata['complete_authority_read'] = true;
        $dry = $service->run($records, $metadata, false, $probe, $max, $batch);

        return $execute ? $service->run($records, $metadata, true, $probe, $max, $batch,
            $dry['plan']['plan_hash'] ?? null, fn () => [$records, $metadata]) : $dry;
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
