<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\BigFivePackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BigFiveQuestionsCompiledDeterminismTest extends TestCase
{
    use RefreshDatabase;

    public function test_questions_compiled_payloads_are_stable_when_raw_is_unchanged(): void
    {
        /** @var BigFivePackLoader $loader */
        $loader = app(BigFivePackLoader::class);

        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $fullA = $loader->readCompiledJson('questions.compiled.json', 'v1');
        $minA = $loader->readCompiledJson('questions.min.compiled.json', 'v1');

        $this->artisan('content:compile --pack=BIG5_OCEAN --pack-version=v1')->assertExitCode(0);
        $fullB = $loader->readCompiledJson('questions.compiled.json', 'v1');
        $minB = $loader->readCompiledJson('questions.min.compiled.json', 'v1');

        $this->assertIsArray($fullA);
        $this->assertIsArray($fullB);
        $this->assertIsArray($minA);
        $this->assertIsArray($minB);

        $hashFullA = $this->stableHash($this->stripTimestamps($fullA));
        $hashFullB = $this->stableHash($this->stripTimestamps($fullB));
        $hashMinA = $this->stableHash($this->stripTimestamps($minA));
        $hashMinB = $this->stableHash($this->stripTimestamps($minB));

        $this->assertSame($hashFullA, $hashFullB);
        $this->assertSame($hashMinA, $hashMinB);
    }

    /**
     * @param array<string,mixed>|list<mixed> $node
     * @return array<string,mixed>|list<mixed>
     */
    private function stripTimestamps(array $node): array
    {
        if (array_is_list($node)) {
            $out = [];
            foreach ($node as $item) {
                if (is_array($item)) {
                    $out[] = $this->stripTimestamps($item);
                } else {
                    $out[] = $item;
                }
            }

            return $out;
        }

        $out = [];
        foreach ($node as $key => $value) {
            if (in_array((string) $key, ['generated_at', 'compiled_at'], true)) {
                continue;
            }
            $out[(string) $key] = is_array($value) ? $this->stripTimestamps($value) : $value;
        }
        ksort($out);

        return $out;
    }

    /**
     * @param array<string,mixed>|list<mixed> $node
     */
    private function stableHash(array $node): string
    {
        $json = json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($json);

        return hash('sha256', $json);
    }
}
