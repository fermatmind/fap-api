<?php

declare(strict_types=1);

namespace Tests\Feature\SeoIntel;

use App\Services\SeoIntel\UrlTruth\ControlledUrlTruthReconciliationService;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use App\Services\SeoIntel\UrlTruthInventoryRecordWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

final class UrlTruthBatchEfficiencyTest extends TestCase
{
    private bool $ownsSchema = false;

    private const TABLES = ['seo_issue_queue_events', 'seo_detector_opportunities', 'seo_issue_queue', 'seo_url_entities', 'seo_urls'];

    public static function engines(): array
    {
        return ['SQLite' => ['sqlite'], 'isolated MySQL' => ['mysql']];
    }

    protected function tearDown(): void
    {
        if ($this->ownsSchema) {
            foreach (self::TABLES as $table) {
                Schema::connection('seo_intel')->dropIfExists($table);
            }
        }
        Carbon::setTestNow();
        DB::purge('seo_intel');
        parent::tearDown();
    }

    #[DataProvider('engines')]
    public function test_stable_batch_matches_original_writer_and_has_bounded_query_count(string $engine): void
    {
        $this->prepare($engine);
        $records = array_map(fn ($i) => $this->record('job-'.$i), range(1, 250));
        Carbon::setTestNow('2026-10-20T01:00:00Z');
        (new UrlTruthInventoryRecordWriter)->write($records);
        $ids = DB::connection('seo_intel')->table('seo_urls')->pluck('id')->all();
        Carbon::setTestNow('2026-10-20T02:00:00Z');
        $queries = $this->compareOriginal($records);
        $this->assertSame($ids, DB::connection('seo_intel')->table('seo_urls')->pluck('id')->all());
        $this->assertLessThanOrEqual(8, count($queries), 'Stable batches must not perform per-record SQL.');
        $this->assertSame('2026-10-20 01:00:00', DB::connection('seo_intel')->table('seo_urls')->value('first_seen_at'));
        $this->assertSame('2026-10-20 02:00:00', DB::connection('seo_intel')->table('seo_urls')->value('last_seen_at'));
    }

    #[DataProvider('engines')]
    public function test_mutations_history_null_and_duplicate_inputs_preserve_original_semantics(string $engine): void
    {
        $this->prepare($engine);
        $writer = new UrlTruthInventoryRecordWriter;
        Carbon::setTestNow('2026-10-20T01:00:00Z');
        $writer->write([$this->record('old', 'identity'), $this->record('steady')]);
        Carbon::setTestNow('2026-10-20T02:00:00Z');
        foreach ([
            [$this->record('steady'), $this->record('new')],
            [$this->record('new-canonical', 'identity')],
            [$this->record('steady', attributes: ['changed' => true])],
            [$this->record('steady'), $this->record('steady')],
            [$this->record('anonymous', '')],
        ] as $records) {
            $this->compareOriginal($records);
        }
        DB::connection('seo_intel')->table('seo_url_entities')->where('binding_status', 'superseded_canonical')->update(['superseded_by_id' => null]);
        $this->compareOriginal([$this->record('new-canonical', 'identity')]);
        $this->assertSame(2, DB::connection('seo_intel')->table('seo_url_entities')->where('entity_id_or_slug', 'identity')->count());
    }

    #[DataProvider('engines')]
    public function test_json_types_objects_lists_and_key_order_match_original_for_both_fields(string $engine): void
    {
        $this->prepare($engine);
        Carbon::setTestNow('2026-10-20T01:00:00Z');
        (new UrlTruthInventoryRecordWriter)->write([$this->record('typed', attributes: ['value' => 0], metadata: ['value' => 0])]);
        Carbon::setTestNow('2026-10-20T02:00:00Z');
        foreach ([false, 1, '1', (object) [], [], (object) ['a' => 1, 'b' => 2],
            (object) ['b' => 2, 'a' => 1], [1, 2], [2, 1], null] as $value) {
            $this->compareOriginal([$this->record('typed', attributes: ['value' => $value], metadata: ['value' => $value])]);
        }
    }

    #[DataProvider('engines')]
    public function test_lossy_json_numbers_fall_back_to_the_original_writer(string $engine): void
    {
        $this->prepare($engine);
        (new UrlTruthInventoryRecordWriter)->write([$this->record('large')]);
        $db = DB::connection('seo_intel');
        foreach (['9223372036854775809', '1.00000000000000000001', '1e-400'] as $number) {
            $db->table('seo_urls')->update(['metadata_json' => '{"authority_revision":"fixture-v1","value":'.$number.'}']);
            $db->table('seo_url_entities')->update(['attributes_json' => '{"value":'.$number.'}']);
            $this->compareOriginal([$this->record('large', attributes: ['value' => (float) PHP_INT_MAX],
                metadata: ['value' => (float) PHP_INT_MAX])]);
        }
    }

    #[DataProvider('engines')]
    public function test_full_reconciliation_keeps_both_passes_and_only_three_global_plans(string $engine): void
    {
        $this->prepare($engine);
        $records = array_map(fn ($i) => $this->record('job-'.$i), range(1, 300));
        Carbon::setTestNow('2026-10-20T01:00:00Z');
        (new UrlTruthInventoryRecordWriter)->write($records);
        $service = app(ControlledUrlTruthReconciliationService::class);
        $metadata = ['complete_authority_read' => true];
        $dry = $service->run($records, $metadata, false, false);
        $db = DB::connection('seo_intel');
        $originalDispatcher = $db->getEventDispatcher();
        Carbon::setTestNow('2026-10-20T02:00:00Z');
        $db->enableQueryLog();
        $result = $service->run($records, $metadata, true, false, 5000, 250, $dry['plan']['plan_hash'], fn () => [$records, $metadata]);
        $queries = $db->getQueryLog();
        $db->disableQueryLog();
        $db->flushQueryLog();
        $this->assertSame($originalDispatcher, $db->getEventDispatcher());
        $this->assertTrue($result['writes_committed']);
        $this->assertTrue($result['idempotent_rerun']['passed']);
        foreach ($result['batches'] as $batch) {
            $this->assertTrue($batch['database_readback_ok']);
        }
        $sql = array_map(static fn ($q) => str_replace('`', '"', $q['query']), $queries);
        $this->assertCount(3, array_filter($sql, static fn ($q) => str_contains($q, 'from "seo_urls" order by "id"')));
        $this->assertCount(2 * $result['batch_count'], array_filter($sql, static fn ($q) => str_starts_with($q, 'update "seo_urls" set "last_seen_at"')));
    }

    private function compareOriginal(array $records): array
    {
        $db = DB::connection('seo_intel');
        $snapshot = $this->snapshot();
        $legacy = new ReflectionMethod(UrlTruthInventoryRecordWriter::class, 'writeRecord');
        $db->transaction(function () use ($legacy, $db, $records): void {
            foreach ($records as $record) {
                $legacy->invoke(new UrlTruthInventoryRecordWriter, $db, $record, true);
            }
        });
        $expected = $this->snapshot();
        foreach (['seo_url_entities', 'seo_urls'] as $table) {
            $db->table($table)->delete();
            if ($snapshot[$table] !== []) {
                $db->table($table)->insert($snapshot[$table]);
            }
        }
        // MySQL auto-increment is not rewound by DELETE; compare business rows independently of newly allocated IDs.
        $db->enableQueryLog();
        (new UrlTruthInventoryRecordWriter)->write($records);
        $queries = $db->getQueryLog();
        $db->disableQueryLog();
        $db->flushQueryLog();
        $actual = $this->snapshot();
        $normalize = static function (array $snapshot): array {
            $entities = $snapshot['seo_url_entities'];
            $idMap = [];
            foreach ($entities as $row) {
                $idMap[$row['id']] = $row['canonical_url_hash'].'|'.$row['entity_id_or_slug'].'|'.$row['locale'];
            }
            foreach ($snapshot as $table => &$rows) {
                foreach ($rows as &$row) {
                    unset($row['id']);
                    if ($table === 'seo_url_entities' && $row['superseded_by_id'] !== null) {
                        $row['superseded_by_id'] = $idMap[$row['superseded_by_id']];
                    }
                }
            }

            return $snapshot;
        };
        $this->assertSame($normalize($expected), $normalize($actual));

        return $queries;
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (['seo_urls', 'seo_url_entities'] as $table) {
            $result[$table] = DB::connection('seo_intel')->table($table)->orderBy('id')->get()->map(static fn ($r) => (array) $r)->all();
        }

        return $result;
    }

    private function prepare(string $engine): void
    {
        $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false];
        if ($engine === 'mysql') {
            $database = getenv('SEO_TEST_MYSQL_DATABASE');
            if (! $database) {
                $this->markTestSkipped('Requires isolated local MySQL.');
            }
            $this->assertSame('127.0.0.1', getenv('SEO_TEST_MYSQL_HOST'));
            $this->assertMatchesRegularExpression('/^seo_operations_[a-z0-9_]+_test$/D', $database);
            $connection = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => getenv('SEO_TEST_MYSQL_PORT'),
                'database' => $database, 'username' => 'root', 'password' => getenv('SEO_TEST_MYSQL_PASSWORD') ?: '',
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true];
        }
        config(['database.default' => 'seo_intel', 'database.connections.seo_intel' => $connection,
            'seo_intel.connection' => 'seo_intel', 'seo_intel.enabled' => true, 'seo_intel.write_enabled' => true]);
        DB::purge('seo_intel');
        foreach (self::TABLES as $table) {
            $this->assertFalse(Schema::connection('seo_intel')->hasTable($table));
        }
        $this->ownsSchema = true;
        foreach (['2026_05_17_000100_create_seo_urls_table.php', '2026_05_17_000200_create_seo_url_entities_table.php',
            '2026_05_17_001700_create_seo_issue_queue_table.php', '2026_08_25_010000_expand_detector_queue_materialization.php',
            '2026_08_25_020000_expand_url_truth_current_bindings.php'] as $file) {
            (require database_path('migrations/seo_intel/'.$file))->up();
        }
    }

    private function record(string $slug, ?string $identity = null, array $attributes = [], array $metadata = []): UrlTruthInventoryRecord
    {
        return new UrlTruthInventoryRecord(canonicalUrl: 'https://fermatmind.com/en/career/jobs/'.$slug,
            locale: 'en', pageEntityType: 'career_job', entityIdOrSlug: $identity ?? $slug,
            sourceAuthority: 'career_runtime_publish_projection', entitySource: 'career_directory_authority',
            authorityStatus: 'published_approved', metadata: ['authority_revision' => 'fixture-v1'] + $metadata, attributes: $attributes);
    }
}
