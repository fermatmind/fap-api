<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\Eq60ContentCompileService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class Eq60CompileDeterminismTest extends TestCase
{
    public function test_two_clean_candidate_compiles_are_byte_for_byte_identical(): void
    {
        $root = sys_get_temp_dir().'/eq60-determinism-'.bin2hex(random_bytes(6));
        $first = $root.'/first';
        $second = $root.'/second';

        try {
            $compiler = app(Eq60ContentCompileService::class);
            $firstResult = $compiler->compile('v1', $first);
            $secondResult = $compiler->compile('v1', $second);
            $this->assertTrue((bool) ($firstResult['ok'] ?? false), json_encode($firstResult['errors'] ?? []));
            $this->assertTrue((bool) ($secondResult['ok'] ?? false), json_encode($secondResult['errors'] ?? []));
            $this->assertSame($firstResult['source_hash'] ?? null, $secondResult['source_hash'] ?? null);
            $this->assertSame($firstResult['compiled_hash'] ?? null, $secondResult['compiled_hash'] ?? null);
            $this->assertSame($this->snapshot($first), $this->snapshot($second));

            $before = $this->snapshot($first);
            $thirdResult = $compiler->compile('v1', $first);
            $this->assertTrue((bool) ($thirdResult['ok'] ?? false), json_encode($thirdResult['errors'] ?? []));
            $this->assertSame($before, $this->snapshot($first));

            $manifestBytes = (string) file_get_contents($first.'/manifest.json');
            $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('eq_60.compiled.manifest.v2', $manifest['schema'] ?? null);
            $this->assertSame($firstResult['source_hash'] ?? null, $manifest['source_hash'] ?? null);
            $this->assertSame($firstResult['compiled_hash'] ?? null, $manifest['compiled_hash'] ?? null);
            $this->assertCount(8, (array) ($manifest['compiled_inventory'] ?? []));
            $this->assertStringNotContainsString('generated_at', $manifestBytes);
            $this->assertStringNotContainsString('compiled_at', $manifestBytes);

            $assets = json_decode((string) file_get_contents($first.'/report_assets.compiled.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(
                '作答速度过快，建议在注意力充足时重新作答。',
                data_get($assets, 'assets.quality_confidence.flag_explanations.SPEEDING.zh-CN'),
            );
            $this->assertSame(
                'Responses were completed unusually quickly; retake when you have enough time and attention.',
                data_get($assets, 'assets.quality_confidence.flag_explanations.SPEEDING.en'),
            );
            $this->assertSame(
                '我会基于当前报告资产解释你的 EQ 结果，不会重新打分或替换报告判断。',
                data_get($assets, 'assets.agent_dialogue_playbooks.runtime_copy.default_response.zh-CN'),
            );
            $this->assertSame(
                'Which real-life scene would you like to discuss first?',
                data_get($assets, 'assets.agent_dialogue_playbooks.runtime_copy.default_follow_up.en'),
            );
        } finally {
            File::deleteDirectory($root);
        }
    }

    /** @return array<string,string> */
    private function snapshot(string $directory): array
    {
        $snapshot = [];
        foreach (File::files($directory) as $file) {
            $snapshot[$file->getFilename()] = hash_file('sha256', $file->getPathname());
        }
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }
}
