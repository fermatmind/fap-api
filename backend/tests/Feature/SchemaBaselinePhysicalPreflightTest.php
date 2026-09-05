<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\SchemaBaseline;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class SchemaBaselinePhysicalPreflightTest extends TestCase
{
    public function test_physical_preflight_requires_invalidation_after_schema_changes(): void
    {
        $table = 'schema_preflight_'.bin2hex(random_bytes(6));
        try {
            $this->assertFalse(SchemaBaseline::tableExists($table));
            Schema::create($table, static fn (Blueprint $schema) => $schema->id());
            $this->assertFalse(SchemaBaseline::tableExists($table));
            SchemaBaseline::clearCache();
            $this->assertTrue(SchemaBaseline::tableExists($table));
            Schema::drop($table);
            SchemaBaseline::clearCache();
            $this->assertFalse(SchemaBaseline::tableExists($table));
        } finally {
            Schema::dropIfExists($table);
            SchemaBaseline::clearCache();
        }
    }

    public function test_disabled_product_feature_cannot_hide_a_physical_authority_table(): void
    {
        $table = 'schema_preflight_'.bin2hex(random_bytes(6));
        config()->set('fap.schema_baseline.feature_tables.'.$table, 'analytics');
        config()->set('fap.features.analytics', false);
        try {
            Schema::create($table, static fn (Blueprint $schema) => $schema->id());
            $this->assertFalse(SchemaBaseline::hasTable($table));
            $this->assertTrue(SchemaBaseline::tableExists($table));
            $this->assertFalse(SchemaBaseline::hasTable($table));
            config()->set('fap.features.analytics', true);
            $this->assertTrue(SchemaBaseline::hasTable($table));
        } finally {
            Schema::dropIfExists($table);
            SchemaBaseline::clearCache();
        }
    }

    public function test_physical_preflight_never_turns_a_failed_probe_into_a_missing_table(): void
    {
        Schema::shouldReceive('hasTable')->once()->with('unavailable_authority')
            ->andThrow(new RuntimeException('private transport detail'));
        $reason = null;
        $exception = null;
        $this->assertFalse(SchemaBaseline::hasTableWithMeta('unavailable_authority', $reason, $exception));
        $this->assertSame('schema_query_exception', $reason);
        $this->assertSame(RuntimeException::class, $exception);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Schema table availability could not be verified.');
        SchemaBaseline::tableExists('unavailable_authority');
    }

    public function test_named_connection_probes_do_not_reuse_default_connection_results(): void
    {
        $table = 'schema_preflight_'.bin2hex(random_bytes(6));
        config()->set('database.connections.preflight_secondary', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        try {
            $this->assertFalse(SchemaBaseline::tableExists($table));
            Schema::connection('preflight_secondary')->create($table, static fn (Blueprint $schema) => $schema->id());
            $this->assertTrue(SchemaBaseline::tableExists($table, 'preflight_secondary'));
            $this->assertFalse(SchemaBaseline::tableExists($table));
        } finally {
            DB::purge('preflight_secondary');
            SchemaBaseline::clearCache();
        }
    }

    public function test_physical_columns_are_connection_scoped_cached_and_invalidated(): void
    {
        $table = 'schema_preflight_'.bin2hex(random_bytes(6));
        config()->set('database.connections.preflight_secondary', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $secondary = Schema::connection('preflight_secondary');
        try {
            Schema::create($table, static fn (Blueprint $schema) => $schema->string('main_only'));
            $secondary->create($table, static fn (Blueprint $schema) => $schema->string('named_only'));
            $this->assertTrue(SchemaBaseline::columnExists($table, 'main_only'));
            $this->assertFalse(SchemaBaseline::columnExists($table, 'named_only'));
            $this->assertTrue(SchemaBaseline::columnExists($table, 'named_only', 'preflight_secondary'));
            $this->assertFalse(SchemaBaseline::columnExists($table, 'main_only', 'preflight_secondary'));
            $secondary->table($table, static fn (Blueprint $schema) => $schema->string('added')->nullable());
            $this->assertFalse(SchemaBaseline::columnExists($table, 'added', 'preflight_secondary'));
            SchemaBaseline::clearCache();
            $this->assertTrue(SchemaBaseline::columnExists($table, 'added', 'preflight_secondary'));
            $secondary->drop($table);
            $this->assertTrue(SchemaBaseline::columnExists($table, 'named_only', 'preflight_secondary'));
            SchemaBaseline::clearCache();
            $this->assertFalse(SchemaBaseline::columnExists($table, 'named_only', 'preflight_secondary'));
            $this->assertTrue(SchemaBaseline::columnExists($table, 'main_only'));
        } finally {
            Schema::dropIfExists($table);
            DB::purge('preflight_secondary');
            SchemaBaseline::clearCache();
        }
    }

    public function test_physical_column_cache_cannot_bypass_business_feature_gate(): void
    {
        $table = 'schema_preflight_'.bin2hex(random_bytes(6));
        config()->set('fap.schema_baseline.feature_tables.'.$table, 'analytics');
        config()->set('fap.features.analytics', false);
        try {
            Schema::create($table, static fn (Blueprint $schema) => $schema->id());
            $this->assertTrue(SchemaBaseline::columnExists($table, 'id'));
            $this->assertFalse(SchemaBaseline::hasColumn($table, 'id'));
            $reason = null;
            $this->assertFalse(SchemaBaseline::hasColumnWithMeta($table, '', $reason));
            $this->assertSame('invalid_input', $reason);
            config()->set('fap.features.analytics', true);
            $this->assertTrue(SchemaBaseline::hasColumn($table, 'id'));
            config()->set('fap.features.analytics', false);
            $this->assertFalse(SchemaBaseline::hasColumn($table, 'id'));
            $this->assertTrue(SchemaBaseline::columnExists($table, 'id'));
        } finally {
            Schema::dropIfExists($table);
            SchemaBaseline::clearCache();
        }
    }

    public function test_column_listing_failure_remains_closed_on_cache_hit_until_invalidation(): void
    {
        Schema::shouldReceive('hasTable')->twice()->with('unavailable_columns')->andReturn(true);
        Schema::shouldReceive('getColumnListing')->once()->with('unavailable_columns')
            ->andThrow(new RuntimeException('private transport detail'));
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                SchemaBaseline::columnExists('unavailable_columns', 'id');
                $this->fail('Column listing errors must fail closed.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Schema column availability could not be verified.', $exception->getMessage());
            }
        }
        SchemaBaseline::clearCache();
        Schema::shouldReceive('getColumnListing')->once()->with('unavailable_columns')->andReturn(['id']);
        $this->assertTrue(SchemaBaseline::columnExists('unavailable_columns', 'id'));
    }

    public function test_material_backfill_refuses_missing_default_or_named_schema_and_query_failures(): void
    {
        config()->set('seo_intel.connection', 'preflight_secondary');
        config()->set('database.connections.preflight_secondary', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);
        $builder = \Mockery::mock(\Illuminate\Database\Schema\Builder::class);
        Schema::shouldReceive('connection')->with('preflight_secondary')->andReturn($builder);
        Schema::shouldReceive('hasTable')->with('content_material_decisions')->andReturn(false, true, true, true, true);
        $builder->shouldReceive('hasTable')->with('seo_urls')->andReturn(false, true, true, true);
        $builder->shouldReceive('getColumnListing')->with('seo_urls')
            ->andReturn(['id'], ['id', 'page_family'])->andThrow(new RuntimeException('query failed'));
        $service = new \App\Services\SeoIntel\UrlTruth\MaterialAuthorityUrlTruthBackfillService;
        foreach (['default table', 'named table', 'page_family', 'material_fingerprint', 'query exception'] as $case) {
            SchemaBaseline::clearCache();
            $receipt = $service->run(true);
            $this->assertSame('blocked', $receipt['status'], $case);
            $this->assertSame(['material_url_truth_schema_unavailable'], $receipt['issues'], $case);
            $this->assertFalse($receipt['writes_committed'], $case);
        }
        DB::purge('preflight_secondary');
    }
}
