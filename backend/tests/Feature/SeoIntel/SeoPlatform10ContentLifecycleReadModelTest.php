<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\OpsDashboard\ContentLifecycleReadService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SeoPlatform10ContentLifecycleReadModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.seo_intel' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            'seo_intel.connection' => 'seo_intel',
        ]);
        DB::purge('seo_intel');

        Schema::connection('seo_intel')->create('seo_urls', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('material_decision_id')->nullable();
            $table->char('canonical_url_hash', 64);
            $table->char('material_fingerprint', 64)->nullable();
            $table->timestamp('material_lastmod_at')->nullable();
            $table->string('material_lastmod_source')->nullable();
            $table->string('material_authority_state')->nullable();
        });
        Schema::connection('seo_intel')->create('seo_issue_queue', function (Blueprint $table): void {
            $table->id();
            $table->char('canonical_url_hash', 64)->nullable();
            $table->string('issue_type');
            $table->string('status');
            $table->string('lifecycle_state');
            $table->json('metadata_json')->nullable();
            $table->timestamp('detected_at')->nullable();
        });
    }

    public function test_read_model_is_paginated_bilingual_traceable_and_sanitized(): void
    {
        $enId = $this->decision('en', '/en/articles/example', 'article-r1', 'review:evidence:secret');
        $zhId = $this->decision('zh-CN', '/zh/articles/example', 'article-r2', 'review:evidence:zh-secret');
        $enHash = hash('sha256', 'https://fermatmind.com/en/articles/example');
        $zhHash = hash('sha256', 'https://fermatmind.com/zh/articles/example');
        $this->url($enId, $enHash, '2026-08-20 00:00:00');
        $this->url($zhId, $zhHash, null, 'hold');
        DB::connection('seo_intel')->table('seo_issue_queue')->insert([
            'canonical_url_hash' => $enHash,
            'issue_type' => 'content_decay_candidate',
            'status' => 'open',
            'lifecycle_state' => 'held',
            'metadata_json' => json_encode([
                'recommended_action' => 'merge',
                'detector_result' => [
                    'outcome' => 'measurement_hold',
                    'authority_revision' => 'gsc-evidence-r1',
                    'raw_query' => 'must-not-escape',
                ],
            ], JSON_THROW_ON_ERROR),
            'detected_at' => '2026-08-28 00:00:00',
        ]);

        $service = new ContentLifecycleReadService('seo_intel');
        $first = $service->read(1, 1);
        $second = $service->read(2, 1);

        $this->assertSame('production_proven', $first['state']);
        $this->assertSame(2, data_get($first, 'pagination.total'));
        $this->assertSame(2, data_get($first, 'pagination.last_page'));
        $this->assertSame('zh-CN', data_get($first, 'rows.0.locale'));
        $this->assertNull(data_get($first, 'rows.0.material_lastmod'));
        $this->assertSame('hold', data_get($first, 'rows.0.material_authority_state'));
        $this->assertSame('en', data_get($second, 'rows.0.locale'));
        $this->assertSame('article-r1', data_get($second, 'rows.0.revision.value'));
        $this->assertSame('evidence_bound', data_get($second, 'rows.0.review.state'));
        $this->assertNull(data_get($second, 'rows.0.review.reviewed_at'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($second, 'rows.0.review.evidence_ref_hash'));
        $this->assertSame('2026-08-20 00:00:00', data_get($second, 'rows.0.material_lastmod'));
        $this->assertSame('hold', data_get($second, 'rows.0.candidate.status'));
        $this->assertSame('merge', data_get($second, 'rows.0.candidate.recommended_action'));
        $this->assertSame('gsc-evidence-r1', data_get($second, 'rows.0.candidate.evidence_revision'));
        $this->assertTrue(data_get($second, 'boundaries.read_only'));

        $encoded = json_encode($second, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('/en/articles/example', $encoded);
        $this->assertStringNotContainsString('review:evidence:secret', $encoded);
        $this->assertStringNotContainsString('must-not-escape', $encoded);
    }

    public function test_locale_filter_and_null_candidate_are_explicit(): void
    {
        $this->decision('en', '/en/career/jobs/example', 'career-r1', 'career-review-r1', 'career');
        $this->decision('zh-CN', '/zh/career/jobs/example', 'career-r2', 'career-review-r2', 'career');

        $result = (new ContentLifecycleReadService('seo_intel'))->read(1, 25, 'en');

        $this->assertSame(1, data_get($result, 'pagination.total'));
        $this->assertSame('en', data_get($result, 'rows.0.locale'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($result, 'rows.0.fingerprint'));
        $this->assertNull(data_get($result, 'rows.0.material_lastmod'));
        $this->assertSame('not_observed', data_get($result, 'rows.0.candidate.status'));
        $this->assertNull(data_get($result, 'rows.0.candidate.recommended_action'));
    }

    private function decision(string $locale, string $identity, string $revision, string $evidence, string $family = 'article'): int
    {
        return (int) DB::table('content_material_decisions')->insertGetId([
            'org_id' => 0,
            'family' => $family,
            'locale' => $locale,
            'authority_subject_key' => hash('sha256', $identity),
            'public_identity' => $identity,
            'previous_public_identity' => null,
            'authority_revision_kind' => $family.'_published_revision',
            'authority_revision' => $revision,
            'material_fingerprint' => hash('sha256', $revision),
            'previous_material_fingerprint' => null,
            'publication_state' => 'published',
            'operation' => 'publish',
            'decision_code' => 'material_changed',
            'material_changed' => true,
            'material_changed_at' => '2026-08-20 00:00:00',
            'evidence_ref' => $evidence,
            'decision_key' => hash('sha256', $locale.'|'.$revision),
            'created_at' => '2026-08-20 00:00:00',
            'updated_at' => '2026-08-20 00:00:00',
        ]);
    }

    private function url(int $decisionId, string $canonicalHash, ?string $lastmod, string $state = 'trusted'): void
    {
        DB::connection('seo_intel')->table('seo_urls')->insert([
            'material_decision_id' => $decisionId,
            'canonical_url_hash' => $canonicalHash,
            'material_fingerprint' => hash('sha256', 'url-'.$decisionId),
            'material_lastmod_at' => $lastmod,
            'material_lastmod_source' => $lastmod === null ? null : 'material_fingerprint.v1:article_published_revision',
            'material_authority_state' => $state,
        ]);
    }
}
