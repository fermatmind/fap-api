<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContentPackLintTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_pack_lint_and_compile_commands_pass(): void
    {
        $this->artisan('content:lint --all')->assertExitCode(0);
        $this->artisan('content:compile --all')->assertExitCode(0);

        $compiledDir = base_path('../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.3/compiled');
        $this->assertFileExists($compiledDir . '/cards.normalized.json');
        $this->assertFileExists($compiledDir . '/cards.tag_index.json');
        $this->assertFileExists($compiledDir . '/rules.normalized.json');
        $this->assertFileExists($compiledDir . '/sections.spec.json');
        $this->assertFileExists($compiledDir . '/variables.used.json');
        $this->assertFileExists($compiledDir . '/manifest.json');
    }
}
