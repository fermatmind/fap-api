<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\GreenfieldBaseline\GreenfieldBaselineCatalog;
use App\Domain\GreenfieldBaseline\GreenfieldBaselineImporter;
use App\Domain\GreenfieldBaseline\GreenfieldBaselineJson;
use App\Domain\GreenfieldBaseline\GreenfieldBaselinePackageBuilder;
use App\Domain\GreenfieldBaseline\GreenfieldBaselinePackageVerifier;
use App\Domain\GreenfieldBaseline\GreenfieldBaselineSourceScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

final class GreenfieldBaselineTransferTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryRoot = sys_get_temp_dir().'/greenfield-baseline-test-'.bin2hex(random_bytes(8));
        mkdir($this->temporaryRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->temporaryRoot);
        parent::tearDown();
    }

    #[Test]
    public function source_exporter_is_standalone_select_only_and_never_emits_secrets(): void
    {
        $source = (new GreenfieldBaselineSourceScript)->render();

        foreach ([
            'SET TRANSACTION READ ONLY',
            'START TRANSACTION WITH CONSISTENT SNAPSHOT',
            '$exists->closeCursor()',
            '$columnsStatement->closeCursor()',
            '$statement->closeCursor()',
            '$pdo->rollBack()',
            "'GREENFIELD_SOURCE_EXPORT_FAILED_STAGE_%s_DATASET_%d%s'",
            "'_PDO_'.(string) \$candidate",
            "'writes_committed' => false",
            "'type' => 'row'",
            "'type' => 'artifact'",
            "'NULL AS '.\$quotedColumn",
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }

        foreach ([
            'file_put_contents(', 'mkdir(', 'unlink(', 'rename(', 'touch(',
            'chmod(', 'chown(', 'DROP ', 'DELETE ', 'UPDATE ', 'INSERT ',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }

        $this->assertStringNotContainsString("'password' =>", $source);
        $this->assertStringNotContainsString("'host' =>", $source);
        $this->assertStringNotContainsString("'database' =>", $source);
        $this->assertStringNotContainsString('getMessage()', $source);
        $this->assertLessThan(
            strpos($source, '$pdo->rollBack()'),
            strpos($source, '$statement->closeCursor()'),
        );
    }

    #[Test]
    public function same_stream_builds_the_same_package_and_keeps_only_allowlisted_rows(): void
    {
        [$stream, $projectionSha] = $this->writeFixtureStream();
        $builder = new GreenfieldBaselinePackageBuilder;

        $first = $builder->build($stream, $this->temporaryRoot.'/package-a', $projectionSha, enforceProductionCounts: false);
        $second = $builder->build($stream, $this->temporaryRoot.'/package-b', $projectionSha, enforceProductionCounts: false);

        $this->assertSame($first['package_sha256'], $second['package_sha256']);
        $this->assertSame(1, $first['dataset_counts']['skus']);
        foreach (GreenfieldBaselineCatalog::forbiddenDatasetNames() as $dataset) {
            $this->assertArrayNotHasKey($dataset, $first['dataset_counts']);
            $this->assertFileDoesNotExist($this->temporaryRoot.'/package-a/datasets/'.$dataset.'.jsonl');
        }

        $verified = (new GreenfieldBaselinePackageVerifier)->verify(
            $this->temporaryRoot.'/package-a',
            (string) $first['package_sha256'],
            false,
        );
        $this->assertSame('ready', $verified['status']);
        $this->assertFalse($verified['writes_committed']);
    }

    #[Test]
    public function package_builder_rejects_forbidden_identity_fields(): void
    {
        [$stream, $projectionSha] = $this->writeFixtureStream([
            'dataset' => 'skus',
            'row' => $this->skuRow() + ['password' => 'must-not-export'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Forbidden field entered Greenfield stream');

        (new GreenfieldBaselinePackageBuilder)->build(
            $stream,
            $this->temporaryRoot.'/unsafe-package',
            $projectionSha,
            enforceProductionCounts: false,
        );
    }

    #[Test]
    public function importer_is_dry_run_by_default_and_apply_requires_exact_empty_target_binding(): void
    {
        [$stream, $projectionSha] = $this->writeFixtureStream();
        $manifest = (new GreenfieldBaselinePackageBuilder)->build(
            $stream,
            $this->temporaryRoot.'/import-package',
            $projectionSha,
            enforceProductionCounts: false,
        );
        $this->emptyPackageTables();

        $importer = new GreenfieldBaselineImporter(new GreenfieldBaselinePackageVerifier, false);
        $dryRun = $importer->run($this->temporaryRoot.'/import-package', false, null, null);
        $this->assertSame('ready', $dryRun['status']);
        $this->assertFalse($dryRun['writes_committed']);
        $this->assertDatabaseMissing('skus', ['sku' => 'greenfield-current']);

        config(['greenfield-baseline.import_enabled' => true]);
        $databaseSha = hash('sha256', (string) DB::connection()->getDatabaseName());
        $applied = $importer->run(
            $this->temporaryRoot.'/import-package',
            true,
            'IMPORT_GREENFIELD_BASELINE:'.$manifest['package_sha256'],
            $databaseSha,
        );

        $this->assertSame('imported', $applied['status']);
        $this->assertTrue($applied['writes_committed']);
        $this->assertDatabaseHas('skus', ['sku' => 'greenfield-current', 'is_active' => 1]);
    }

    /**
     * @param  array{dataset: string, row: array<string, mixed>}|null  $replacement
     * @return array{string, string}
     */
    private function writeFixtureStream(?array $replacement = null): array
    {
        $projection = GreenfieldBaselineJson::encode([
            'projection_kind' => 'career_runtime_publish_projection',
            'items' => [],
        ], true)."\n";
        $projectionSha = hash('sha256', $projection);
        $counts = [];
        foreach (GreenfieldBaselineCatalog::datasets() as $definition) {
            $counts[(string) $definition['name']] = 0;
        }
        $rowRecord = $replacement ?? ['dataset' => 'skus', 'row' => $this->skuRow()];
        $counts[$rowRecord['dataset']] = 1;
        ksort($counts, SORT_STRING);

        $records = [
            [
                'type' => 'header',
                'schema_version' => GreenfieldBaselineCatalog::STREAM_SCHEMA,
                'active_revision' => str_repeat('a', 40),
                'source_database_name_sha256' => hash('sha256', 'source-greenfield-fixture'),
                'writes_committed' => false,
            ],
            ['type' => 'row'] + $rowRecord,
            [
                'type' => 'artifact',
                'name' => 'career_runtime_publish_projection',
                'sha256' => $projectionSha,
                'content_base64' => base64_encode($projection),
            ],
            ['type' => 'footer', 'counts' => $counts, 'writes_committed' => false],
        ];
        $path = $this->temporaryRoot.'/source-'.bin2hex(random_bytes(4)).'.jsonl';
        file_put_contents(
            $path,
            implode("\n", array_map(static fn (array $record): string => GreenfieldBaselineJson::encode($record), $records))."\n",
        );

        return [$path, $projectionSha];
    }

    /** @return array<string, mixed> */
    private function skuRow(): array
    {
        $now = '2026-08-09 00:00:00';

        return [
            'org_id' => 0,
            'sku' => 'greenfield-current',
            'scale_code' => 'MBTI',
            'kind' => 'single',
            'unit_qty' => 1,
            'benefit_code' => 'report',
            'scope' => 'global',
            'price_cents' => 100,
            'currency' => 'CNY',
            'is_active' => 1,
            'meta_json' => '{}',
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function emptyPackageTables(): void
    {
        Schema::disableForeignKeyConstraints();
        try {
            foreach (array_reverse(GreenfieldBaselineCatalog::datasets()) as $definition) {
                $table = (string) $definition['table'];
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
            foreach (GreenfieldBaselineCatalog::forbiddenDatasetNames() as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
