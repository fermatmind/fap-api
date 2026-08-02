<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use App\Services\BigFive\ResultPageV2\Production\BigFiveResultPageV2ProductionImportExecutor;
use App\Services\Riasec\RiasecResultPageV2ProductionImportExecutor;
use App\Services\Storage\ContentReleaseManifestCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ContentReleaseManifestSchemaVersionLengthTest extends TestCase
{
    use RefreshDatabase;

    private const MAX_SCHEMA_VERSION_LENGTH = 128;

    public function test_production_import_schema_versions_fit_the_manifest_column_contract(): void
    {
        $schemaVersions = [
            RiasecResultPageV2ProductionImportExecutor::RELEASE_SCHEMA_VERSION,
            BigFiveResultPageV2ProductionImportExecutor::RELEASE_SCHEMA,
        ];

        foreach ($schemaVersions as $index => $schemaVersion) {
            $this->assertLessThanOrEqual(self::MAX_SCHEMA_VERSION_LENGTH, strlen($schemaVersion));

            app(ContentReleaseManifestCatalogService::class)->upsertManifest([
                'manifest_hash' => hash('sha256', 'schema-version-'.$index),
                'schema_version' => $schemaVersion,
                'storage_disk' => 'local',
                'storage_path' => 'private/testing/schema-version-'.$index,
                'pack_id' => $index === 0 ? 'RIASEC' : 'BIG5_OCEAN',
                'pack_version' => 'result_page_v2',
                'payload_json' => ['schema_version' => $schemaVersion],
            ]);
        }

        $this->assertSame(
            $schemaVersions,
            DB::table('content_release_manifests')->orderBy('id')->pluck('schema_version')->all(),
        );
    }

    public function test_forward_only_migration_widens_the_column_to_the_contract_length(): void
    {
        $migration = file_get_contents(database_path(
            'migrations/2026_08_02_170000_widen_content_release_manifest_schema_version.php'
        ));

        $this->assertIsString($migration);
        $this->assertStringContainsString('private const SCHEMA_VERSION_LENGTH = 128;', $migration);
        $this->assertStringContainsString('MODIFY COLUMN `schema_version` VARCHAR(%d)', $migration);
        $this->assertStringNotContainsString('dropColumn', $migration);
        $this->assertStringNotContainsString('->change()', $migration);
    }
}
