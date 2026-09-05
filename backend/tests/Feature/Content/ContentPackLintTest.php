<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\ContentCompileService;
use App\Services\Content\ContentLintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentPackLintTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_pack_lint_and_compile_commands_pass(): void
    {
        $this->artisan('content:lint --pack=MBTI.cn-mainland.zh-CN.v0.3')->assertExitCode(0);
        $this->artisan('content:compile --pack=MBTI.cn-mainland.zh-CN.v0.3')->assertExitCode(0);

        $compiledDir = dirname(base_path()).'/content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/compiled';
        $this->assertFileExists($compiledDir.'/cards.normalized.json');
        $this->assertFileExists($compiledDir.'/cards.tag_index.json');
        $this->assertFileExists($compiledDir.'/rules.normalized.json');
        $this->assertFileExists($compiledDir.'/sections.spec.json');
        $this->assertFileExists($compiledDir.'/variables.used.json');
        $this->assertFileExists($compiledDir.'/inventory.spec.json');
        $this->assertFileExists($compiledDir.'/governance.spec.json');
        $this->assertFileExists($compiledDir.'/manifest.json');
    }

    public function test_pack_scoped_lint_targets_only_the_canonical_governed_mbti_forms(): void
    {
        $result = $this->app->make(ContentLintService::class)->lintAll('MBTI.cn-mainland.zh-CN.v0.3');

        $packs = is_array($result['packs'] ?? null) ? $result['packs'] : [];
        $this->assertCount(2, $packs);
        $baseDirs = array_map(static fn (array $pack): string => (string) ($pack['base_dir'] ?? ''), $packs);
        $this->assertEqualsCanonicalizing([
            dirname(base_path()).'/content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3',
            dirname(base_path()).'/content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3-form-93',
        ], $baseDirs);
    }

    public function test_pack_scoped_compile_targets_only_the_canonical_governed_mbti_forms(): void
    {
        $result = $this->app->make(ContentCompileService::class)->compileAll('MBTI.cn-mainland.zh-CN.v0.3');

        $packs = is_array($result['packs'] ?? null) ? $result['packs'] : [];
        $this->assertCount(2, $packs);
        $compiledDirs = array_map(static fn (array $pack): string => (string) ($pack['compiled_dir'] ?? ''), $packs);
        $this->assertEqualsCanonicalizing([
            dirname(base_path()).'/content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/compiled',
            dirname(base_path()).'/content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3-form-93/compiled',
        ], $compiledDirs);
    }
}
