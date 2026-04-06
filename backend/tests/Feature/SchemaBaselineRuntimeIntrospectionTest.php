<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\SchemaBaseline;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SchemaBaselineRuntimeIntrospectionTest extends TestCase
{
    use RefreshDatabase;

    private string $tableName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tableName = 'schema_baseline_rt_'.strtolower(Str::random(8));
        SchemaBaseline::clearCache();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists($this->tableName);
        SchemaBaseline::clearCache();

        parent::tearDown();
    }

    public function test_has_table_and_column_reflect_runtime_schema_truth(): void
    {
        $tableReason = null;
        $tableException = null;
        $columnReason = null;
        $columnException = null;
        $this->assertFalse(SchemaBaseline::hasTable($this->tableName));
        $this->assertFalse(SchemaBaseline::hasTableWithMeta($this->tableName, $tableReason, $tableException));
        $this->assertSame('table_missing', $tableReason);
        $this->assertNull($tableException);
        $this->assertFalse(SchemaBaseline::hasColumn($this->tableName, 'alpha'));
        $this->assertFalse(SchemaBaseline::hasColumnWithMeta($this->tableName, 'alpha', $columnReason, $columnException));
        $this->assertSame('table_missing', $columnReason);
        $this->assertNull($columnException);

        Schema::create($this->tableName, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('alpha')->nullable();
        });

        SchemaBaseline::clearCache();

        $this->assertTrue(SchemaBaseline::hasTable($this->tableName));
        $this->assertTrue(SchemaBaseline::hasTableWithMeta($this->tableName, $tableReason, $tableException));
        $this->assertSame('exists', $tableReason);
        $this->assertNull($tableException);
        $this->assertTrue(SchemaBaseline::hasColumn($this->tableName, 'alpha'));
        $this->assertTrue(SchemaBaseline::hasColumnWithMeta($this->tableName, 'alpha', $columnReason, $columnException));
        $this->assertSame('exists', $columnReason);
        $this->assertNull($columnException);
        $this->assertFalse(SchemaBaseline::hasColumn($this->tableName, 'beta'));
        $this->assertFalse(SchemaBaseline::hasColumnWithMeta($this->tableName, 'beta', $columnReason, $columnException));
        $this->assertSame('column_missing', $columnReason);
        $this->assertNull($columnException);

        Schema::dropIfExists($this->tableName);
        SchemaBaseline::clearCache();

        $this->assertFalse(SchemaBaseline::hasTable($this->tableName));
        $this->assertFalse(SchemaBaseline::hasColumn($this->tableName, 'alpha'));
    }

    public function test_recreated_table_schema_is_reflected_after_cache_clear(): void
    {
        Schema::create($this->tableName, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('legacy_col')->nullable();
        });

        SchemaBaseline::clearCache();
        $this->assertTrue(SchemaBaseline::hasColumn($this->tableName, 'legacy_col'));
        $this->assertFalse(SchemaBaseline::hasColumn($this->tableName, 'new_col'));

        Schema::dropIfExists($this->tableName);
        Schema::create($this->tableName, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('new_col')->nullable();
        });

        SchemaBaseline::clearCache();

        $this->assertFalse(SchemaBaseline::hasColumn($this->tableName, 'legacy_col'));
        $this->assertTrue(SchemaBaseline::hasColumn($this->tableName, 'new_col'));
    }

    public function test_feature_gate_still_applies_before_runtime_schema_truth(): void
    {
        $tableReason = null;
        $tableException = null;
        config()->set('fap.features.analytics', false);
        SchemaBaseline::clearCache();
        $this->assertFalse(SchemaBaseline::hasTable('events'));
        $this->assertFalse(SchemaBaseline::hasTableWithMeta('events', $tableReason, $tableException));
        $this->assertSame('feature_disabled', $tableReason);
        $this->assertNull($tableException);

        config()->set('fap.features.analytics', true);
        SchemaBaseline::clearCache();

        $this->assertTrue(SchemaBaseline::hasTable('events'));
        $this->assertTrue(SchemaBaseline::hasColumn('events', 'event_code'));
    }
}
