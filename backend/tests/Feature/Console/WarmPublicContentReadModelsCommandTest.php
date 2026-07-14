<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\WarmPublicContentReadModels;
use App\Models\PersonalityProfile;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Cms\PersonalityPublicAssetReadModelCache;
use App\Services\Cms\PersonalityPublicReadModelCache;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

final class WarmPublicContentReadModelsCommandTest extends TestCase
{
    use RefreshDatabase;

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

        $this->assertSame(0, $exitCode, json_encode($report, JSON_THROW_ON_ERROR));
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

        $this->assertSame(0, $exitCode, json_encode($report, JSON_THROW_ON_ERROR));
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

    public function test_verify_only_uses_public_json_encoding_for_payload_budget(): void
    {
        $mbtiCache = app(PersonalityPublicReadModelCache::class);
        $assetCache = app(PersonalityPublicAssetReadModelCache::class);
        $this->seedMbtiReadModels($mbtiCache);
        $this->seedBigFiveReadModels($assetCache);
        Cache::put(
            $mbtiCache->key('detail', 'INTJ-A', 'zh-CN', 'mbti-v1'),
            ['body' => str_repeat('测', 90000)],
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
        $entry = collect($report['entries'])->first(
            static fn (array $entry): bool => ($entry['target'] ?? null) === 'INTJ-A:detail'
                && ($entry['locale'] ?? null) === 'zh-CN',
        );
        $this->assertSame('budget_exceeded', $entry['status'] ?? null);
        $this->assertGreaterThan(WarmPublicContentReadModels::PAYLOAD_BUDGET_BYTES, $entry['bytes'] ?? 0);
    }

    public function test_big_five_warm_accepts_only_fresh_or_miss_cache_states(): void
    {
        $method = new ReflectionMethod(WarmPublicContentReadModels::class, 'bigFiveWarmResponseIsReady');
        $command = app(WarmPublicContentReadModels::class);

        foreach (['fresh' => true, 'miss' => true, 'stale' => false, 'bypass' => false] as $state => $expected) {
            $response = response()->json(['ok' => true])
                ->header('X-Fermat-Public-Read-Cache', $state);
            $this->assertSame($expected, $method->invoke($command, $response), $state);
        }
    }

    public function test_warm_json_suppresses_child_output_and_scopes_career_to_directory_only(): void
    {
        $mbtiCache = app(PersonalityPublicReadModelCache::class);
        $this->seedMbtiReadModels($mbtiCache);

        $careerCache = app(PublicCareerAuthorityResponseCache::class);
        foreach (['en', 'zh-CN'] as $locale) {
            $careerCache->publishDirectoryReadModel($locale, ['items' => []]);
        }

        Artisan::registerCommand(new class extends ConsoleCommand
        {
            protected $signature = 'personality:warm-public-read-models {--locales=}';

            public function handle(): int
            {
                $this->line('nested mbti output that must stay hidden');

                return self::SUCCESS;
            }
        });
        Artisan::registerCommand(new class extends ConsoleCommand
        {
            protected $signature = 'career:warm-public-authority-cache {--directory-only} {--json}';

            public function handle(): int
            {
                Cache::put('test:career-directory-only', (bool) $this->option('directory-only'));
                $this->line('{"nested":"career output that must stay hidden"}');

                return self::SUCCESS;
            }
        });

        $exitCode = Artisan::call('public-content:warm-read-models', [
            '--warm' => true,
            '--json' => true,
        ]);
        $report = $this->jsonOutput();

        $this->assertSame(0, $exitCode, json_encode($report, JSON_THROW_ON_ERROR));
        $this->assertSame('verified', $report['status']);
        $this->assertTrue($report['write_executed']);
        $this->assertTrue(Cache::get('test:career-directory-only'));
        $this->assertStringNotContainsString('nested mbti output', Artisan::output());
        $this->assertStringNotContainsString('nested', Artisan::output());
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
                $cache->activeKey('index', 'big_five', 'all', 'page:1:per-page:100', $locale),
                'big-five-v1',
            );
            Cache::put(
                $cache->key('index', 'big_five', 'all', 'page:1:per-page:100', $locale, 'big-five-v1'),
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
