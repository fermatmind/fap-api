<?php

declare(strict_types=1);

namespace Tests\Feature\Storage;

use Tests\TestCase;

final class ContentReleaseManifestSchemaVersionLengthTest extends TestCase
{
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
