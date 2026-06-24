<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Console\Commands\PersonalityMbti64GscQueryReadonlyExport;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PersonalityMbti64GscQueryReadonlyExportCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ConsoleKernel::class)->registerCommand(
            $this->app->make(PersonalityMbti64GscQueryReadonlyExport::class)
        );
    }

    #[Test]
    public function preflight_export_reads_mbti64_targets_without_external_calls_or_writes(): void
    {
        Http::fake();
        $targets = $this->targetFile([
            '/en/personality/enfj-a',
            'https://fermatmind.com/zh/personality/intp-a',
        ]);

        $exitCode = Artisan::call('personality:mbti64-gsc-query-readonly-export', [
            '--targets' => $targets,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exitCode);
        $this->assertSame('MBTI64-GSC-API-READONLY-INTEGRATION-01', $decoded['task'] ?? null);
        $this->assertSame('preflight_only', $decoded['mode'] ?? null);
        $this->assertSame(2, $decoded['target_count'] ?? null);
        $this->assertSame(0, $decoded['query_row_count'] ?? null);
        $this->assertFalse((bool) data_get($decoded, 'safety_boundary.external_calls_attempted', true));
        $this->assertFalse((bool) data_get($decoded, 'safety_boundary.writes_attempted', true));
        $this->assertFalse((bool) data_get($decoded, 'safety_boundary.cms_mutation_attempted', true));
        $this->assertFalse((bool) data_get($decoded, 'safety_boundary.enqueue_attempted', true));
        $this->assertFalse((bool) data_get($decoded, 'safety_boundary.search_submission_attempted', true));
        $this->assertSame('https://fermatmind.com/en/personality/enfj-a', data_get($decoded, 'targets.0.canonical_url'));
        $this->assertSame('https://fermatmind.com/zh/personality/intp-a', data_get($decoded, 'targets.1.canonical_url'));
        Http::assertNothingSent();
    }

    #[Test]
    public function live_read_export_uses_exact_page_filters_and_writes_importer_csv_without_mutations(): void
    {
        $this->enableAccessTokenConfig();
        $targets = $this->targetFile([
            '/en/personality/enfj-a',
            '/zh/personality/intp-a',
        ]);
        $csvOutput = storage_path('framework/testing/mbti64-gsc-'.Str::uuid()->toString().'.csv');

        Http::fake(function (Request $request) {
            $target = data_get($request->data(), 'dimensionFilterGroups.0.filters.0.expression');

            return Http::response([
                'rows' => [
                    [
                        'keys' => [$target === 'https://fermatmind.com/en/personality/enfj-a' ? 'enfj a personality' : 'intp-a 人格', $target],
                        'clicks' => $target === 'https://fermatmind.com/en/personality/enfj-a' ? 1 : 0,
                        'impressions' => $target === 'https://fermatmind.com/en/personality/enfj-a' ? 12 : 7,
                        'ctr' => $target === 'https://fermatmind.com/en/personality/enfj-a' ? 0.0833 : 0.0,
                        'position' => $target === 'https://fermatmind.com/en/personality/enfj-a' ? 8.2 : 10.4,
                    ],
                ],
            ], 200);
        });

        $exitCode = Artisan::call('personality:mbti64-gsc-query-readonly-export', [
            '--targets' => $targets,
            '--start-date' => '2026-06-20',
            '--end-date' => '2026-06-23',
            '--limit-per-url' => 10,
            '--dry-run' => true,
            '--execute-live-read' => true,
            '--csv-output' => $csvOutput,
            '--json' => true,
        ]);

        $decoded = json_decode(trim(Artisan::output()), true);
        $csv = (string) file_get_contents($csvOutput);

        $this->assertSame(0, $exitCode);
        $this->assertSame('live_read', $decoded['mode'] ?? null);
        $this->assertSame(2, $decoded['query_row_count'] ?? null);
        $this->assertTrue((bool) data_get($decoded, 'safety_boundary.external_calls_attempted', false));
        $this->assertFalse((bool) data_get($decoded, 'safety_boundary.writes_attempted', true));
        $this->assertFalse((bool) data_get($decoded, 'safety_boundary.search_submission_attempted', true));
        $this->assertSame('enfj a personality', data_get($decoded, 'query_rows.0.query'));
        $this->assertNotEmpty(data_get($decoded, 'query_rows.0.query_hash'));
        $this->assertStringContainsString('target_url,path,query,query_hash,clicks,impressions,ctr,position,start_date,end_date,source', $csv);
        $this->assertStringContainsString('https://fermatmind.com/en/personality/enfj-a', $csv);

        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            return data_get($request->data(), 'dimensionFilterGroups.0.filters.0.dimension') === 'page'
                && data_get($request->data(), 'dimensionFilterGroups.0.filters.0.operator') === 'equals'
                && in_array(data_get($request->data(), 'dimensionFilterGroups.0.filters.0.expression'), [
                    'https://fermatmind.com/en/personality/enfj-a',
                    'https://fermatmind.com/zh/personality/intp-a',
                ], true)
                && $request['rowLimit'] === 10
                && $request['dimensions'] === ['query', 'page'];
        });
    }

    #[Test]
    public function live_read_fails_closed_when_gsc_preflight_is_blocked(): void
    {
        Http::fake();
        $targets = $this->targetFile(['/en/personality/enfj-a']);

        $exitCode = Artisan::call('personality:mbti64-gsc-query-readonly-export', [
            '--targets' => $targets,
            '--start-date' => '2026-06-20',
            '--end-date' => '2026-06-23',
            '--dry-run' => true,
            '--execute-live-read' => true,
            '--json' => true,
        ]);

        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse((bool) ($decoded['ok'] ?? true));
        $this->assertContains('gsc_enabled_false', $decoded['issues'] ?? []);
        $this->assertFalse((bool) data_get($decoded, 'safety_boundary.writes_attempted', true));
        $this->assertFalse((bool) data_get($decoded, 'safety_boundary.external_calls_attempted', true));
        Http::assertNothingSent();
    }

    #[Test]
    public function command_rejects_private_or_non_personality_targets_before_any_gsc_call(): void
    {
        Http::fake();
        $targets = $this->targetFile([
            '/en/results/lookup',
            '/en/personality/enfj-a',
        ]);

        $exitCode = Artisan::call('personality:mbti64-gsc-query-readonly-export', [
            '--targets' => $targets,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exitCode);
        $this->assertFalse((bool) ($decoded['ok'] ?? true));
        $this->assertStringContainsString('forbidden private route target', (string) ($decoded['error'] ?? ''));
        $this->assertFalse((bool) data_get($decoded, 'safety_boundary.writes_attempted', true));
        Http::assertNothingSent();
    }

    private function enableAccessTokenConfig(): void
    {
        config([
            'seo_intel.gsc_enabled' => true,
            'seo_intel.gsc_live_api_enabled' => true,
            'seo_intel.allow_external_api_calls' => true,
            'seo_intel.gsc_property_url' => 'sc-domain:fermatmind.com',
            'seo_intel.gsc_readonly_adapter.auth_mode' => 'access_token',
            'seo_intel.gsc_readonly_adapter.access_token' => 'secret-gsc-token',
            'seo_intel.gsc_readonly_adapter.default_limit' => 250,
            'seo_intel.gsc_readonly_adapter.max_limit' => 250,
        ]);
    }

    /**
     * @param  list<string>  $urls
     */
    private function targetFile(array $urls): string
    {
        $path = storage_path('framework/testing/mbti64-gsc-targets-'.Str::uuid()->toString().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(['targets' => $urls], JSON_THROW_ON_ERROR));

        return $path;
    }
}
