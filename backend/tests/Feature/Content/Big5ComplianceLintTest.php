<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\BigFivePackLoader;
use Tests\TestCase;

final class Big5ComplianceLintTest extends TestCase
{
    public function test_assessment_compile_excludes_retired_private_result_assets(): void
    {
        $this->artisan('content:lint --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        $loader = app(BigFivePackLoader::class);
        $manifest = $loader->readCompiledJson('manifest.json', 'v1');
        $this->assertIsArray($manifest);
        $this->assertSame([
            'questions.compiled.json',
            'questions.min.compiled.json',
            'facet_index.json',
            'domain_index.json',
            'norms.compiled.json',
            'policy.compiled.json',
        ], $manifest['compiled_files'] ?? null);

        foreach (['blocks', 'copy', 'golden_cases', 'landing', 'layout', 'legal'] as $retiredAsset) {
            $this->assertFileDoesNotExist($loader->compiledPath($retiredAsset.'.compiled.json', 'v1'));
        }
    }
}
