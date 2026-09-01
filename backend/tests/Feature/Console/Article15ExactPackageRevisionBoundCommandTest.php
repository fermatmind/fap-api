<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Events\PublicAuthorityChanged;
use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\Article15ExactPackageRevisionBoundAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class Article15ExactPackageRevisionBoundCommandTest extends TestCase
{
    use RefreshDatabase;

    private const MANIFEST_SHA = '6fa5fb22df81062fed26ebf7743cd24c0e42f67c28d5b5ef2d61f7ec7fd3e13c';

    public function test_seeded_current_state_matches_public_article_projection(): void
    {
        $this->seedBatch('ALL');

        foreach ($this->targets('ALL') as $target) {
            $response = $this->getJson(
                '/api/v0.5/articles/'.$target['slug'].'?locale='.$target['locale'].'&org_id=0'
            )->assertOk();
            $faq = array_map(static fn (array $item): array => [
                'question' => (string) ($item['question'] ?? ''),
                'answer' => (string) ($item['answer'] ?? ''),
            ], (array) $response->json('answer_surface_v1.faq_blocks'));
            $this->assertSame(
                data_get($target, 'package.current_to_proposed.answer_surface_v1.current.faq_items'),
                $faq,
                'FAQ projection mismatch for article '.(string) $target['article_id']
            );
            $this->assertSame(
                data_get($target, 'package.current_to_proposed.primary_cta.current'),
                $response->json('answer_surface_v1.next_step_blocks'),
                'CTA projection mismatch for article '.(string) $target['article_id']
            );
            $this->assertSame(
                data_get($target, 'package.current_to_proposed.seo_title.current'),
                $response->json('article.seo_meta.seo_title'),
                'SEO title projection mismatch for article '.(string) $target['article_id']
            );
            $this->assertSame(
                data_get($target, 'package.current_to_proposed.seo_description.current'),
                $response->json('article.seo_meta.seo_description'),
                'SEO description projection mismatch for article '.(string) $target['article_id']
            );
        }
    }

    public function test_snapshot_then_locked_preflight_emit_complete_zero_write_contract_for_all_fifteen_targets(): void
    {
        $this->seedBatch('ALL');
        $before = $this->publicFingerprint('ALL');

        $exitCode = Artisan::call('articles:article15-exact-package', $this->snapshotOptions());
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        $snapshot = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['state_sha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $snapshot['revision_set_sha256']);
        $options = $this->commandOptions('preflight', 'ALL');
        $options['--expected-state-sha256'] = $snapshot['state_sha256'];
        $options['--expected-revision-set-sha256'] = $snapshot['revision_set_sha256'];

        $this->assertSame(0, Artisan::call('articles:article15-exact-package', $options));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['ok']);
        $this->assertSame(15, $payload['target']);
        $this->assertSame(15, $payload['target_count']);
        $this->assertSame(0, $payload['unknown']);
        $this->assertSame(0, $payload['revision_drift']);
        $this->assertSame(0, $payload['package_sha_mismatch']);
        $this->assertSame(0, $payload['revision_body_drift']);
        $this->assertSame(0, $payload['public_body_drift']);
        $this->assertSame(0, $payload['keep_body_writes']);
        $this->assertSame(15, $payload['approved']);
        $this->assertSame('article-public-payload.v1', $payload['projection_contract_version']);
        $this->assertSame(0, $payload['public_mutations']);
        $this->assertSame($snapshot['database_row_counts'], $payload['database_row_counts']);
        $this->assertSame([
            'articles' => 15,
            'article_seo_meta' => 15,
            'article_translation_revisions' => 15,
            'article_editorial_package_imports' => 0,
        ], $payload['database_row_counts']);
        $this->assertSame(['CHANGE' => 9, 'KEEP' => 6], $payload['field_counts']['declared']['body_markdown']);
        $this->assertSame(['changed' => 2, 'unchanged' => 13], $payload['field_counts']['effective']['title/H1']);
        $this->assertSame(['changed' => 5, 'unchanged' => 10], $payload['field_counts']['effective']['intro']);
        $this->assertSame(['changed' => 9, 'unchanged' => 6], $payload['field_counts']['effective']['body']);
        $this->assertSame(['changed' => 2, 'unchanged' => 13], $payload['field_counts']['effective']['SEO title']);
        $this->assertSame(['changed' => 4, 'unchanged' => 11], $payload['field_counts']['effective']['SEO description']);
        $this->assertSame(['changed' => 10, 'unchanged' => 5], $payload['field_counts']['effective']['FAQ']);
        $this->assertSame(['changed' => 10, 'unchanged' => 5], $payload['field_counts']['effective']['CTA']);
        $this->assertSame(['changed' => 10, 'unchanged' => 5], $payload['field_counts']['effective']['reading minutes']);
        $this->assertSame(['changed' => 4, 'unchanged' => 11], $payload['field_counts']['effective']['related test']);
        $this->assertCount(15, $payload['expected_readback']);
        foreach ($payload['expected_readback'] as $expected) {
            $this->assertSame(1, $expected['cta_count']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $expected['cta_sha256']);
            $this->assertMatchesRegularExpression('~^/(?:en|zh)/~', $expected['cta_canonical_href']);
            $this->assertGreaterThan(0, $expected['faq_count']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $expected['faq_sha256']);
        }
        $this->assertCount(15, array_unique(array_column($this->manifest()['targets'], 'article_id')));
        $this->assertFalse($payload['executed']);
        $this->assertFalse($payload['write_boundaries']['database_write']);
        $this->assertSame($before, $this->publicFingerprint('ALL'));
    }

    public function test_snapshot_counts_missing_identity_and_published_revision_drift_without_writes(): void
    {
        $this->seedBatch('ALL');
        Article::query()->withoutGlobalScopes()->findOrFail(58)->forceDelete();
        $drift = Article::query()->withoutGlobalScopes()->findOrFail(3);
        $drift->forceFill(['published_revision_id' => 72])->saveQuietly();

        $this->assertSame(1, Artisan::call('articles:article15-exact-package', $this->snapshotOptions()));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $payload['unknown']);
        $this->assertSame(1, $payload['revision_drift']);
        $this->assertSame(0, $payload['public_mutations']);
        $this->assertSame(0, ArticleEditorialPackageImport::query()->count());
    }

    public function test_snapshot_fails_closed_on_keep_faq_and_cta_public_drift(): void
    {
        $this->seedBatch('ALL');
        $title = ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', 3)->firstOrFail();
        $title->forceFill(['title' => 'forged KEEP title'])->saveQuietly();
        $faqSeo = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', 58)->firstOrFail();
        $faqSchema = $faqSeo->schema_json;
        data_set($faqSchema, 'editorial_package_v1.answer_surface_policy', 'editor_supplied');
        data_set($faqSchema, 'editorial_package_v1.answer_surface_visibility', 'visible');
        data_set($faqSchema, 'editorial_package_v1.answer_surface_v1.faq_items', [[
            'question' => 'forged question',
            'answer' => 'forged answer',
        ]]);
        $faqSeo->forceFill(['schema_json' => $faqSchema])->saveQuietly();

        $ctaSeo = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', 40)->firstOrFail();
        $ctaSchema = $ctaSeo->schema_json;
        data_set($ctaSchema, 'editorial_package_v1.cta_slots', [[
            'label' => 'forged CTA',
            'href' => '/zh/tests/mbti-personality-test-16-personality-types',
            'kind' => 'start_test',
        ]]);
        $ctaSeo->forceFill(['schema_json' => $ctaSchema])->saveQuietly();

        $this->assertSame(1, Artisan::call('articles:article15-exact-package', $this->snapshotOptions()));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($payload['ok']);
        $this->assertSame(3, $payload['public_authority_drift']);
        $this->assertContains('current_value_drift:title_h1:3', $payload['public_authority_errors']);
        $this->assertContains('current_value_drift:faq:58', $payload['public_authority_errors']);
        $this->assertContains('current_value_drift:primary_cta:40', $payload['public_authority_errors']);
        $this->assertSame(0, $payload['public_mutations']);
    }

    public function test_effective_counts_compare_live_values_to_proposed_values(): void
    {
        $this->seedBatch('ALL');
        $target = $this->targets('ALL')[0];
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', 58)->firstOrFail();
        $revision->forceFill([
            'seo_description' => data_get($target, 'package.current_to_proposed.seo_description.proposed'),
        ])->saveQuietly();

        $this->assertSame(1, Artisan::call('articles:article15-exact-package', $this->snapshotOptions()));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['changed' => 3, 'unchanged' => 12], $payload['field_counts']['effective']['SEO description']);
        $this->assertContains('current_value_drift:seo_description:58', $payload['public_authority_errors']);
    }

    public function test_snapshot_uses_published_revision_seo_instead_of_stale_article_seo_meta(): void
    {
        $this->seedBatch('ALL');
        $seo = ArticleSeoMeta::query()->withoutGlobalScopes()->where('article_id', 3)->firstOrFail();
        $seo->forceFill([
            'seo_title' => 'stale database SEO title',
            'seo_description' => 'stale database SEO description',
        ])->saveQuietly();

        $exitCode = Artisan::call('articles:article15-exact-package', $this->snapshotOptions());
        $output = Artisan::output();
        $this->assertSame(0, $exitCode, $output);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['ok']);
        $this->assertSame(0, $payload['public_authority_drift']);
        $this->assertSame(['changed' => 2, 'unchanged' => 13], $payload['field_counts']['effective']['SEO title']);
        $this->assertSame(['changed' => 4, 'unchanged' => 11], $payload['field_counts']['effective']['SEO description']);
    }

    public function test_locked_preflight_detects_change_between_its_two_observations(): void
    {
        $this->seedBatch('ALL');
        $locks = app(Article15ExactPackageRevisionBoundAdapter::class)->currentLockHashes('ALL');
        $options = $this->commandOptions('preflight', 'ALL');
        $options['--expected-state-sha256'] = $locks['state_sha256'];
        $options['--expected-revision-set-sha256'] = $locks['revision_set_sha256'];
        $retrieved = 0;
        Article::retrieved(function () use (&$retrieved): void {
            $retrieved++;
            if ($retrieved === 15) {
                Article::query()->withoutGlobalScopes()->whereKey(58)->update(['updated_at' => now()->addSecond()]);
            }
        });
        $this->assertSame(1, Artisan::call('articles:article15-exact-package', $options));
        $this->assertStringContainsString('preflight_observation_drift', Artisan::output());
        $this->assertSame(0, ArticleEditorialPackageImport::query()->count());
    }

    public function test_package_and_body_digest_mismatch_are_target_level_failures(): void
    {
        $target = $this->targets('A')[0];
        $adapter = app(Article15ExactPackageRevisionBoundAdapter::class);
        $bodyPath = dirname(base_path()).'/'.dirname($target['package_path']).'/'.basename((string) data_get($target, 'package.body_write_plan.proposed_cms_file'));
        $body = file_get_contents($bodyPath);
        $this->assertIsString($body);
        $this->assertTrue($adapter->packageDigestMatches($target, $target['raw_package'], $body));
        $this->assertFalse($adapter->packageDigestMatches($target, [...$target['raw_package'], 'title' => 'forged'], $body));
        $this->assertFalse($adapter->packageDigestMatches($target, $target['raw_package'], 'forged body'));
    }

    public function test_snapshot_reports_package_mismatch_as_failed_target_integrity(): void
    {
        $this->assertSnapshotIntegrityMismatch('package');
    }

    public function test_snapshot_reports_body_mismatch_as_failed_target_integrity(): void
    {
        $this->assertSnapshotIntegrityMismatch('body');
    }

    public function test_approval_manifest_review_and_projection_contract_forgery_fail_closed(): void
    {
        $this->seedBatch('ALL');
        foreach (['approval', 'final_manifest', 'review', 'projection_contract'] as $mutation) {
            $root = $this->isolatedPackageRepository($mutation);
            config(['article15_test.repository_root' => $root]);

            $this->assertSame(1, Artisan::call('articles:article15-exact-package', $this->snapshotOptions()), $mutation);
            $this->assertSame(0, ArticleEditorialPackageImport::query()->count(), $mutation);
            config(['article15_test.repository_root' => null]);
            File::deleteDirectory($root);
        }
    }

    public function test_forged_revision_and_projection_body_locks_fail_closed_independently(): void
    {
        $this->seedBatch('ALL');
        foreach (['revision_lock', 'public_projection_lock'] as $mutation) {
            $root = $this->isolatedPackageRepository($mutation);
            config(['article15_test.repository_root' => $root]);
            $manifest = json_decode(file_get_contents($root.'/'.Article15ExactPackageRevisionBoundAdapter::MANIFEST_PATH), true, 512, JSON_THROW_ON_ERROR);

            $options = $this->snapshotOptions();
            $options['--execution-manifest-sha256'] = $manifest['execution_manifest_sha256'];
            $this->assertSame(1, Artisan::call('articles:article15-exact-package', $options), $mutation);
            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('package_dual_body_lock_mismatch', (string) $payload['error'], $mutation);
            $this->assertSame(0, ArticleEditorialPackageImport::query()->count(), $mutation);

            config(['article15_test.repository_root' => null]);
            File::deleteDirectory($root);
        }
    }

    public function test_target_inventory_rejects_missing_extra_duplicate_and_order_drift(): void
    {
        $targets = $this->manifest()['targets'];
        $mutations = [
            'missing' => array_slice($targets, 0, 14),
            'extra' => [...$targets, $targets[0]],
            'duplicate' => array_replace($targets, [14 => [...$targets[0], 'order' => 15, 'batch' => 'C']]),
            'order' => array_replace($targets, [0 => [...$targets[0], 'order' => 2]]),
        ];

        foreach ($mutations as $name => $mutation) {
            try {
                app(Article15ExactPackageRevisionBoundAdapter::class)->assertTargetInventory($mutation);
                $this->fail($name.' target inventory was accepted');
            } catch (RuntimeException $exception) {
                $this->assertStringStartsWith('execution_manifest_target_', $exception->getMessage(), $name);
            }
        }
    }

    public function test_forged_hash_and_public_state_drift_fail_closed_without_writes(): void
    {
        $this->seedBatch('A');
        $options = $this->commandOptions('draft-import', 'A', true);
        $options['--execution-manifest-sha256'] = str_repeat('0', 64);

        $this->assertSame(1, Artisan::call('articles:article15-exact-package', $options));
        $this->assertSame(0, ArticleEditorialPackageImport::query()->count());

        $options = $this->commandOptions('draft-import', 'A', true);
        Article::query()->withoutGlobalScopes()->findOrFail(58)->forceFill(['reading_minutes' => 99])->saveQuietly();

        $this->assertSame(1, Artisan::call('articles:article15-exact-package', $options));
        $this->assertStringContainsString('expected_state_sha256_mismatch', Artisan::output());
        $this->assertSame(0, ArticleEditorialPackageImport::query()->count());
    }

    public function test_current_body_hash_drift_fails_closed_without_writes(): void
    {
        $this->seedBatch('A');
        ArticleTranslationRevision::query()->withoutGlobalScopes()->where('article_id', 58)
            ->update(['content_md' => 'forged revision raw body']);
        $options = $this->commandOptions('draft-import', 'A', true);

        $this->assertSame(1, Artisan::call('articles:article15-exact-package', $options));
        $this->assertStringContainsString('revision_body_drift', Artisan::output());
        $this->assertSame(0, ArticleEditorialPackageImport::query()->count());
    }

    public function test_draft_import_is_batch_atomic_idempotent_and_keeps_public_projection_unchanged(): void
    {
        $this->seedBatch('A');
        $before = $this->publicFingerprint('A');

        $exitCode = Artisan::call('articles:article15-exact-package', $this->commandOptions('draft-import', 'A', true));
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertSame($before, $this->publicFingerprint('A'));
        $this->assertSame(5, ArticleEditorialPackageImport::query()->count());
        $this->assertSame(10, ArticleTranslationRevision::query()->count());

        foreach ($this->targets('A') as $target) {
            $article = Article::query()->withoutGlobalScopes()->with('workingRevision')->findOrFail($target['article_id']);
            $metadata = data_get($article->workingRevision?->authority_metadata_json, 'article15_exact_package_v1');
            $this->assertSame('drafted', $metadata['status'] ?? null);
            $this->assertSame(
                data_get($target, 'package.current_to_proposed.reading_minutes.proposed'),
                $metadata['reading_minutes'] ?? null
            );
            $this->assertSame(
                data_get($target, 'package.current_to_proposed.answer_surface_v1.proposed.faq_items'),
                $metadata['faq_items'] ?? null
            );
            $this->assertCount(1, $metadata['cta_slots'] ?? []);
        }

        $this->assertSame(0, Artisan::call('articles:article15-exact-package', $this->commandOptions('draft-import', 'A', true)));
        $this->assertSame(5, ArticleEditorialPackageImport::query()->count());
        $this->assertSame(10, ArticleTranslationRevision::query()->count());
        $this->assertStringContainsString('unchanged', Artisan::output());
    }

    public function test_all_partitions_create_exact_change_bodies_and_zero_keep_working_revision_bodies(): void
    {
        $this->seedBatch('ALL');

        foreach (['A', 'B', 'C'] as $batch) {
            $exitCode = Artisan::call('articles:article15-exact-package', $this->commandOptions('draft-import', $batch, true));
            $this->assertSame(0, $exitCode, Artisan::output());
        }

        foreach ($this->targets('ALL') as $target) {
            $article = Article::query()->withoutGlobalScopes()->with('workingRevision')->findOrFail($target['article_id']);
            if ($target['decision'] === 'KEEP') {
                $this->assertSame($article->published_revision_id, $article->working_revision_id);
                $this->assertSame(0, ArticleEditorialPackageImport::query()->where('article_id', $article->id)->count());

                continue;
            }
            $this->assertNotSame($article->published_revision_id, $article->working_revision_id);
            $this->assertSame($target['proposed_body_sha256'], hash('sha256', (string) $article->workingRevision?->content_md));
            $this->assertSame(1, ArticleEditorialPackageImport::query()->where('article_id', $article->id)->count());
        }
        $this->assertSame(9, ArticleEditorialPackageImport::query()->count());
    }

    public function test_draft_import_rolls_back_all_five_when_one_working_revision_collides(): void
    {
        $this->seedBatch('A');
        $article = Article::query()->withoutGlobalScopes()->findOrFail(40);
        $collision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'article_id' => 40,
            'source_article_id' => 40,
            'translation_group_id' => (string) $article->translation_group_id,
            'locale' => 'zh-CN',
            'source_locale' => 'zh-CN',
            'revision_number' => 2,
            'revision_status' => ArticleTranslationRevision::STATUS_HUMAN_REVIEW,
            'title' => 'unrelated draft',
            'content_md' => 'unrelated draft',
        ]);
        $article->forceFill(['working_revision_id' => (int) $collision->id])->saveQuietly();

        $this->assertSame(1, Artisan::call('articles:article15-exact-package', $this->commandOptions('draft-import', 'A', true)));
        $this->assertSame(0, ArticleEditorialPackageImport::query()->count());
        $this->assertSame(6, ArticleTranslationRevision::query()->count());
    }

    public function test_publish_is_one_batch_transaction_and_exact_readback_is_idempotent(): void
    {
        Event::fake([PublicAuthorityChanged::class]);
        $this->seedBatch('B');
        $exitCode = Artisan::call('articles:article15-exact-package', $this->commandOptions('draft-import', 'B', true));
        $this->assertSame(0, $exitCode, Artisan::output());

        $this->assertSame(0, Artisan::call('articles:article15-exact-package', $this->commandOptions('publish', 'B', true)));
        foreach ($this->targets('B') as $target) {
            $article = Article::query()->withoutGlobalScopes()->with(['publishedRevision', 'seoMeta'])->findOrFail($target['article_id']);
            $this->assertSame(data_get($target, 'package.current_to_proposed.reading_minutes.proposed'), $article->reading_minutes);
            $this->assertSame(data_get($target, 'package.current_to_proposed.related_test_slug.proposed'), $article->related_test_slug);
            $this->assertSame(data_get($target, 'package.current_to_proposed.answer_surface_v1.proposed.faq_items'), data_get($article->seoMeta?->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items'));
            $this->assertSame(
                data_get($target, 'package.current_to_proposed.primary_cta.proposed'),
                data_get($article->seoMeta?->schema_json, 'editorial_package_v1.cta_slots')
            );
            $this->assertCount(1, (array) data_get($target, 'package.field_plan.primary_cta.effective_primary'));
            $this->assertSame('/images/preserved.webp', data_get($article->cover_image_variants, 'preserved_media.src'));
            $this->assertNull(data_get($article->cover_image_variants, 'editorial_package_v1'));
            $this->assertSame('published', data_get($article->publishedRevision?->authority_metadata_json, 'article15_exact_package_v1.status'));
        }
        $this->assertSame(5, DB::table('audit_logs')->where('action', 'content_release_publish')->count());
        Event::assertDispatchedTimes(PublicAuthorityChanged::class, 5);

        $revisionCount = ArticleTranslationRevision::query()->count();
        $this->assertSame(0, Artisan::call('articles:article15-exact-package', $this->commandOptions('publish', 'B', true)));
        $this->assertSame($revisionCount, ArticleTranslationRevision::query()->count());
        $this->assertStringContainsString('already_applied', Artisan::output());
    }

    public function test_publish_rolls_back_all_five_when_one_working_revision_drifts(): void
    {
        $this->seedBatch('C');
        $exitCode = Artisan::call('articles:article15-exact-package', $this->commandOptions('draft-import', 'C', true));
        $this->assertSame(0, $exitCode, Artisan::output());
        $before = $this->publicFingerprint('C');
        $options = $this->commandOptions('publish', 'C', true);
        $article = Article::query()->withoutGlobalScopes()->with('workingRevision')->findOrFail(31);
        $article->workingRevision->forceFill(['content_md' => 'forged working body'])->save();

        $this->assertSame(1, Artisan::call('articles:article15-exact-package', $options));
        $this->assertSame($before, $this->publicFingerprint('C'));
        foreach ($this->targets('C') as $target) {
            $this->assertSame($target['published_revision_id'], Article::query()->withoutGlobalScopes()->findOrFail($target['article_id'])->published_revision_id);
        }
    }

    public function test_article15_seven_and_eight_faqs_are_complete_while_normal_api_stays_at_six_and_json_ld_returns_eight(): void
    {
        foreach (['B', 'C'] as $batch) {
            $this->seedBatch($batch);
            $exitCode = Artisan::call('articles:article15-exact-package', $this->commandOptions('draft-import', $batch, true));
            $this->assertSame(0, $exitCode, Artisan::output());
            $publishExit = Artisan::call('articles:article15-exact-package', $this->commandOptions('publish', $batch, true));
            $this->assertSame(0, $publishExit, Artisan::output());
        }

        foreach ([[50, 7], [61, 8]] as [$id, $count]) {
            $article = Article::query()->withoutGlobalScopes()->findOrFail($id);
            $response = $this->getJson('/api/v0.5/articles/'.$article->slug.'?locale='.$article->locale)->assertOk();
            $this->assertCount($count, $response->json('answer_surface_v1.faq_blocks'));
            $seoResponse = $this->getJson('/api/v0.5/articles/'.$article->slug.'/seo?locale='.$article->locale)->assertOk();
            $faqPage = collect((array) $seoResponse->json('jsonld.hasPart'))
                ->firstWhere('@type', 'FAQPage');
            $this->assertCount($count, (array) ($faqPage['mainEntity'] ?? []));
        }

        $normal = $this->createNormalEightFaqArticle();
        $response = $this->getJson('/api/v0.5/articles/'.$normal->slug.'?locale=en')->assertOk();
        $this->assertCount(6, $response->json('answer_surface_v1.faq_blocks'));
        $seoResponse = $this->getJson('/api/v0.5/articles/'.$normal->slug.'/seo?locale=en')->assertOk();
        $faqPage = collect((array) $seoResponse->json('jsonld.hasPart'))->firstWhere('@type', 'FAQPage');
        $this->assertCount(8, (array) ($faqPage['mainEntity'] ?? []));
    }

    /** @return array<string,mixed> */
    private function commandOptions(string $phase, string $batch, bool $execute = false): array
    {
        $hashes = app(Article15ExactPackageRevisionBoundAdapter::class)->currentLockHashes($batch);

        return [
            '--phase' => $phase,
            '--batch' => $batch,
            '--execution-manifest-sha256' => self::MANIFEST_SHA,
            '--expected-state-sha256' => $hashes['state_sha256'],
            '--expected-revision-set-sha256' => $hashes['revision_set_sha256'],
            '--json' => true,
            ...($execute ? ['--execute' => true] : ['--dry-run' => true]),
        ];
    }

    /** @return array<string,mixed> */
    private function snapshotOptions(): array
    {
        return [
            '--phase' => 'snapshot',
            '--batch' => 'ALL',
            '--execution-manifest-sha256' => self::MANIFEST_SHA,
            '--dry-run' => true,
            '--json' => true,
        ];
    }

    private function seedBatch(string $batch): void
    {
        config([
            'article15_test.skip_synthetic_current_body_lock' => false,
            'article15_test.skip_manifest_production_lock' => true,
        ]);

        foreach ($this->targets($batch) as $target) {
            $package = $target['package'];
            $current = (array) $package['current_to_proposed'];
            $coverImageVariants = ['preserved_media' => ['src' => '/images/preserved.webp']];
            $editorialMetadata = $this->currentPublicEditorialMetadata($target);
            if ($editorialMetadata !== []) {
                $coverImageVariants['editorial_package_v1'] = $editorialMetadata;
            }
            $article = Article::query()->withoutGlobalScopes()->forceCreate([
                'id' => $target['article_id'],
                'org_id' => 0,
                'slug' => $target['slug'],
                'locale' => $target['locale'],
                'translation_group_id' => $target['translation_group_id'],
                'source_locale' => $target['locale'],
                'translation_status' => Article::TRANSLATION_STATUS_SOURCE,
                'title' => data_get($current, 'title.current'),
                'excerpt' => data_get($current, 'intro.current'),
                'content_md' => $this->revisionRawBody($target),
                'content_html' => '<p>baseline body</p>',
                'cover_image_variants' => $coverImageVariants,
                'reading_minutes' => data_get($current, 'reading_minutes.current'),
                'related_test_slug' => data_get($current, 'related_test_slug.current'),
                'status' => 'published',
                'is_public' => true,
                'is_indexable' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'published_at' => now()->subDay(),
            ]);
            $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->forceCreate([
                'id' => $target['published_revision_id'],
                'org_id' => 0,
                'article_id' => $target['article_id'],
                'source_article_id' => $target['article_id'],
                'translation_group_id' => $target['translation_group_id'],
                'locale' => $target['locale'],
                'source_locale' => $target['locale'],
                'revision_number' => 1,
                'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
                'title' => data_get($current, 'title.current'),
                'excerpt' => data_get($current, 'intro.current'),
                'content_md' => $this->revisionRawBody($target),
                'seo_title' => data_get($current, 'seo_title.current'),
                'seo_description' => data_get($current, 'seo_description.current'),
                'published_at' => now()->subDay(),
            ]);
            $article->forceFill([
                'published_revision_id' => (int) $revision->id,
                'working_revision_id' => (int) $revision->id,
            ])->saveQuietly();
            ArticleSeoMeta::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'article_id' => $target['article_id'],
                'locale' => $target['locale'],
                'seo_title' => data_get($current, 'seo_title.current'),
                'seo_description' => data_get($current, 'seo_description.current'),
                'canonical_url' => $target['canonical_url'],
                'og_title' => 'preserved og '.$target['article_id'],
                'og_description' => 'preserved og description '.$target['article_id'],
                'robots' => 'index,follow',
                'schema_json' => ['preserved_gate' => true],
                'is_indexable' => true,
            ]);
        }
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function currentPublicEditorialMetadata(array $target): array
    {
        $package = (array) $target['package'];
        $locale = (string) $target['locale'];
        $faq = (array) data_get($package, 'current_to_proposed.answer_surface_v1.current.faq_items', []);
        $ctas = (array) data_get($package, 'current_to_proposed.primary_cta.current', []);
        $metadata = [];

        if (! $this->isDefaultFaqProjection($faq, $locale)) {
            $faqMetadata = $faq;
            if ((int) $target['article_id'] === 51) {
                $faqMetadata[0] = [
                    'q' => $faq[0]['question'],
                    'a' => $faq[0]['answer'],
                ];
                $faqMetadata[] = [
                    'question' => 'hidden fixture question',
                    'answer' => 'hidden fixture answer',
                    'visibility' => 'hidden',
                ];
            }
            $metadata = [
                'answer_surface_policy' => 'editor_supplied',
                'answer_surface_visibility' => 'visible',
                'answer_surface_v1' => ['faq_items' => $faqMetadata],
            ];
        }
        if (! $this->isDefaultCtaProjection($ctas, $locale)) {
            $metadata['cta_slots'] = array_map(
                static fn (array $cta, int $index): array => array_filter([
                    'key' => (int) $target['article_id'] === 58 ? null : (string) ($cta['key'] ?? ''),
                    $index % 2 === 0 ? 'title' : 'label' => (string) ($cta['title'] ?? ''),
                    $index % 2 === 0 ? 'url' : 'href' => (string) ($cta['href'] ?? ''),
                    'kind' => (string) ($cta['kind'] ?? ''),
                ], static fn (mixed $value): bool => $value !== null),
                $ctas,
                array_keys($ctas),
            );
            $metadata['cta_slots'][] = [
                'label' => 'invalid private fixture',
                'href' => '/en/result/private-attempt',
            ];
        }

        return $metadata;
    }

    /** @param list<array<string,mixed>> $faq */
    private function isDefaultFaqProjection(array $faq, string $locale): bool
    {
        $questions = array_column($faq, 'question');

        return $questions === ($locale === 'zh-CN'
            ? ['什么时候适合阅读这篇文章？', '这篇文章会替代正式判断吗？']
            : ['When should I use this article?', 'Does this replace formal judgment?']);
    }

    /** @param list<array<string,mixed>> $ctas */
    private function isDefaultCtaProjection(array $ctas, string $locale): bool
    {
        $segment = $locale === 'zh-CN' ? 'zh' : 'en';
        $hrefs = array_column($ctas, 'href', 'key');

        return array_column($ctas, 'key') === ['articles_index', 'topic_hub', 'start_test']
            && ($hrefs['articles_index'] ?? null) === '/'.$segment.'/articles'
            && ($hrefs['topic_hub'] ?? null) === '/'.$segment.'/topics';
    }

    /** @return list<array<string,mixed>> */
    private function targets(string $batch): array
    {
        $targets = array_values(array_filter(
            (array) $this->manifest()['targets'],
            static fn (array $target): bool => $batch === 'ALL' || $target['batch'] === $batch
        ));
        foreach ($targets as &$target) {
            $raw = json_decode(file_get_contents(dirname(base_path()).'/'.$target['package_path']), true, 512, JSON_THROW_ON_ERROR);
            $target['raw_package'] = $raw;
            $target['package'] = $this->normalizePackageForTest($raw, $target);
        }

        return $targets;
    }

    /** @return array<string,mixed> */
    private function manifest(): array
    {
        return json_decode(file_get_contents(dirname(base_path()).'/'.Article15ExactPackageRevisionBoundAdapter::MANIFEST_PATH), true, 512, JSON_THROW_ON_ERROR);
    }

    private function publicFingerprint(string $batch): string
    {
        $rows = [];
        foreach ($this->targets($batch) as $target) {
            $article = Article::query()->withoutGlobalScopes()->with(['publishedRevision', 'seoMeta'])->findOrFail($target['article_id']);
            $rows[] = [
                'article' => $article->only(['title', 'excerpt', 'content_md', 'content_html', 'reading_minutes', 'related_test_slug', 'published_revision_id', 'status', 'is_public', 'is_indexable', 'sitemap_eligible', 'llms_eligible']),
                'published' => $article->publishedRevision?->only(['id', 'revision_status', 'title', 'excerpt', 'content_md', 'seo_title', 'seo_description']),
                'seo' => $article->seoMeta?->only(['seo_title', 'seo_description', 'canonical_url', 'og_title', 'og_description', 'robots', 'schema_json', 'is_indexable']),
            ];
        }

        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function createNormalEightFaqArticle(): Article
    {
        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'slug' => 'normal-eight-faq', 'locale' => 'en', 'title' => 'Normal FAQ',
            'content_md' => 'Normal body', 'status' => 'published', 'is_public' => true, 'is_indexable' => true,
            'published_at' => now()->subDay(),
        ]);
        $revision = ArticleTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'article_id' => $article->id, 'source_article_id' => $article->id,
            'translation_group_id' => $article->translation_group_id, 'locale' => 'en', 'source_locale' => 'en',
            'revision_number' => 1, 'revision_status' => ArticleTranslationRevision::STATUS_PUBLISHED,
            'title' => 'Normal FAQ', 'content_md' => 'Normal body', 'published_at' => now()->subDay(),
        ]);
        $article->forceFill(['published_revision_id' => $revision->id])->saveQuietly();
        $faqs = array_map(static fn (int $i): array => ['question' => 'Question '.$i, 'answer' => 'Answer '.$i], range(1, 8));
        ArticleSeoMeta::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'article_id' => $article->id, 'locale' => 'en', 'seo_title' => 'Normal FAQ',
            'canonical_url' => 'https://fermatmind.com/en/articles/normal-eight-faq', 'robots' => 'index,follow',
            'is_indexable' => true, 'schema_json' => ['editorial_package_v1' => [
                'answer_surface_policy' => 'editor_supplied', 'answer_surface_visibility' => 'visible',
                'answer_surface_v1' => ['faq_items' => $faqs],
            ]],
        ]);

        return $article->fresh() ?? $article;
    }

    private function isolatedPackageRepository(string $mutation): string
    {
        $sourceRoot = dirname(base_path());
        $root = storage_path('framework/testing/article15-'.$mutation.'-'.bin2hex(random_bytes(4)));
        $manifest = $this->manifest();
        $paths = [
            Article15ExactPackageRevisionBoundAdapter::MANIFEST_PATH,
            (string) data_get($manifest, 'bindings.approval_manifest_path'),
            (string) data_get($manifest, 'bindings.final_manifest_path'),
            (string) data_get($manifest, 'bindings.review_artifact_path'),
            ...array_keys((array) data_get($manifest, 'bindings.projection_contract.implementation_file_sha256', [])),
        ];
        foreach ((array) $manifest['targets'] as $target) {
            $paths[] = (string) $target['package_path'];
            $package = json_decode(file_get_contents($sourceRoot.'/'.$target['package_path']), true, 512, JSON_THROW_ON_ERROR);
            $locale = (string) data_get($package, 'identity_lock.locale');
            $paths[] = dirname((string) $target['package_path']).'/current.public.'.$locale.'.md';
            if (($target['decision'] ?? null) === 'CHANGE') {
                $paths[] = dirname((string) $target['package_path']).'/'.basename((string) data_get($package, 'body_write_plan.proposed_cms_file'));
            }
        }
        foreach (array_unique($paths) as $path) {
            File::ensureDirectoryExists(dirname($root.'/'.$path));
            File::copy($sourceRoot.'/'.$path, $root.'/'.$path);
        }

        $target = $manifest['targets'][0];
        $packagePath = $root.'/'.$target['package_path'];
        if ($mutation === 'package') {
            $package = json_decode(file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);
            $package['test_only_forgery'] = true;
            File::put($packagePath, json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } elseif ($mutation === 'body') {
            $package = json_decode(file_get_contents($packagePath), true, 512, JSON_THROW_ON_ERROR);
            $bodyPath = dirname($packagePath).'/'.basename((string) data_get($package, 'body_write_plan.proposed_cms_file'));
            File::append($bodyPath, "\ntest-only-forgery\n");
        } elseif (in_array($mutation, ['approval', 'final_manifest', 'review'], true)) {
            $binding = match ($mutation) {
                'approval' => 'approval_manifest_path',
                'final_manifest' => 'final_manifest_path',
                default => 'review_artifact_path',
            };
            File::append($root.'/'.data_get($manifest, 'bindings.'.$binding), "\n");
        } elseif ($mutation === 'projection_contract') {
            $projectionPath = array_key_first((array) data_get($manifest, 'bindings.projection_contract.implementation_file_sha256'));
            $this->assertIsString($projectionPath);
            File::append($root.'/'.$projectionPath, "\n");
        } elseif (in_array($mutation, ['revision_lock', 'public_projection_lock'], true)) {
            $field = $mutation === 'revision_lock' ? 'revision_raw_body_sha256' : 'public_projection_body_sha256';
            $manifest['targets'][0][$field] = str_repeat('0', 64);
            unset($manifest['execution_manifest_sha256']);
            $manifest['execution_manifest_sha256'] = $this->canonicalHashForTest($manifest);
            File::put(
                $root.'/'.Article15ExactPackageRevisionBoundAdapter::MANIFEST_PATH,
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n"
            );
        }

        return $root;
    }

    private function assertSnapshotIntegrityMismatch(string $mutation): void
    {
        $root = $this->isolatedPackageRepository($mutation);
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($root));
        config(['article15_test.repository_root' => $root]);
        $this->seedBatch('ALL');

        $this->assertSame(1, Artisan::call('articles:article15-exact-package', $this->snapshotOptions()));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($payload['ok']);
        $this->assertSame(1, $payload['package_sha_mismatch']);
        $this->assertSame(0, $payload['public_mutations']);
    }

    /** @param array<string,mixed> $package @param array<string,mixed> $target @return array<string,mixed> */
    private function normalizePackageForTest(array $package, array $target): array
    {
        $fields = (array) ($package['field_plan'] ?? []);
        $patch = static fn (array $field, string $current = 'current'): array => [
            'status' => (string) ($field['status'] ?? ''),
            'current' => $field[$current] ?? null,
            'proposed' => $field['proposed'] ?? null,
        ];
        $normalized = [];
        foreach (['title', 'h1', 'intro', 'seo_title', 'seo_description', 'reading_minutes', 'related_test_slug'] as $field) {
            $normalized[$field] = $patch((array) ($fields[$field] ?? []));
        }
        $normalized['body_markdown'] = [
            'status' => (string) data_get($fields, 'body_markdown.status'),
            'current' => ['sha256' => (string) $target['public_projection_body_sha256']],
            'proposed' => ['sha256' => $target['proposed_body_sha256'] ?? $target['public_projection_body_sha256']],
        ];
        $normalized['faq'] = $patch((array) ($fields['faq_visible_body'] ?? []));
        $answerSurface = $patch((array) ($fields['answer_surface_v1'] ?? []), 'current_public_api');
        $normalized['answer_surface_v1'] = [
            ...$answerSurface,
            'current' => ['faq_items' => (array) $answerSurface['current']],
            'proposed' => ['faq_items' => (array) $answerSurface['proposed']],
        ];
        $normalized['primary_cta'] = $patch((array) ($fields['primary_cta'] ?? []), 'current_public_api');
        $normalized['publication'] = [
            'status' => 'KEEP',
            'current' => ['status' => 'published', 'is_public' => true],
            'proposed' => ['status' => 'published', 'is_public' => true],
        ];

        return [...$package, 'current_to_proposed' => $normalized];
    }

    /** @param array<string,mixed> $target */
    private function revisionRawBody(array $target): string
    {
        $package = (array) $target['raw_package'];
        $locale = (string) data_get($package, 'identity_lock.locale');
        $path = dirname(base_path()).'/'.dirname((string) $target['package_path']).'/current.public.'.$locale.'.md';
        $public = (string) file_get_contents($path);
        if (hash('sha256', $public) === (string) $target['revision_raw_body_sha256']) {
            return $public;
        }
        $raw = preg_replace('/^## /', '# ', $public, 1);
        $this->assertIsString($raw);
        $this->assertSame((string) $target['revision_raw_body_sha256'], hash('sha256', $raw));

        return $raw;
    }

    private function canonicalHashForTest(mixed $value): string
    {
        $canonicalize = function (mixed $item) use (&$canonicalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($canonicalize, $item);
            }
            ksort($item, SORT_STRING);
            foreach ($item as $key => $nested) {
                $item[$key] = $canonicalize($nested);
            }

            return $item;
        };

        return hash('sha256', json_encode($canonicalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
