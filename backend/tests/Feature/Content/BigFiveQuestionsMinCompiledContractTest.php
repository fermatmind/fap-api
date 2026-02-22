<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\BigFivePackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BigFiveQuestionsMinCompiledContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_questions_min_compiled_contract_has_120_coverage_for_both_locales(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        /** @var BigFivePackLoader $loader */
        $loader = app(BigFivePackLoader::class);
        $doc = $loader->readCompiledJson('questions.min.compiled.json', 'v1');

        $this->assertIsArray($doc);
        $this->assertSame('big5.questions.min.compiled.v1', (string) ($doc['schema'] ?? ''));

        $questionIndex = is_array($doc['question_index'] ?? null) ? $doc['question_index'] : [];
        $this->assertCount(120, $questionIndex);

        $textsByLocale = is_array($doc['texts_by_locale'] ?? null) ? $doc['texts_by_locale'] : [];
        $zh = is_array($textsByLocale['zh-CN'] ?? null) ? $textsByLocale['zh-CN'] : [];
        $en = is_array($textsByLocale['en'] ?? null) ? $textsByLocale['en'] : [];
        $this->assertCount(120, $zh);
        $this->assertCount(120, $en);

        foreach ($zh as $text) {
            $this->assertNotSame('', trim((string) $text));
        }
        foreach ($en as $text) {
            $this->assertNotSame('', trim((string) $text));
        }

        $optionSets = is_array($doc['option_sets'] ?? null) ? $doc['option_sets'] : [];
        $likert = is_array($optionSets['LIKERT5'] ?? null) ? $optionSets['LIKERT5'] : [];
        $this->assertCount(5, $likert);

        $refs = is_array($doc['question_option_set_ref'] ?? null) ? $doc['question_option_set_ref'] : [];
        $this->assertCount(120, $refs);
        foreach ($refs as $setId) {
            $this->assertSame('LIKERT5', (string) $setId);
        }
    }
}
