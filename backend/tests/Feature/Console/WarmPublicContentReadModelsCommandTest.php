<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\WarmPublicContentReadModels;
use App\Models\PersonalityProfile;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use App\Services\Cms\PersonalityPublicReadModelCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class WarmPublicContentReadModelsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_default_mode_is_a_bounded_non_writing_dry_run_in_priority_order(): void
    {
        Cache::put('sentinel', 'unchanged');

        $exitCode = Artisan::call('public-content:warm-read-models', ['--json' => true]);
        $report = $this->jsonOutput();

        $this->assertSame(0, $exitCode);
        $this->assertSame('dry-run', $report['mode']);
        $this->assertSame('planned', $report['status']);
        $this->assertFalse($report['write_executed']);
        $this->assertSame(['L1:mbti', 'L2:big-five', 'L3:career-industries'], $report['priority_order']);
        $this->assertSame(['L1', 'L2', 'L3'], array_column($report['entries'], 'priority'));
        $this->assertSame(WarmPublicContentReadModels::PAYLOAD_BUDGET_BYTES, $report['payload_budget_bytes']);
        $this->assertSame('unchanged', Cache::get('sentinel'));
        $this->assertStringContainsString(
            "public-content:warm-read-models --verify-only --json')->everyTenMinutes()->withoutOverlapping()",
            File::get(base_path('bootstrap/app.php')),
        );
    }

    public function test_it_rejects_conflicting_modes_and_write_acknowledgements_on_read_only_modes(): void
    {
        $this->assertSame(1, Artisan::call('public-content:warm-read-models', [
            '--dry-run' => true,
            '--verify-only' => true,
        ]));
        $this->assertStringContainsString('Choose exactly one', Artisan::output());

        $this->assertSame(1, Artisan::call('public-content:warm-read-models', [
            '--verify-only' => true,
            '--production-write' => true,
        ]));
        $this->assertStringContainsString('valid only with --warm', Artisan::output());
    }

    public function test_production_warm_fails_closed_before_any_cache_write(): void
    {
        $previousEnvironment = app()->environment();
        app()->detectEnvironment(static fn (): string => 'production');
        config()->set('public_content_observability.warm_production_enabled', false);
        Cache::put('sentinel', 'unchanged');

        try {
            $exitCode = Artisan::call('public-content:warm-read-models', [
                '--warm' => true,
                '--production-write' => true,
                '--confirm' => 'PUBLIC-CONTENT-WARM',
            ]);
        } finally {
            app()->detectEnvironment(static fn (): string => $previousEnvironment);
        }

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Production warm refused before writes', Artisan::output());
        $this->assertSame('unchanged', Cache::get('sentinel'));
    }

    public function test_verify_only_reads_active_and_lkg_versions_without_mutation(): void
    {
        $mbtiCache = app(PersonalityPublicReadModelCache::class);
        $assetCache = app(PersonalityPublicAssetReadModelCache::class);
        $this->seedMbtiReadModels($mbtiCache);
        $this->seedBigFiveReadModels($assetCache);
        Cache::forget($mbtiCache->activeKey('detail', 'INTJ-A', 'en'));
        Cache::put($mbtiCache->lkgKey('detail', 'INTJ-A', 'en'), 'mbti-v1');

        $careerCache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['en', 'zh-CN'] as $locale) {
            $careerCache->publishDirectoryReadModel($locale, [
                'read_model_version' => 'career.directory.read-model.v1',
                'locale' => $locale,
                'items' => [],
            ]);
        }

        $exitCode = Artisan::call('public-content:warm-read-models', [
            '--verify-only' => true,
            '--json' => true,
        ]);
        $report = $this->jsonOutput();

        $this->assertSame(0, $exitCode);
        $this->assertSame('verified', $report['status']);
        $this->assertFalse($report['write_executed']);
        $this->assertCount(132, $report['entries']);
        $this->assertSame(['L1', 'L2', 'L3'], array_values(array_unique(array_column($report['entries'], 'priority'))));
        $this->assertNotContains('unavailable', array_column($report['entries'], 'status'));
        $lkgEntry = collect($report['entries'])->firstWhere('target', 'INTJ-A:detail');
        $this->assertSame('lkg', $lkgEntry['source'] ?? null);
    }

    public function test_verify_only_fails_when_a_payload_exceeds_the_budget(): void
    {
        $mbtiCache = app(PersonalityPublicReadModelCache::class);
        $assetCache = app(PersonalityPublicAssetReadModelCache::class);
        $this->seedMbtiReadModels($mbtiCache);
        $this->seedBigFiveReadModels($assetCache);

        Cache::put(
            $mbtiCache->key('detail', 'INTJ-A', 'en', 'mbti-v1'),
            ['body' => str_repeat('x', WarmPublicContentReadModels::PAYLOAD_BUDGET_BYTES + 1)],
        );

        $careerCache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['en', 'zh-CN'] as $locale) {
            $careerCache->publishDirectoryReadModel($locale, ['items' => []]);
        }

        $exitCode = Artisan::call('public-content:warm-read-models', [
            '--verify-only' => true,
            '--json' => true,
        ]);
        $report = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertSame('failed', $report['status']);
        $this->assertContains('budget_exceeded', array_column($report['entries'], 'status'));
    }

    private function seedMbtiReadModels(PersonalityPublicReadModelCache $cache): void
    {
        foreach (PersonalityProfile::BASE_TYPE_CODES as $baseType) {
            foreach ([$baseType.'-A', $baseType.'-T'] as $type) {
                foreach (['en', 'zh-CN'] as $locale) {
                    foreach (['detail', 'seo'] as $surface) {
                        Cache::put($cache->activeKey($surface, $type, $locale), 'mbti-v1');
                        Cache::put($cache->key($surface, $type, $locale, 'mbti-v1'), ['ok' => true]);
                    }
                }
            }
        }
    }

    private function seedBigFiveReadModels(PersonalityPublicAssetReadModelCache $cache): void
    {
        foreach (['en', 'zh-CN'] as $locale) {
            Cache::put(
                $cache->activeKey('index', 'big-five', 'all', 'page:1:per-page:100', $locale),
                'big-five-v1',
            );
            Cache::put(
                $cache->key('index', 'big-five', 'all', 'page:1:per-page:100', $locale, 'big-five-v1'),
                ['ok' => true, 'items' => []],
            );
        }
    }

    /** @return array<string, mixed> */
    private function jsonOutput(): array
    {
        $decoded = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
