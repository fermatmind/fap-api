<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ArticleSeoObservationPlanCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_seo_observation_creates_d1_d7_d14_artifacts_without_mutation(): void
    {
        Carbon::setTestNow('2026-07-15T00:00:00Z');
        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'slug' => 'test-observation', 'locale' => 'zh-CN', 'translation_group_id' => 'tg_test_observation',
            'title' => '观察文章', 'content_md' => '[测试](/zh/tests/big-five-personality-test-ocean-model)', 'status' => 'published',
            'is_public' => true, 'is_indexable' => true, 'published_at' => '2026-07-01T00:00:00Z',
        ]);
        $dir = sys_get_temp_dir().'/fm-observation-'.Str::random(10);

        $exit = Artisan::call('articles:seo-observation-plan', ['--article-ids' => (string) $article->id, '--package-id' => 'pkg-test', '--output-dir' => $dir, '--json' => true]);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(0, $exit);
        $this->assertSame(['d1', 'd7', 'd14'], array_keys($payload['articles'][0]['windows']));
        $this->assertSame('HOLD_INSUFFICIENT_DATA', $payload['articles'][0]['windows']['d1']['recommendation']);
        $this->assertFileExists($dir.'/article-seo-observation-plan.json');
        $this->assertFileExists($dir.'/article-seo-observation-plan.md');
        $this->assertSame('观察文章', $article->fresh()->title);
    }

    public function test_article_seo_observation_evidence_produces_deterministic_recommendation(): void
    {
        $article = Article::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'slug' => 'test-evidence', 'locale' => 'en', 'translation_group_id' => 'tg_test_evidence',
            'title' => 'Evidence article', 'content_md' => 'Body', 'status' => 'published', 'is_public' => true, 'is_indexable' => true, 'published_at' => now(),
        ]);
        $dir = sys_get_temp_dir().'/fm-observation-'.Str::random(10);
        $evidence = $dir.'-evidence.json';
        file_put_contents($evidence, json_encode(['articles' => [(string) $article->id => ['d1' => ['impressions' => 100, 'clicks' => 0, 'ctr' => 0.0]]]]));

        $this->assertSame(0, Artisan::call('articles:seo-observation-plan', ['--article-ids' => (string) $article->id, '--package-id' => 'pkg-test', '--output-dir' => $dir, '--evidence-json' => $evidence, '--json' => true]));
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('TITLE_META_REVIEW', $payload['articles'][0]['windows']['d1']['recommendation']);
        $this->assertNull($payload['articles'][0]['windows']['d7']['metrics']);
    }
}
