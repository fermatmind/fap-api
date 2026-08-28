<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\ContentMaterialDecision;
use App\Services\SeoIntel\UrlTruth\MaterialAuthorityUrlTruthBackfillService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SeoPlatform10MaterialUrlTruthBackfillTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::purge('sqlite');
        DB::purge('seo_intel');
        parent::tearDown();
    }

    #[Test]
    public function dry_run_canary_readback_and_rerun_are_bounded_and_idempotent_while_unknown_legacy_stays_on_hold(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        $knownAt = Carbon::parse('2026-08-01T08:30:00Z');
        $known = $this->url('/en/articles/known', 'article', 'en');
        $legacy = $this->url('/zh/career/jobs/legacy', 'career', 'zh-CN');
        $topicOutsideAdapterScope = $this->url('/en/topics/not-an-article', 'article', 'en');
        $topicOutsideAdapterScope['page_entity_type'] = 'topic';
        DB::connection('seo_intel')->table('seo_urls')->insert([$known, $legacy, $topicOutsideAdapterScope]);
        $decision = $this->decision('/en/articles/known', 'article', 'en', $knownAt);
        $service = new MaterialAuthorityUrlTruthBackfillService;

        $dryRun = $service->run(false, 10, 1);

        self::assertSame('success', $dryRun['status']);
        self::assertSame('dry_run', $dryRun['mode']);
        self::assertSame(['apply' => 1, 'retire' => 0, 'hold' => 1, 'no_change' => 0], data_get($dryRun, 'plan.counts'));
        self::assertSame(2, data_get($dryRun, 'artifact.record_count'));
        self::assertFalse($dryRun['writes_committed']);
        self::assertSame('hold', DB::connection('seo_intel')->table('seo_urls')->where('id', 1)->value('material_authority_state'));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($dryRun, 'artifact.artifact_hash'));
        self::assertSame(
            ['family', 'locale', 'public_identity', 'authority_revision', 'material_fingerprint', 'material_changed_at', 'decision_code', 'evidence_ref'],
            data_get($dryRun, 'artifact.event_field_contract'),
        );
        self::assertFalse((bool) data_get($dryRun, 'boundaries.sitemap_can_create_authority', true));
        self::assertFalse((bool) data_get($dryRun, 'boundaries.llms_can_create_authority', true));
        self::assertFalse((bool) data_get($dryRun, 'boundaries.cache_can_create_authority', true));
        self::assertFalse((bool) data_get($dryRun, 'boundaries.runtime_can_create_authority', true));

        $execute = $service->run(true, 10, 1);

        self::assertSame('success', $execute['status']);
        self::assertSame('controlled_write', $execute['mode']);
        self::assertTrue($execute['writes_committed']);
        self::assertSame(data_get($dryRun, 'artifact.artifact_hash'), data_get($execute, 'artifact.artifact_hash'));
        self::assertSame('canary', data_get($execute, 'readback.0.stage'));
        self::assertTrue((bool) data_get($execute, 'readback.0.passed'));
        self::assertTrue((bool) data_get($execute, 'idempotent_rerun.passed'));
        self::assertSame(0, data_get($execute, 'idempotent_rerun.apply'));
        self::assertSame(1, data_get($execute, 'idempotent_rerun.hold'));
        self::assertSame(0, data_get($execute, 'idempotent_rerun.pending_writes'));

        $projected = DB::connection('seo_intel')->table('seo_urls')->where('id', 1)->first();
        self::assertSame((string) $decision->material_fingerprint, $projected->material_fingerprint);
        self::assertSame((string) $decision->decision_key, $projected->material_decision_key);
        self::assertSame('material_fingerprint.v1:article_translation_revision', $projected->material_lastmod_source);
        self::assertSame('trusted', $projected->material_authority_state);
        self::assertSame('2026-08-01 08:30:00', $projected->material_lastmod_at);
        self::assertSame('2026-01-01 00:00:00', $projected->lastmod_at);
        self::assertSame('2026-01-02 00:00:00', $projected->updated_at);

        $legacyAfter = DB::connection('seo_intel')->table('seo_urls')->where('id', 2)->first();
        self::assertSame('hold', $legacyAfter->material_authority_state);
        self::assertNull($legacyAfter->material_fingerprint);
        self::assertNull($legacyAfter->material_lastmod_at);

        $rerun = $service->run(true, 10, 1);
        $projectedAfterRerun = DB::connection('seo_intel')->table('seo_urls')->where('id', 1)->first();
        self::assertSame(['apply' => 0, 'retire' => 0, 'hold' => 1, 'no_change' => 1], data_get($rerun, 'plan.counts'));
        self::assertSame($projected->material_fingerprint, $projectedAfterRerun->material_fingerprint);
        self::assertSame($projected->material_lastmod_at, $projectedAfterRerun->material_lastmod_at);
        self::assertSame($projected->updated_at, $projectedAfterRerun->updated_at);
        self::assertSame(data_get($execute, 'projection_state.projection_digest'), data_get($rerun, 'projection_state.projection_digest'));
        self::assertSame(1, data_get($rerun, 'projection_state.state_counts.hold'));
        self::assertFalse((bool) data_get($rerun, 'projection_state.raw_urls_emitted', true));
    }

    #[Test]
    public function unpublished_material_event_retires_url_truth_and_unknown_fingerprint_cannot_replace_trusted_projection(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        DB::connection('seo_intel')->table('seo_urls')->insert($this->url('/zh/articles/retired', 'article', 'zh-CN'));
        $publishedAt = Carbon::parse('2026-07-01T00:00:00Z');
        $this->decision('/zh/articles/retired', 'article', 'zh-CN', $publishedAt);
        $service = new MaterialAuthorityUrlTruthBackfillService;
        self::assertSame('success', $service->run(true, 10, 1)['status']);
        $trusted = DB::connection('seo_intel')->table('seo_urls')->first();

        ContentMaterialDecision::query()->create([
            'org_id' => 0,
            'family' => 'article',
            'locale' => 'zh-CN',
            'authority_subject_key' => hash('sha256', 'retired'),
            'public_identity' => '/zh/articles/retired',
            'previous_public_identity' => '/zh/articles/retired',
            'authority_revision_kind' => 'article_translation_revision',
            'authority_revision' => 'unknown',
            'material_fingerprint' => null,
            'previous_material_fingerprint' => $trusted->material_fingerprint,
            'publication_state' => 'unpublished',
            'operation' => 'unpublish',
            'decision_code' => 'unpublish_hold_unknown_legacy_fingerprint',
            'material_changed' => false,
            'material_changed_at' => null,
            'evidence_ref' => 'article:legacy:unpublish',
            'decision_key' => hash('sha256', 'unknown-unpublish'),
        ]);

        $hold = $service->run(true, 10, 1);
        $retained = DB::connection('seo_intel')->table('seo_urls')->first();
        self::assertSame(1, data_get($hold, 'plan.counts.hold'));
        self::assertSame($trusted->material_fingerprint, $retained->material_fingerprint);
        self::assertSame($trusted->material_lastmod_at, $retained->material_lastmod_at);
        self::assertSame('hold', $retained->material_authority_state);

        ContentMaterialDecision::query()->create([
            'org_id' => 0,
            'family' => 'article',
            'locale' => 'zh-CN',
            'authority_subject_key' => hash('sha256', 'retired'),
            'public_identity' => '/zh/articles/retired',
            'previous_public_identity' => '/zh/articles/retired',
            'authority_revision_kind' => 'article_translation_revision',
            'authority_revision' => '42',
            'material_fingerprint' => $trusted->material_fingerprint,
            'previous_material_fingerprint' => $trusted->material_fingerprint,
            'publication_state' => 'unpublished',
            'operation' => 'unpublish',
            'decision_code' => 'unpublish',
            'material_changed' => true,
            'material_changed_at' => Carbon::parse('2026-08-02T00:00:00Z'),
            'evidence_ref' => 'article:42:unpublish',
            'decision_key' => hash('sha256', 'known-unpublish'),
        ]);

        $retire = $service->run(true, 10, 1);
        $retired = DB::connection('seo_intel')->table('seo_urls')->first();
        self::assertSame(1, data_get($retire, 'plan.counts.retire'));
        self::assertSame('retired', $retired->material_authority_state);
        self::assertSame('retired_material_authority', $retired->indexability_state);
        self::assertSame('2026-08-02 00:00:00', $retired->material_lastmod_at);
    }

    #[Test]
    public function record_bound_fails_closed_before_any_write(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        DB::connection('seo_intel')->table('seo_urls')->insert([
            $this->url('/en/articles/one', 'article', 'en'),
            $this->url('/en/articles/two', 'article', 'en'),
        ]);

        $receipt = (new MaterialAuthorityUrlTruthBackfillService)->run(true, 1, 1);

        self::assertSame('blocked', $receipt['status']);
        self::assertSame(['legacy_url_bound_exceeded'], $receipt['issues']);
        self::assertFalse($receipt['writes_committed']);
        self::assertSame(0, DB::connection('seo_intel')->table('seo_urls')->whereNotNull('material_fingerprint')->count());
    }

    #[Test]
    public function execute_fails_closed_when_the_existing_url_truth_write_lane_is_disabled(): void
    {
        $this->prepareSchema();
        config(['seo_intel.enabled' => true, 'seo_intel.write_enabled' => false]);
        DB::connection('seo_intel')->table('seo_urls')->insert($this->url('/en/articles/known', 'article', 'en'));
        $this->decision('/en/articles/known', 'article', 'en', Carbon::parse('2026-08-01T08:30:00Z'));

        $receipt = (new MaterialAuthorityUrlTruthBackfillService)->run(true, 10, 1);

        self::assertSame('blocked', $receipt['status']);
        self::assertSame(['seo_intel_write_flags_disabled'], $receipt['issues']);
        self::assertFalse($receipt['writes_committed']);
        self::assertNull(DB::connection('seo_intel')->table('seo_urls')->value('material_fingerprint'));
    }

    private function prepareSchema(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'seo_intel.connection' => 'seo_intel',
            'database.connections.seo_intel' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
        ]);
        DB::purge('sqlite');
        DB::purge('seo_intel');
        $materialMigration = require dirname(__DIR__, 3).'/database/migrations/2026_08_28_010000_create_content_material_decisions_table.php';
        $materialMigration->up();
        DB::setDefaultConnection('seo_intel');
        foreach (['2026_05_17_000100_create_seo_urls_table.php', '2026_08_25_020000_expand_url_truth_current_bindings.php', '2026_08_28_030000_expand_url_truth_material_authority.php'] as $file) {
            $migration = require dirname(__DIR__, 3).'/database/migrations/seo_intel/'.$file;
            $migration->up();
        }
        DB::setDefaultConnection('sqlite');
    }

    /** @return array<string,mixed> */
    private function url(string $path, string $family, string $locale): array
    {
        $now = Carbon::parse('2026-01-02T00:00:00Z');

        return [
            'canonical_url_hash' => hash('sha256', 'https://fermatmind.com'.$path),
            'canonical_url' => 'https://fermatmind.com'.$path,
            'locale' => $locale,
            'page_entity_type' => $family === 'career' ? 'career_job' : $family,
            'page_family' => $family === 'article' ? 'articles_topics' : $family,
            'entity_id_or_slug' => basename($path),
            'source_authority' => 'backend_cms',
            'authority_revision' => hash('sha256', 'legacy-revision'),
            'canonical_revision' => hash('sha256', $path),
            'indexability_state' => 'indexable',
            'lastmod_at' => '2026-01-01 00:00:00',
            'lastmod_source' => 'legacy.updated_at',
            'is_private_flow' => false,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function decision(string $identity, string $family, string $locale, Carbon $changedAt): ContentMaterialDecision
    {
        return ContentMaterialDecision::query()->create([
            'org_id' => 0,
            'family' => $family,
            'locale' => $locale,
            'authority_subject_key' => hash('sha256', basename($identity)),
            'public_identity' => $identity,
            'authority_revision_kind' => $family === 'article' ? 'article_translation_revision' : $family.'_revision',
            'authority_revision' => 'revision-1',
            'material_fingerprint' => hash('sha256', 'material|'.$identity),
            'publication_state' => 'published',
            'operation' => 'publish',
            'decision_code' => 'initial_publish',
            'material_changed' => true,
            'material_changed_at' => $changedAt,
            'evidence_ref' => $family.':fixture:1',
            'decision_key' => hash('sha256', 'decision|'.$identity),
        ]);
    }
}
