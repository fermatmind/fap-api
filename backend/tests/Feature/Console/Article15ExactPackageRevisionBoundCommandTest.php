<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use App\Models\ArticleEditorialPackageImport;
use App\Models\ArticleSeoMeta;
use App\Models\ArticleTranslationRevision;
use App\Services\Cms\Article15ExactPackageRevisionBoundAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

final class Article15ExactPackageRevisionBoundCommandTest extends TestCase
{
    use RefreshDatabase;

    private const MANIFEST_SHA = 'eb9a0254600991e2b4d7967b7c4d46c27d604719d8c40ba63353a20cd50a96e7';

    public function test_preflight_recomputes_exact_manifest_package_and_body_chain_for_all_fifteen_targets(): void
    {
        $this->seedBatch('ALL');
        $options = $this->commandOptions('preflight', 'ALL');

        $this->assertSame(0, Artisan::call('articles:article15-exact-package', $options));
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['ok']);
        $this->assertSame(15, $payload['target_count']);
        $this->assertCount(15, array_unique(array_column($this->manifest()['targets'], 'article_id')));
        $this->assertFalse($payload['executed']);
        $this->assertFalse($payload['write_boundaries']['database_write']);
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
        config(['article15_test.skip_synthetic_current_body_lock' => false]);
        $options = $this->commandOptions('draft-import', 'A', true);

        $this->assertSame(1, Artisan::call('articles:article15-exact-package', $options));
        $this->assertStringContainsString('current_value_drift:body_markdown', Artisan::output());
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
        $this->seedBatch('B');
        $exitCode = Artisan::call('articles:article15-exact-package', $this->commandOptions('draft-import', 'B', true));
        $this->assertSame(0, $exitCode, Artisan::output());

        $this->assertSame(0, Artisan::call('articles:article15-exact-package', $this->commandOptions('publish', 'B', true)));
        foreach ($this->targets('B') as $target) {
            $article = Article::query()->withoutGlobalScopes()->with(['publishedRevision', 'seoMeta'])->findOrFail($target['article_id']);
            $this->assertSame(data_get($target, 'package.current_to_proposed.reading_minutes.proposed'), $article->reading_minutes);
            $this->assertSame(data_get($target, 'package.current_to_proposed.related_test_slug.proposed'), $article->related_test_slug);
            $this->assertSame(data_get($target, 'package.current_to_proposed.answer_surface_v1.proposed.faq_items'), data_get($article->seoMeta?->schema_json, 'editorial_package_v1.answer_surface_v1.faq_items'));
            $this->assertCount(1, (array) data_get($article->seoMeta?->schema_json, 'editorial_package_v1.cta_slots'));
            $this->assertSame('published', data_get($article->publishedRevision?->authority_metadata_json, 'article15_exact_package_v1.status'));
        }

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
        $article = Article::query()->withoutGlobalScopes()->with('workingRevision')->findOrFail(61);
        $article->workingRevision->forceFill(['content_md' => 'forged working body'])->save();

        $this->assertSame(1, Artisan::call('articles:article15-exact-package', $options));
        $this->assertSame($before, $this->publicFingerprint('C'));
        foreach ($this->targets('C') as $target) {
            $this->assertSame($target['published_revision_id'], Article::query()->withoutGlobalScopes()->findOrFail($target['article_id'])->published_revision_id);
        }
    }

    public function test_article15_seven_and_eight_faqs_are_complete_while_normal_articles_remain_bounded_to_six(): void
    {
        foreach (['B', 'C'] as $batch) {
            $this->seedBatch($batch);
            $exitCode = Artisan::call('articles:article15-exact-package', $this->commandOptions('draft-import', $batch, true));
            $this->assertSame(0, $exitCode, Artisan::output());
            $this->assertSame(0, Artisan::call('articles:article15-exact-package', $this->commandOptions('publish', $batch, true)));
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
        $this->assertCount(6, (array) ($faqPage['mainEntity'] ?? []));
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

    private function seedBatch(string $batch): void
    {
        config(['article15_test.skip_synthetic_current_body_lock' => true]);

        foreach ($this->targets($batch) as $target) {
            $package = $target['package'];
            $current = (array) $package['current_to_proposed'];
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
                'content_md' => 'baseline body '.$target['article_id'],
                'content_html' => '<p>baseline body</p>',
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
                'content_md' => 'baseline body '.$target['article_id'],
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

    /** @return list<array<string,mixed>> */
    private function targets(string $batch): array
    {
        $targets = array_values(array_filter(
            (array) $this->manifest()['targets'],
            static fn (array $target): bool => $batch === 'ALL' || $target['batch'] === $batch
        ));
        foreach ($targets as &$target) {
            $target['package'] = json_decode(file_get_contents(dirname(base_path()).'/'.$target['package_path']), true, 512, JSON_THROW_ON_ERROR);
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
}
