<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\BigFivePackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BigFiveQuestionsMinCompiledEvidenceContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_questions_min_compiled_contains_valid_and_reproducible_content_evidence_hashes(): void
    {
        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);

        /** @var BigFivePackLoader $loader */
        $loader = app(BigFivePackLoader::class);
        $doc = $loader->readCompiledJson('questions.min.compiled.json', 'v1');

        $this->assertIsArray($doc);
        $evidence = is_array($doc['content_evidence'] ?? null) ? $doc['content_evidence'] : [];
        $this->assertNotEmpty($evidence);

        $targets = [
            'question_index_sha256' => $doc['question_index'] ?? [],
            'texts_by_locale_sha256' => $doc['texts_by_locale'] ?? [],
            'option_sets_sha256' => $doc['option_sets'] ?? [],
            'question_option_set_ref_sha256' => $doc['question_option_set_ref'] ?? [],
        ];

        foreach ($targets as $hashKey => $node) {
            $actual = trim((string) ($evidence[$hashKey] ?? ''));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $actual, $hashKey.' must be lowercase sha256 hex');
            $this->assertSame($this->stableHash($node), $actual, $hashKey.' mismatch');
        }
    }

    private function stableHash(mixed $node): string
    {
        $normalized = $this->normalize($node);
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);

        return hash('sha256', $encoded);
    }

    private function normalize(mixed $node): mixed
    {
        if (is_array($node)) {
            if (array_is_list($node)) {
                return array_map(fn (mixed $item): mixed => $this->normalize($item), $node);
            }

            ksort($node);
            foreach ($node as $key => $value) {
                $node[$key] = $this->normalize($value);
            }

            return $node;
        }

        if (is_bool($node) || is_int($node) || is_float($node)) {
            return (string) $node;
        }

        return trim((string) $node);
    }
}

