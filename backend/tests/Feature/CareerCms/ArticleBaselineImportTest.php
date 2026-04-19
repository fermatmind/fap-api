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
            ->expectsOutputToContain('articles_found=42')
            ->expectsOutputToContain('will_create=42')
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
            ->expectsOutputToContain('articles_found=42')
            ->expectsOutputToContain('will_create=42')
            ->assertExitCode(0);

        $this->assertSame(42, Article::query()->withoutGlobalScopes()->count());
        $this->assertSame(
            42,
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
        $this->assertSame('FermatMind Editorial', (string) $enBasics->author_name);
        $this->assertSame(2, (int) $enBasics->reading_minutes);
        $this->assertSame('mbti-personality-test-16-personality-types', (string) $enBasics->related_test_slug);
        $this->assertSame('tool', (string) $enBasics->voice);
        $this->assertSame(1, (int) $enBasics->voice_order);

        $zhGrowth = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', 'mbti-growth-guide')
            ->firstOrFail();
        $this->assertSame('MBTI 性格测试（16型人格）｜成长引导版', (string) $zhGrowth->title);
        $this->assertSame('mbti-personality-test-16-personality-types', (string) $zhGrowth->related_test_slug);
        $this->assertSame('growth', (string) $zhGrowth->voice);
        $this->assertSame(2, (int) $zhGrowth->voice_order);

        $editorial = Article::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('locale', 'zh-CN')
            ->where('slug', 'how-personality-shapes-attitude-toward-ai')
            ->firstOrFail();
        $this->assertSame('https://api.fermatmind.com/static/articles/covers/how-personality-shapes-attitude-toward-ai.svg', (string) $editorial->cover_image_url);
        $this->assertSame('人工智能与人格', (string) $editorial->category?->name);
        $this->assertContains('算法信任', $editorial->tags->pluck('name')->all());
        $this->assertSame(1200, (int) $editorial->cover_image_width);
        $this->assertSame(675, (int) $editorial->cover_image_height);
        $this->assertSame(
            'https://api.fermatmind.com/static/articles/covers/how-personality-shapes-attitude-toward-ai.svg',
            $editorial->cover_image_variants['hero'] ?? null
        );

        $this->artisan('articles:import-local-baseline', [
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/articles',
        ])
            ->expectsOutputToContain('articles_found=42')
            ->expectsOutputToContain('will_skip=42')
            ->assertExitCode(0);

        $this->assertSame(42, Article::query()->withoutGlobalScopes()->count());
    }
}
