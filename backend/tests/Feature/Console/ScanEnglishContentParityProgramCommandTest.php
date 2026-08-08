<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ScanEnglishContentParityProgramCommandTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $process = new Process(['rm', '-rf', $directory]);
            $process->run();
        }
        parent::tearDown();
    }

    public function test_it_builds_a_deduplicated_read_only_program_ledger(): void
    {
        $web = $this->repository('fap-web', [
            'EN-PARITY-W1-MBTI-ASSETS-01: package (#101)',
            'EN-PARITY-W1-MBTI-ASSETS-01: ledger closeout (#101)',
            'unrelated commit (#102)',
        ]);
        $api = $this->repository('fap-api', [
            'CONTENT-PROMOTION-W4-RIASEC-01: adapter (#201)',
        ]);
        $lanes = array_map(static fn (int $lane): array => ['lane_id' => 'W'.$lane, 'status' => $lane === 1 ? 'qa_pass' : 'not_started', 'subscopes' => []], range(1, 9));
        $lanes[2] = ['lane_id' => 'W3', 'status' => 'inventory_frozen', 'subscopes' => [
            ['id' => 'W3-ARTICLES', 'status' => 'live_qa_pass'],
            ['id' => 'W3-CAREER-GUIDES', 'status' => 'dry_run_ready'],
        ]];
        $this->writeJson($web.'/docs/seo/generated/en-content-parity-control-master.v2.json', ['lanes' => $lanes]);
        $duplicateReceipt = ['lane_id' => 'W5', 'subscope' => 'enneagram-results', 'target_status' => 'live_qa_pass', 'package_sha256' => str_repeat('b', 64), 'receipt_paths' => ['receipt.json']];
        $this->writeJson($web.'/docs/seo/generated/en-content-parity-control-inputs.v2.json', [
            'lane_manifests' => [],
            'receipt_chains' => [
                ['lane_id' => 'W1', 'subscope' => null, 'target_status' => 'live_qa_pass', 'package_sha256' => str_repeat('a', 64), 'receipt_paths' => ['receipt.json']],
                $duplicateReceipt,
                $duplicateReceipt,
            ],
        ]);
        $this->writeJson($web.'/generated/en-content-parity/v2/W1/lane_manifest.json', ['lane_id' => 'W1', 'status' => 'live_qa_pass', 'package_sha256' => str_repeat('a', 64)]);
        $this->writeJson($web.'/generated/en-content-parity/v2/W3-articles/lane_manifest.json', ['lane_id' => 'W3-ARTICLES', 'status' => 'live_qa_pass', 'package_sha256' => str_repeat('c', 64)]);
        $this->writeJson($web.'/generated/en-content-parity/v2/W3-career-guides/lane_manifest.json', ['lane_id' => 'W3-CAREER-GUIDES', 'status' => 'dry_run_ready', 'package_sha256' => str_repeat('d', 64)]);
        $this->commitAll($web, 'control evidence');

        $lanes[0]['status'] = 'not_started';
        $this->writeJson($web.'/docs/seo/generated/en-content-parity-control-master.v2.json', ['lanes' => $lanes]);

        $retryingApi = Http::sequence()
            ->push(['ok' => false], 500, ['Content-Type' => 'application/json'])
            ->push(['ok' => false], 500, ['Content-Type' => 'application/json'])
            ->push(['ok' => false], 500, ['Content-Type' => 'application/json'])
            ->push(['ok' => false], 500, ['Content-Type' => 'application/json']);
        Http::fake([
            'https://fermatmind.com/sitemap.xml' => static fn () => Http::response('<?xml version="1.0"?><urlset><url><loc>https://fermatmind.com/en</loc></url><url><loc>https://fermatmind.com/zh</loc></url><url><loc>https://fermatmind.com/reports/private-token</loc></url></urlset>', 200, ['Content-Type' => 'application/xml']),
            'https://fermatmind.com/en' => fn () => Http::response(str_replace('</head>', '<meta content="noindex,follow" name="robots"></head>', $this->html('en', 'https://fermatmind.com/en', 'English content '.str_repeat('safe content ', 20))), 200, ['Content-Type' => 'text/html']),
            'https://fermatmind.com/zh' => fn () => Http::response($this->html('zh-CN', 'https://fermatmind.com/zh', '中文内容'.str_repeat('安全内容', 20)), 200, ['Content-Type' => 'text/html']),
            'https://fermatmind.com/llms.txt' => static fn () => Http::response('FermatMind English authority', 200, ['Content-Type' => 'text/plain']),
            'https://fermatmind.com/llms-full.txt' => static fn () => Http::response('FermatMind full English authority', 200, ['Content-Type' => 'text/plain']),
            'https://api.fermatmind.com/api/v0.5/articles?locale=en&per_page=20' => $retryingApi,
            'https://api.fermatmind.com/*' => static fn () => Http::response(['ok' => true, 'items' => [['slug' => 'one', 'locale' => 'en']]], 200, ['Content-Type' => 'application/json']),
        ]);

        $output = $this->temporaryDirectory().'/ledger.json';
        $exitCode = Artisan::call('en-parity:scan-program', [
            '--fap-web-root' => $web,
            '--fap-api-root' => $api,
            '--max-urls' => 50,
            '--concurrency' => 2,
            '--output' => $output,
            '--json' => true,
        ]);
        self::assertSame(0, $exitCode, Artisan::output());

        $ledger = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('english-content-parity-program-ledger.v1', $ledger['schema_version']);
        self::assertSame(3, $ledger['summary']['candidate_commit_count']);
        self::assertSame(2, $ledger['summary']['deduplicated_task_count']);
        self::assertSame(2, $ledger['summary']['sitemap_url_count']);
        self::assertCount(9, $ledger['lanes']);
        self::assertSame('deferred', $ledger['lanes'][5]['repository_state']);
        self::assertSame('qa_capability', $ledger['lanes'][8]['lane_kind']);
        self::assertTrue($ledger['lanes'][0]['live_verified']);
        self::assertSame('qa_pass', $ledger['lanes'][0]['control_state']);
        self::assertSame('dry_run_ready', $ledger['lanes'][2]['repository_state']);
        self::assertFalse($ledger['lanes'][2]['live_verified']);
        self::assertContains('duplicate_v2_input', array_column($ledger['control_drift'], 'type'));
        self::assertContains('receipt_chain_ahead_of_materialized_master', array_column($ledger['control_drift'], 'type'));
        self::assertContains('lane_manifest_not_registered_in_v2_inputs', array_column($ledger['control_drift'], 'type'));
        self::assertFalse($ledger['negative_guarantees']['cms_write']);
        self::assertContains('private_path_in_public_inventory_not_fetched', array_column($ledger['live_scan']['findings'], 'code'));
        self::assertContains('public_sitemap_page_noindex', array_column($ledger['live_scan']['findings'], 'code'));

        $secondOutput = $this->temporaryDirectory().'/ledger.json';
        self::assertSame(0, Artisan::call('en-parity:scan-program', [
            '--fap-web-root' => $web,
            '--fap-api-root' => $api,
            '--max-urls' => 50,
            '--concurrency' => 2,
            '--output' => $secondOutput,
            '--json' => true,
        ]), Artisan::output());
        $secondLedger = json_decode((string) file_get_contents($secondOutput), true, flags: JSON_THROW_ON_ERROR);
        unset($ledger['generated_at'], $ledger['scan_window']['until'], $secondLedger['generated_at'], $secondLedger['scan_window']['until']);
        self::assertSame($ledger, $secondLedger);

        self::assertNotEmpty(Http::recorded());
        foreach (Http::recorded() as [$request]) {
            self::assertSame('GET', $request->method());
            self::assertContains((string) parse_url($request->url(), PHP_URL_HOST), ['fermatmind.com', 'api.fermatmind.com']);
            self::assertFalse($request->hasHeader('Cookie'));
            self::assertStringNotContainsString('/reports/private-token', $request->url());
        }
        $retriedRequests = Http::recorded()->filter(static fn (array $record): bool => $record[0]->url() === 'https://api.fermatmind.com/api/v0.5/articles?locale=en&per_page=20');
        self::assertCount(4, $retriedRequests, 'Two scans must issue exactly one retry each.');
    }

    public function test_it_rejects_non_allowlisted_hosts_without_http_requests(): void
    {
        Http::fake();
        $web = $this->repository('fap-web', []);
        $api = $this->repository('fap-api', []);

        $this->artisan('en-parity:scan-program', [
            '--site-base' => 'https://example.com',
            '--fap-web-root' => $web,
            '--fap-api-root' => $api,
            '--json' => true,
        ])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_it_fails_closed_for_malformed_root_sitemap(): void
    {
        $web = $this->repositoryWithControl('fap-web');
        $api = $this->repository('fap-api', []);
        Http::fake([
            'https://fermatmind.com/sitemap.xml' => static fn () => Http::response('<urlset><url>', 200, ['Content-Type' => 'application/xml']),
        ]);

        $this->artisan('en-parity:scan-program', [
            '--fap-web-root' => $web,
            '--fap-api-root' => $api,
            '--json' => true,
        ])->expectsOutputToContain('root_sitemap_malformed')->assertFailed();
    }

    public function test_it_fails_closed_for_malformed_control_json(): void
    {
        Http::fake();
        $web = $this->repository('fap-web', []);
        $api = $this->repository('fap-api', []);
        $master = $web.'/docs/seo/generated/en-content-parity-control-master.v2.json';
        if (! is_dir(dirname($master))) {
            mkdir(dirname($master), 0775, true);
        }
        file_put_contents($master, '{invalid');
        $this->writeJson($web.'/docs/seo/generated/en-content-parity-control-inputs.v2.json', ['lane_manifests' => [], 'receipt_chains' => []]);
        $this->commitAll($web, 'malformed control evidence');

        $this->artisan('en-parity:scan-program', [
            '--fap-web-root' => $web,
            '--fap-api-root' => $api,
            '--json' => true,
        ])->expectsOutputToContain('invalid_control_json')->assertFailed();

        Http::assertNothingSent();
    }

    /** @param list<string> $subjects */
    private function repository(string $name, array $subjects): string
    {
        $directory = $this->temporaryDirectory().'/'.$name;
        mkdir($directory, 0775, true);
        $this->runProcess($directory, ['git', 'init', '-b', 'main']);
        $this->runProcess($directory, ['git', 'config', 'user.email', 'tests@fermatmind.com']);
        $this->runProcess($directory, ['git', 'config', 'user.name', 'FermatMind Tests']);
        file_put_contents($directory.'/seed.txt', "seed\n");
        $this->runProcess($directory, ['git', 'add', 'seed.txt']);
        $this->runProcess($directory, ['git', 'commit', '-m', 'seed']);
        foreach ($subjects as $index => $subject) {
            file_put_contents($directory.'/seed.txt', "seed {$index}\n", FILE_APPEND);
            $this->runProcess($directory, ['git', 'add', 'seed.txt']);
            $this->runProcess($directory, ['git', 'commit', '-m', $subject]);
        }
        $this->runProcess($directory, ['git', 'update-ref', 'refs/remotes/origin/main', 'HEAD']);

        return $directory;
    }

    private function repositoryWithControl(string $name): string
    {
        $repository = $this->repository($name, []);
        $this->writeJson($repository.'/docs/seo/generated/en-content-parity-control-master.v2.json', ['lanes' => []]);
        $this->writeJson($repository.'/docs/seo/generated/en-content-parity-control-inputs.v2.json', ['lane_manifests' => [], 'receipt_chains' => []]);
        $this->commitAll($repository, 'control evidence');

        return $repository;
    }

    private function commitAll(string $directory, string $message): void
    {
        $this->runProcess($directory, ['git', 'add', '.']);
        $this->runProcess($directory, ['git', 'commit', '-m', $message]);
        $this->runProcess($directory, ['git', 'update-ref', 'refs/remotes/origin/main', 'HEAD']);
    }

    /** @param list<string> $command */
    private function runProcess(string $directory, array $command): void
    {
        $process = new Process($command, $directory);
        $process->mustRun();
    }

    /** @param array<string,mixed> $payload */
    private function writeJson(string $path, array $payload): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function html(string $locale, string $canonical, string $body): string
    {
        return '<!doctype html><html lang="'.$locale.'"><head><link rel="canonical" href="'.$canonical.'"><link rel="alternate" hreflang="en" href="https://fermatmind.com/en"><link rel="alternate" hreflang="zh-CN" href="https://fermatmind.com/zh"></head><body>'.$body.'</body></html>';
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/fm-en-parity-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0775, true);
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
