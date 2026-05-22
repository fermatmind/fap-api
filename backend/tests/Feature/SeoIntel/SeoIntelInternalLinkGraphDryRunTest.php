<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Models\Article;
use App\Models\ArticleSeoMeta;
use App\Services\SeoIntel\InternalLink\InternalLinkGraphDryRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class SeoIntelInternalLinkGraphDryRunTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function service_reports_graph_readiness_without_writes_or_link_mutation(): void
    {
        $article = $this->createArticleWithSeoMeta([
            'related_test_slug' => 'mbti-personality-test',
            'target_tests' => ['big-five-personality-test', 'enneagram-test'],
            'target_topics' => ['personality-at-work'],
        ]);

        $before = $this->articleSnapshot($article);
        $report = (new InternalLinkGraphDryRun)->report();

        $this->assertSame('internal_link_graph_dry_run', $report['runtime']);
        $this->assertSame('success', $report['status']);
        $this->assertTrue($report['dry_run']);
        $this->assertTrue($report['no_write']);
        $this->assertFalse($report['writes_attempted']);
        $this->assertFalse($report['cms_mutation_attempted']);
        $this->assertFalse($report['link_mutation_attempted']);
        $this->assertFalse($report['fap_web_modification_attempted']);
        $this->assertFalse($report['crawler_log_read_attempted']);
        $this->assertFalse($report['crawler_log_authority_claimed']);
        $this->assertFalse($report['sitemap_graph_truth_claimed']);
        $this->assertFalse($report['frontend_fallback_authority_claimed']);
        $this->assertFalse($report['search_channel_enqueue_attempted']);
        $this->assertFalse($report['search_submission_attempted']);
        $this->assertFalse($report['observation_queue_write_attempted']);

        $this->assertSame(1, $report['source_inventory']['cms_backend_authoritative']['article_related_test_slug']);
        $this->assertSame(2, $report['source_inventory']['cms_backend_authoritative']['article_editorial_package_target_tests']);
        $this->assertSame(1, $report['source_inventory']['cms_backend_authoritative']['article_editorial_package_target_topics']);
        $this->assertSame(3, $report['graph_family_counts']['article_to_test']);
        $this->assertSame(1, $report['graph_family_counts']['article_to_topic']);
        $this->assertSame(4, $report['candidate_opportunity_count']);
        $this->assertSame(0, $report['unsafe_fallback_source_count']);

        $this->assertSame($before, $this->articleSnapshot($article));
    }

    #[Test]
    public function service_marks_missing_entity_keys_as_legacy_unpaired_without_title_slug_authority(): void
    {
        $article = $this->createArticleWithSeoMeta();
        DB::table('articles')
            ->where('id', (int) $article->id)
            ->update(['translation_group_id' => '']);

        $report = (new InternalLinkGraphDryRun)->report();

        $this->assertSame(1, $report['missing_entity_key_count']);
        $this->assertGreaterThanOrEqual(1, $report['legacy_unpaired_count']);
        $this->assertSame('translation_group_uuid', $report['entity_key_policy']['preferred']);
        $this->assertSame('migration_helper_only_not_authority', $report['entity_key_policy']['title_slug_similarity']);
        $this->assertContains('missing_entity_key', array_column($report['warnings'], 'code'));
        $this->assertContains('legacy_unpaired', array_column($report['warnings'], 'code'));
    }

    #[Test]
    public function command_requires_dry_run_no_write_and_json(): void
    {
        $exitCode = Artisan::call('seo-intel:internal-link-graph');

        $this->assertSame(Command::FAILURE, $exitCode);

        $output = Artisan::output();

        $this->assertStringContainsString('status=blocked', $output);
        $this->assertStringContainsString('dry_run=0', $output);
        $this->assertStringContainsString('no_write=0', $output);
        $this->assertStringContainsString('writes_attempted=0', $output);
    }

    #[Test]
    public function command_emits_json_report_without_creating_links(): void
    {
        $article = $this->createArticleWithSeoMeta([
            'related_test_slug' => 'mbti-personality-test',
        ]);
        $before = $this->articleSnapshot($article);

        $exitCode = Artisan::call('seo-intel:internal-link-graph', [
            '--dry-run' => true,
            '--no-write' => true,
            '--json' => true,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);

        $payload = $this->artisanJsonOutput();

        $this->assertSame('internal_link_graph_dry_run', $payload['runtime']);
        $this->assertTrue($payload['dry_run']);
        $this->assertTrue($payload['no_write']);
        $this->assertFalse($payload['writes_attempted']);
        $this->assertFalse($payload['cms_mutation_attempted']);
        $this->assertFalse($payload['link_mutation_attempted']);
        $this->assertFalse($payload['search_channel_enqueue_attempted']);
        $this->assertFalse($payload['search_submission_attempted']);
        $this->assertSame(1, $payload['graph_family_counts']['article_to_test']);

        $this->assertSame($before, $this->articleSnapshot($article));
    }

    #[Test]
    public function command_help_exposes_no_write_create_submit_or_scheduler_options(): void
    {
        Artisan::call('seo-intel:internal-link-graph --help');
        $help = Artisan::output();

        foreach ([
            '--write',
            '--create',
            '--apply',
            '--submit',
            '--scheduler',
            '--production',
        ] as $forbiddenOption) {
            $this->assertDoesNotMatchRegularExpression('/(^|\\s)'.preg_quote($forbiddenOption, '/').'(=|\\s|$)/', $help);
        }

        foreach ([
            '--dry-run',
            '--no-write',
            '--json',
        ] as $allowedOption) {
            $this->assertStringContainsString($allowedOption, $help);
        }
    }

    #[Test]
    public function docs_and_generated_artifact_lock_read_only_graph_boundary(): void
    {
        $doc = strtolower((string) file_get_contents(base_path('docs/seo/internal-link-graph-dry-run.md')));
        $artifact = $this->artifact();
        $artifactJson = strtolower((string) json_encode($artifact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $this->assertSame('internal-link-graph-dry-run.v1', $artifact['version'] ?? null);
        $this->assertSame('INTERNAL-LINK-01B', $artifact['task'] ?? null);
        $this->assertSame('CLAIM-LINT-01A', $artifact['next_task'] ?? null);
        $this->assertSame('App\\Services\\SeoIntel\\InternalLink\\InternalLinkGraphDryRun', $artifact['service'] ?? null);
        $this->assertTrue($artifact['safety_flags']['no_cms_mutation'] ?? false);
        $this->assertTrue($artifact['safety_flags']['no_link_mutation'] ?? false);
        $this->assertTrue($artifact['safety_flags']['no_fap_web_modification'] ?? false);
        $this->assertTrue($artifact['safety_flags']['no_crawler_log_read'] ?? false);
        $this->assertTrue($artifact['safety_flags']['no_auto_link_creation'] ?? false);

        foreach ([
            'dry-run/read model',
            'does not mutate cms content',
            'does not create internal links',
            'does not modify fap-web',
            'does not read crawler logs',
            'does not use sitemap or `llms.txt` as graph truth',
            'does not enqueue search channel rows',
            'does not submit urls',
            'translation_group_uuid',
            'migration helper only',
            'next task: `claim-lint-01a`',
            '"next_task":"claim-lint-01a"',
        ] as $required) {
            $this->assertStringContainsString($required, $doc."\n".$artifactJson);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createArticleWithSeoMeta(array $overrides = []): Article
    {
        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'slug' => (string) ($overrides['slug'] ?? 'internal-link-draft'),
            'locale' => (string) ($overrides['locale'] ?? 'en'),
            'title' => 'Internal Link Draft',
            'excerpt' => 'Internal link graph dry-run fixture.',
            'content_md' => '# Internal Link Draft',
            'content_html' => '<h1>Internal Link Draft</h1>',
            'related_test_slug' => (string) ($overrides['related_test_slug'] ?? ''),
            'status' => 'draft',
            'lifecycle_state' => Article::LIFECYCLE_ACTIVE,
            'is_public' => false,
            'is_indexable' => false,
            'translation_group_id' => (string) ($overrides['translation_group_id'] ?? 'tg-internal-link'),
        ]);

        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => (int) $article->id,
            'locale' => (string) $article->locale,
            'seo_title' => 'Internal Link Draft',
            'seo_description' => 'Internal link graph dry-run fixture.',
            'canonical_url' => 'https://fermatmind.com/en/articles/internal-link-draft',
            'robots' => 'noindex,follow',
            'is_indexable' => false,
            'schema_json' => [
                'editorial_package_v1' => [
                    'target_tests' => (array) ($overrides['target_tests'] ?? []),
                    'target_topics' => (array) ($overrides['target_topics'] ?? []),
                ],
            ],
        ]);

        return $article->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function articleSnapshot(Article $article): array
    {
        $fresh = Article::query()->withoutGlobalScopes()->findOrFail((int) $article->id);

        return [
            'status' => (string) $fresh->status,
            'is_public' => (bool) $fresh->is_public,
            'is_indexable' => (bool) $fresh->is_indexable,
            'related_test_slug' => (string) $fresh->related_test_slug,
            'translation_group_id' => (string) $fresh->translation_group_id,
            'published_revision_id' => $fresh->published_revision_id,
            'published_at' => $fresh->published_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function artisanJsonOutput(): array
    {
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function artifact(): array
    {
        $decoded = json_decode((string) file_get_contents(base_path('docs/seo/generated/internal-link-graph-dry-run.v1.json')), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
