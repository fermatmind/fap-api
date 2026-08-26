<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\CrawlerLog\CrawlerLogAggregateStorageWriter;
use App\Services\SeoIntel\CrawlerLog\CrawlerLogScheduledAggregateCollector;
use App\Services\SeoIntel\CrawlerLog\CrawlerLogSingleSourceReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class SeoPlatform07CrawlerAggregateRuntimeTest extends TestCase
{
    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.seo_intel' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('seo_intel');
        $this->createAggregateTable();
        $this->sourcePath = tempnam(sys_get_temp_dir(), 'seo07-crawler-source-');
        $this->assertIsString($this->sourcePath);
    }

    protected function tearDown(): void
    {
        @unlink($this->sourcePath);
        DB::disconnect('seo_intel');
        parent::tearDown();
    }

    #[Test]
    public function source_reader_returns_only_the_newest_bounded_tail(): void
    {
        file_put_contents($this->sourcePath, implode("\n", range(1, 1105))."\n");

        $lines = app(CrawlerLogSingleSourceReader::class)->readTail($this->sourcePath, 1000);

        $this->assertCount(1000, $lines);
        $this->assertSame('106', $lines[0]);
        $this->assertSame('1105', $lines[999]);
    }

    #[Test]
    public function scheduled_collection_reads_only_recent_sanitized_crawlers_and_is_idempotent(): void
    {
        file_put_contents($this->sourcePath, implode("\n", [
            $this->line('23/Aug/2026:13:59:00 +0000', '/en', 'Googlebot/2.1'),
            $this->line('26/Aug/2026:13:59:00 +0000', '/en', 'Mozilla/5.0'),
            $this->line('26/Aug/2026:13:59:10 +0000', '/en/take', 'Googlebot/2.1'),
            $this->line('26/Aug/2026:13:59:20 +0000', '/en?token=private', 'Googlebot/2.1'),
            $this->line('26/Aug/2026:13:59:30 +0000', '/en', 'Googlebot/2.1'),
        ])."\n");
        $this->enableRuntime();

        $first = app(CrawlerLogScheduledAggregateCollector::class)->collect(CarbonImmutable::parse('2026-08-26T14:00:00Z'));
        $second = app(CrawlerLogScheduledAggregateCollector::class)->collect(CarbonImmutable::parse('2026-08-26T14:10:00Z'));

        $this->assertSame('success', $first['status']);
        $this->assertSame(3, $first['eligible_crawler_observation_count']);
        $this->assertSame(3, $first['aggregate_row_count']);
        $this->assertTrue($first['writes_committed']);
        $this->assertFalse($first['raw_persistence']);
        $this->assertSame('success', $second['status']);
        $this->assertSame(3, DB::connection('seo_intel')->table(CrawlerLogAggregateStorageWriter::TARGET_TABLE)->count());
        $row = (array) DB::connection('seo_intel')->table(CrawlerLogAggregateStorageWriter::TARGET_TABLE)
            ->where('canonical_path', '/en')
            ->first();
        $this->assertSame('googlebot', $row['bot_family'] ?? null);
        $this->assertSame('/en', $row['canonical_path'] ?? null);
        $this->assertSame(1, $row['hit_count'] ?? null);
        $this->assertArrayNotHasKey('raw_user_agent', $row);
        $this->assertArrayNotHasKey('raw_log_line', $row);
        $private = (array) DB::connection('seo_intel')->table(CrawlerLogAggregateStorageWriter::TARGET_TABLE)
            ->where('private_path_blocked', true)
            ->first();
        $this->assertNull($private['canonical_path'] ?? null);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) ($private['path_hash'] ?? ''));
    }

    #[Test]
    public function disabled_gates_or_absent_recent_crawler_evidence_fail_closed_without_writes(): void
    {
        file_put_contents($this->sourcePath, $this->line('26/Aug/2026:13:59:00 +0000', '/en', 'Mozilla/5.0')."\n");
        config([
            'seo_intel.crawler_log_source' => $this->sourcePath,
            'seo_intel.crawler_log_aggregate_storage.scheduler_enabled' => false,
            'seo_intel.crawler_log_aggregate_storage.production_log_read_allowed' => false,
            'seo_intel.crawler_log_aggregate_storage.write_enabled' => false,
        ]);

        $gated = app(CrawlerLogScheduledAggregateCollector::class)->collect(CarbonImmutable::parse('2026-08-26T14:00:00Z'));
        $this->assertSame('MEASUREMENT_HOLD', $gated['status']);
        $this->assertContains('scheduler_gate_disabled', $gated['issues']);
        $this->assertFalse($gated['production_log_read_attempted']);

        $this->enableRuntime();
        $empty = app(CrawlerLogScheduledAggregateCollector::class)->collect(CarbonImmutable::parse('2026-08-26T14:00:00Z'));
        $this->assertSame('MEASUREMENT_HOLD', $empty['status']);
        $this->assertSame(['no_recent_crawler_observation'], $empty['issues']);
        $this->assertTrue($empty['production_log_read_attempted']);
        $this->assertFalse($empty['writes_attempted']);
        $this->assertSame(0, DB::connection('seo_intel')->table(CrawlerLogAggregateStorageWriter::TARGET_TABLE)->count());
    }

    #[Test]
    public function scheduled_command_emits_only_sanitized_bounded_evidence(): void
    {
        file_put_contents($this->sourcePath, $this->line(now('UTC')->format('d/M/Y:H:i:s O'), '/en', 'Googlebot/2.1')."\n");
        $this->enableRuntime();

        $exit = Artisan::call('seo-intel:crawler-log-aggregate-scheduled', ['--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame('success', $payload['status'] ?? null);
        $this->assertTrue($payload['scheduler_enabled'] ?? false);
        $this->assertFalse($payload['raw_url_emitted'] ?? true);
        $this->assertFalse($payload['query_emitted'] ?? true);
        $this->assertFalse($payload['user_agent_emitted'] ?? true);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($this->sourcePath, $encoded);
        $this->assertStringNotContainsString('Googlebot', $encoded);
    }

    #[Test]
    public function gated_schedule_runs_collector_before_the_runtime_receipt_with_cluster_guards(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));
        $collectorPosition = strpos($bootstrap, 'seo-intel:crawler-log-aggregate-scheduled --json');
        $receiptPosition = strpos($bootstrap, 'seo:runtime-probe-scheduled --trigger=scheduled --json');

        $this->assertIsInt($collectorPosition);
        $this->assertIsInt($receiptPosition);
        $this->assertLessThan($receiptPosition, $collectorPosition);
        $this->assertMatchesRegularExpression(
            '/crawler-log-aggregate-scheduled[\\s\\S]+user\(\'www-data\'\)[\\s\\S]+everyTenMinutes\(\)[\\s\\S]+withoutOverlapping\(20\)[\\s\\S]+onOneServer\(\)/',
            $bootstrap,
        );

        $process = new Process(
            [PHP_BINARY, 'artisan', 'schedule:list', '--json', '--no-ansi'],
            base_path(),
            ['APP_ENV' => 'testing', 'SEO_INTEL_CRAWLER_LOG_SCHEDULER_ENABLED' => 'true'],
        );
        $process->mustRun();
        $event = collect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->first(
            fn (array $candidate): bool => str_contains((string) ($candidate['command'] ?? ''), 'crawler-log-aggregate-scheduled'),
        );

        $this->assertIsArray($event);
        $this->assertSame('*/10 * * * *', $event['expression'] ?? null);

        $identityInspection = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$kernel->call('schedule:list', ['--json' => true, '--no-ansi' => true]);
foreach ($app->make(Illuminate\Console\Scheduling\Schedule::class)->events() as $event) {
    if (str_contains($event->command ?? '', 'crawler-log-aggregate-scheduled')) {
        echo $event->user ?? 'missing';
    }
}
PHP;
        $identityProcess = new Process(
            [PHP_BINARY, '-r', $identityInspection],
            base_path(),
            ['APP_ENV' => 'testing', 'SEO_INTEL_CRAWLER_LOG_SCHEDULER_ENABLED' => 'true'],
        );
        $identityProcess->mustRun();
        $this->assertSame('www-data', trim($identityProcess->getOutput()));
    }

    private function enableRuntime(): void
    {
        config([
            'seo_intel.connection' => 'seo_intel',
            'seo_intel.crawler_log_source' => $this->sourcePath,
            'seo_intel.crawler_log_aggregate_storage.scheduler_enabled' => true,
            'seo_intel.crawler_log_aggregate_storage.production_log_read_allowed' => true,
            'seo_intel.crawler_log_aggregate_storage.write_enabled' => true,
            'seo_intel.crawler_log_aggregate_storage.max_lines' => 1000,
            'seo_intel.crawler_log_aggregate_storage.maximum_source_age_minutes' => 2880,
        ]);
    }

    private function line(string $time, string $target, string $userAgent): string
    {
        return sprintf(
            '203.0.113.10 - - [%s] "GET %s HTTP/1.1" 200 123 "-" "%s" host=fermatmind.com',
            $time,
            $target,
            $userAgent,
        );
    }

    private function createAggregateTable(): void
    {
        Schema::connection('seo_intel')->create(CrawlerLogAggregateStorageWriter::TARGET_TABLE, function (Blueprint $table): void {
            $table->id();
            $table->date('log_date');
            $table->string('host');
            $table->string('surface_family');
            $table->string('bot_family');
            $table->string('bot_variant');
            $table->string('bot_verification_state');
            $table->string('route_family');
            $table->string('page_entity_type')->nullable();
            $table->string('canonical_path')->nullable();
            $table->string('path_hash')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('method_bucket');
            $table->boolean('query_present');
            $table->string('query_risk_state');
            $table->boolean('private_path_blocked');
            $table->unsignedInteger('hit_count');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('source_log_family');
            $table->string('privacy_transform_version');
            $table->string('idempotency_key')->unique();
            $table->timestamps();
        });
    }
}
