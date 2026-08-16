<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\RiasecPrivateResultCompileService;
use RuntimeException;
use Tests\TestCase;

final class RiasecPrivateResultCompileDeterminismTest extends TestCase
{
    public function test_compile_is_byte_identical_and_hash_bound_to_whitelisted_sources(): void
    {
        $compiler = app(RiasecPrivateResultCompileService::class);
        $first = $compiler->compile();
        $second = $compiler->compile();

        $this->assertSame($first['bytes'], $second['bytes']);
        $this->assertSame($first['manifest_bytes'], $second['manifest_bytes']);
        $this->assertSame($first['source_hash'], $second['source_hash']);
        $this->assertSame($first['compiled_hash'], $second['compiled_hash']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first['source_hash']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first['compiled_hash']);
        $this->assertSame(array_keys(RiasecPrivateResultCompileService::SOURCE_CONTRACT), array_column($first['manifest']['source_files'], 'path'));
        $this->assertSame(['riasec_60', 'riasec_140'], $first['manifest']['coverage']['forms']);
        $this->assertSame(15, $first['manifest']['coverage']['pair_count']);
        $this->assertSame($first['manifest_bytes'], file_get_contents(base_path('content_assets/riasec/compiled/manifest.json')));
        $this->assertSame($first['bytes'], file_get_contents(base_path('content_assets/riasec/compiled/private_result.compiled.json')));
    }

    public function test_compile_fails_closed_on_source_schema_mismatch(): void
    {
        $root = sys_get_temp_dir().'/riasec-canonical-'.bin2hex(random_bytes(6));
        mkdir($root, 0775, true);
        file_put_contents($root.'/140q_task_environment_role_v1.zh-CN.jsonl', json_encode([
            'schema_version' => 'wrong.schema',
            'frontend_fallback_allowed' => false,
        ], JSON_THROW_ON_ERROR)."\n");

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('schema mismatch');
            (new RiasecPrivateResultCompileService($root))->compile();
        } finally {
            @unlink($root.'/140q_task_environment_role_v1.zh-CN.jsonl');
            @rmdir($root);
        }
    }
}
