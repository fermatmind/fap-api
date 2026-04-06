<?php

declare(strict_types=1);

namespace Tests\Feature\CareerCms;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ArticleBaselineImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write_database(): void
    {
        $this->artisan('articles:import-local-baseline', [
            '--dry-run' => true,
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/articles',
        ])
            ->expectsOutputToContain('files_found=2')
            ->expectsOutputToContain('articles_found=6')
            ->expectsOutputToContain('will_create=6')
            ->assertExitCode(0);

        $this->assertSame(0, Article::query()->withoutGlobalScopes()->count());
    }

    public function test_import_creates_and_updates_published_public_articles_for_mbti_minimum_set(): void
    {
        $this->artisan('articles:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/articles',
        ])
            ->expectsOutputToContain('files_found=2')
            ->expectsOutputToContain('articles_found=6')
            ->expectsOutputToContain('will_create=6')
            ->assertExitCode(0);

        $this->assertSame(6, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            6,
            Article::query()
                ->withoutGlobalScopes()
                ->where('status', 'published')
                ->where('is_public', true)
                ->count()
        );

        $enBasics = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'en')
            ->where('slug', 'mbti-basics')
            ->firstOrFail();
        $this->assertSame('MBTI Personality Test (16 Types) | Tool Guide', (string) $enBasics->title);

        $zhGrowth = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', 'mbti-growth-guide')
            ->firstOrFail();
        $this->assertSame('MBTI 性格测试（16型人格）｜成长引导版', (string) $zhGrowth->title);

        $this->artisan('articles:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/articles',
        ])
            ->expectsOutputToContain('articles_found=6')
            ->expectsOutputToContain('will_skip=6')
            ->assertExitCode(0);

        $this->assertSame(6, Article::query()->withoutGlobalScopes()->count());
    }
}
