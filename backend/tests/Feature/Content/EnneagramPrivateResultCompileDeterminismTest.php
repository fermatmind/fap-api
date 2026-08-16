<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\EnneagramPrivateResultCompileService;
use Tests\TestCase;

final class EnneagramPrivateResultCompileDeterminismTest extends TestCase
{
    public function test_compile_is_byte_identical_and_hash_bound_to_both_locales(): void
    {
        $compiler = app(EnneagramPrivateResultCompileService::class);
        $first = $compiler->compile();
        $second = $compiler->compile();

        $this->assertSame($first['bytes'], $second['bytes']);
        $this->assertSame($first['manifest_bytes'], $second['manifest_bytes']);
        $this->assertSame($first['english_manifest_bytes'], $second['english_manifest_bytes']);
        $this->assertSame($first['source_hash'], $second['source_hash']);
        $this->assertSame($first['compiled_hash'], $second['compiled_hash']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first['source_hash']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $first['compiled_hash']);
        $this->assertCount(24, array_merge($first['manifest']['locale_source_files']['zh-CN'], $first['manifest']['locale_source_files']['en']));
        $this->assertSame($first['source_hash'], $first['payload']['form_projections']['e105']['source_hash']);
        $this->assertSame($first['source_hash'], $first['payload']['form_projections']['fc144']['source_hash']);
        $this->assertSame(36, $first['manifest']['coverage']['pair_count']);
        $this->assertSame($first['bytes'], file_get_contents(base_path('content_packs/ENNEAGRAM/v2/compiled/private_result.compiled.json')));
    }
}
