<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Career\Display;

use App\Support\SchemaBaseline;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class CareerDisplayAssetCanonicalSlugUniqueMigrationTest extends TestCase
{
    private bool $createdOccupations = false;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('career_job_display_assets');
        RefreshDatabaseState::$migrated = false;
        SchemaBaseline::clearCache();
    }

    public function test_fresh_schema_keeps_legacy_columns_and_composite_index_while_adding_slug_unique(): void
    {
        if (! Schema::hasTable('occupations')) {
            Schema::create('occupations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
            });
            $this->createdOccupations = true;
        }
        $this->createMigration()->up();
        $migration = $this->expandMigration();
        $migration->up();

        $indexes = collect(Schema::getIndexes('career_job_display_assets'))->keyBy('name');
        self::assertTrue($indexes->has('career_job_display_assets_canonical_slug_unique'));
        self::assertTrue($indexes->has('career_job_display_assets_slug_version_unique'));
        self::assertTrue(Schema::hasColumns('career_job_display_assets', ['asset_version', 'template_version']));

        $migration->down();
        $indexesAfterDown = collect(Schema::getIndexes('career_job_display_assets'))->keyBy('name');
        self::assertTrue($indexesAfterDown->has('career_job_display_assets_canonical_slug_unique'));
        self::assertTrue($indexesAfterDown->has('career_job_display_assets_slug_version_unique'));
    }

    public function test_existing_schema_upgrade_enforces_unique_slug_and_fails_closed_on_duplicates(): void
    {
        $this->createMinimalLegacyTable();
        DB::table('career_job_display_assets')->insert([
            ['id' => 'one', 'canonical_slug' => 'accountants-and-auditors', 'asset_version' => 'v4.2'],
            ['id' => 'two', 'canonical_slug' => 'registered-nurses', 'asset_version' => 'v4.3'],
        ]);
        $this->expandMigration()->up();

        try {
            DB::table('career_job_display_assets')->insert([
                'id' => 'three',
                'canonical_slug' => 'accountants-and-auditors',
                'asset_version' => 'v4.3',
            ]);
            self::fail('Expected unique canonical_slug rejection.');
        } catch (QueryException) {
            self::assertDatabaseCount('career_job_display_assets', 2);
        }

        Schema::drop('career_job_display_assets');
        $this->createMinimalLegacyTable();
        DB::table('career_job_display_assets')->insert([
            ['id' => 'one', 'canonical_slug' => 'duplicate-slug', 'asset_version' => 'v4.2'],
            ['id' => 'two', 'canonical_slug' => 'duplicate-slug', 'asset_version' => 'v4.3'],
        ]);
        try {
            $this->expandMigration()->up();
            self::fail('Expected duplicate audit failure.');
        } catch (RuntimeException $failure) {
            self::assertSame('CAREER_DISPLAY_CANONICAL_SLUG_DUPLICATE', $failure->getMessage());
            self::assertFalse(collect(Schema::getIndexes('career_job_display_assets'))
                ->contains(static fn (array $index): bool => ($index['name'] ?? null) === 'career_job_display_assets_canonical_slug_unique'));
        }
    }

    protected function tearDown(): void
    {
        try {
            Schema::dropIfExists('career_job_display_assets');
            if ($this->createdOccupations) {
                Schema::dropIfExists('occupations');
            }
            RefreshDatabaseState::$migrated = false;
            SchemaBaseline::clearCache();
        } finally {
            parent::tearDown();
        }
    }

    private function createMinimalLegacyTable(): void
    {
        Schema::create('career_job_display_assets', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('canonical_slug');
            $table->string('asset_version');
            $table->unique(
                ['canonical_slug', 'asset_version'],
                'career_job_display_assets_slug_version_unique',
            );
        });
    }

    private function createMigration(): object
    {
        return require database_path('migrations/2026_05_02_000100_create_career_job_display_assets_table.php');
    }

    private function expandMigration(): object
    {
        return require database_path('migrations/2026_08_25_120000_expand_career_display_asset_canonical_slug_unique.php');
    }
}
