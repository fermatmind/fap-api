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
}
