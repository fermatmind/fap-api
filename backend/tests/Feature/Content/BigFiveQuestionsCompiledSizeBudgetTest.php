<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\BigFivePackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BigFiveQuestionsCompiledSizeBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_questions_min_compiled_file_is_significantly_smaller_than_full_questions_payload(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        /** @var BigFivePackLoader $loader */
        $loader = app(BigFivePackLoader::class);

        $fullPath = $loader->compiledPath('questions.compiled.json', 'v1');
        $minPath = $loader->compiledPath('questions.min.compiled.json', 'v1');

        $this->assertFileExists($fullPath);
        $this->assertFileExists($minPath);

        $fullSize = filesize($fullPath);
        $minSize = filesize($minPath);
        $this->assertIsInt($fullSize);
        $this->assertIsInt($minSize);
        $this->assertGreaterThan(0, $fullSize);
        $this->assertGreaterThan(0, $minSize);

        $ratio = $fullSize > 0 ? ((float) $minSize / (float) $fullSize) : 1.0;
        $maxRatio = (float) config('big5_content_budget.questions.min_compiled_max_ratio', 0.60);
        $maxBytes = (int) config('big5_content_budget.questions.min_compiled_max_bytes', 300000);

        $this->assertLessThanOrEqual($maxRatio, $ratio, sprintf(
            'questions.min.compiled.json ratio %.4f exceeds budget %.4f (min=%d full=%d)',
            $ratio,
            $maxRatio,
            $minSize,
            $fullSize
        ));
        $this->assertGreaterThan($minSize, $fullSize);
        $this->assertLessThanOrEqual($maxBytes, $minSize, sprintf(
            'questions.min.compiled.json size %d exceeds max bytes budget %d',
            $minSize,
            $maxBytes
        ));
    }
}
