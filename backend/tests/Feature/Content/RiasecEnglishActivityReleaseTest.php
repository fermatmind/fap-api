<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Services\Content\RiasecPrivateResultCompileService as Compiler;
use App\Services\Content\RiasecPrivateResultPackLoader as Loader;
use App\Services\Riasec\RiasecActivityExplorerService;
use App\Services\Riasec\RiasecPrivateResultSourceRepository;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class RiasecEnglishActivityReleaseTest extends TestCase
{
    public function test_compiled_english_is_module_scoped_and_never_falls_back_across_locales(): void
    {
        $compiled = app(Compiler::class)->compile();
        $loader = app(Loader::class);
        $loader->assertPackage($compiled['manifest'], $compiled['payload'], $compiled['bytes']);
        $english = $loader->load('en-US');
        $chinese = $loader->load('zh-CN');
        $this->assertCount(9, $english['assets']);
        $this->assertCount(19, $chinese['assets']);
        $this->assertSame('FERMATMIND_RIASEC_PRIVATE_RESULT_EN_SELECTED', $english['authority']['authority_id']);
        $this->assertSame('riasec.public_projection.v2', $english['authority']['runtime_contract']);
        $this->assertSame(['en' => ['activity_explorer_v0_1', 'deep_content_slots_v1', 'lifecycle_copy_v1']], $compiled['payload']['coverage']['locale_modules']);
        $repository = new RiasecPrivateResultSourceRepository($loader);
        $service = new RiasecActivityExplorerService(privateResultSource: $repository);
        $rows = $english['assets'][Compiler::ENGLISH_ACTIVITY_ASSET];
        foreach (['R', 'I', 'A', 'S', 'E', 'C'] as $a) {
            foreach (['R', 'I', 'A', 'S', 'E', 'C'] as $b) {
                foreach (['R', 'I', 'A', 'S', 'E', 'C'] as $c) {
                    if (count(array_unique([$a, $b, $c])) !== 3) {
                        continue;
                    }
                    $payload = $service->build($a.$b.$c, 'en');
                    $activities = $payload['code_activity_pack']['activities'];
                    $this->assertCount(9, $activities);
                    $expected = array_merge(...array_map(fn ($code) => array_values(array_filter($rows, fn ($row) => $row['dimensions'] === [$code])), [$a, $b, $c]));
                    $this->assertSame(array_column($expected, 'activity_key'), array_column($activities, 'activity_key'));
                    foreach ($activities as $index => $activity) {
                        $this->assertSame($expected[$index]['activity_label'], $activity['activity_label']);
                        $this->assertSame($expected[$index]['task_examples'], $activity['task_examples']);
                        $this->assertSame($expected[$index]['action_duration_options'], $activity['action_duration_options']);
                        $this->assertSame($expected[$index]['low_risk_validation'], $activity['activity_user_copy']);
                        $this->assertSame([], $activity['occupation_examples']);
                        $this->assertFalse($activity['fit_score_allowed']);
                        $this->assertSame('activity_task_examples_v1.en', $activity['content_version']);
                    }
                    $this->assertSame([], $payload['dimension_activity_families']);
                    $this->assertDoesNotMatchRegularExpression('/[\x{3400}-\x{9fff}]/u', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                }
            }
        }
        $this->assertSame('activity_task_examples_v1.zh-CN', $service->build('RIA', 'zh-CN')['content_version']);
        $this->assertSame('activity_task_examples_v1.en', $service->build('RIA', 'en')['content_version']);
        $this->expectException(RuntimeException::class);
        $repository->asset('runtime_surface_copy_v1.zh-CN.json', 'en');
    }

    public function test_original_chinese_release_still_validates_and_english_remains_unavailable(): void
    {
        $compiled = app(Compiler::class)->compile();
        $payload = $compiled['payload'];
        $manifest = $compiled['manifest'];
        $payload['assets'] = array_intersect_key($payload['assets'], Compiler::sourceContract('1.0.0'));
        unset($payload['coverage']['locale_modules'], $payload['coverage']['english_selected_sources']);
        $payload['compiler']['version'] = '1.0.0';
        $manifest['compiler'] = $payload['compiler'];
        $manifest['coverage'] = $payload['coverage'];
        $manifest['source_files'] = array_values(array_filter($manifest['source_files'], fn ($row) => isset(Compiler::sourceContract('1.0.0')[$row['path']])));
        $sourceHash = hash('sha256', implode('', array_map(fn ($row) => $row['path']."\0".$row['sha256']."\n", $manifest['source_files'])));
        $payload['source_hash'] = $manifest['source_hash'] = $manifest['content_hash'] = $sourceHash;
        unset($payload['compiled_hash']);
        $payload['compiled_hash'] = hash('sha256', $this->canonical($payload));
        $manifest['compiled_hash'] = $payload['compiled_hash'];
        $bytes = $this->canonical($payload)."\n";
        $manifest['artifacts'][0]['sha256'] = hash('sha256', $bytes);
        $this->assertSame('f222c798dd7d12c43bb003d2957e9bfb44a533bf485c2850cb0fc2be138cd3c7', $sourceHash);
        $this->assertSame('8f3b73a9d6dbf59bcb5feeb77bbcdeaf35cdfb7d5c3c629341148b9299cee62a', $payload['compiled_hash']);
        $loader = app(Loader::class);
        $loader->assertPackage($manifest, $payload, $bytes);
        $this->assertSame($payload['assets'], Loader::localeProjection($payload, 'zh-CN')['assets']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('RIASEC_PRIVATE_RESULT_LOCALE_UNAVAILABLE');
        Loader::localeProjection($payload, 'en');
    }

    public function test_activity_only_immutable_release_retains_its_exact_hashes_and_scope(): void
    {
        $compiled = app(Compiler::class)->compile();
        $payload = $compiled['payload'];
        $manifest = $compiled['manifest'];
        $contract = Compiler::sourceContract('1.1.0');
        $payload['assets'] = array_intersect_key($payload['assets'], $contract);
        unset($payload['coverage']['english_selected_sources']);
        $payload['coverage']['locale_modules']['en'] = ['activity_explorer_v0_1'];
        $payload['compiler']['version'] = '1.1.0';
        $manifest['compiler'] = $payload['compiler'];
        $manifest['coverage'] = $payload['coverage'];
        $manifest['source_files'] = array_values(array_filter($manifest['source_files'], fn ($row) => isset($contract[$row['path']])));
        $sourceHash = hash('sha256', implode('', array_map(fn ($row) => $row['path']."\0".$row['sha256']."\n", $manifest['source_files'])));
        $payload['source_hash'] = $manifest['source_hash'] = $manifest['content_hash'] = $sourceHash;
        unset($payload['compiled_hash']);
        $payload['compiled_hash'] = hash('sha256', $this->canonical($payload));
        $manifest['compiled_hash'] = $payload['compiled_hash'];
        $bytes = $this->canonical($payload)."\n";
        $manifest['artifacts'][0]['sha256'] = hash('sha256', $bytes);
        $this->assertSame('fee5193dc0434d4cb420b7a72cfe65ff3a0030ed24d8e671846a62a7b865aa1a', $sourceHash);
        $this->assertSame('1ea12b9301ef242da0531a72b49ecdc729f6b94b6fe419d0abc0debd733c2bf1', $payload['compiled_hash']);
        app(Loader::class)->assertPackage($manifest, $payload, $bytes);
        $english = Loader::localeProjection($payload, 'en');
        $this->assertSame([Compiler::ENGLISH_ACTIVITY_ASSET], array_keys($english['assets']));
        $this->assertSame(['activity_explorer_v0_1'], $english['authority']['modules']);
    }

    public function test_each_missing_selected_activity_empties_the_entire_code_pack(): void
    {
        $rows = app(Compiler::class)->compile()['payload']['assets'][Compiler::ENGLISH_ACTIVITY_ASSET];
        $filename = sys_get_temp_dir().'/riasec-incomplete-'.bin2hex(random_bytes(6)).'.jsonl';
        try {
            foreach ($rows as $index => $row) {
                $remaining = $rows;
                unset($remaining[$index]);
                File::put($filename, implode("\n", array_map(fn ($item) => json_encode($item, JSON_THROW_ON_ERROR), $remaining))."\n");
                $payload = (new RiasecActivityExplorerService($filename))->build($row['dimensions'][0].'IA', 'en');
                $this->assertSame([], $payload['code_activity_pack']['activities']);
            }
        } finally {
            File::delete($filename);
        }
    }

    public function test_missing_wrong_locale_and_duplicate_english_sources_fail_compilation(): void
    {
        $directory = sys_get_temp_dir().'/riasec-en-source-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($directory);
        foreach (array_keys(Compiler::SOURCE_CONTRACT) as $name) {
            File::copy(base_path('content_assets/riasec/'.$name), $directory.'/'.$name);
        }
        $filename = $directory.'/'.Compiler::ENGLISH_ACTIVITY_ASSET;
        $original = File::get($filename);
        $rows = array_map(fn ($line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR), explode("\n", trim($original)));
        try {
            foreach (['missing', 'locale', 'duplicate', 'copy'] as $case) {
                $mutated = $rows;
                if ($case === 'missing') {
                    array_pop($mutated);
                } elseif ($case === 'locale') {
                    $mutated[0]['locale'] = 'zh-CN';
                } elseif ($case === 'duplicate') {
                    $mutated[1] = $mutated[0];
                } else {
                    $mutated[0]['task_examples'][0] = '';
                }
                File::put($filename, implode("\n", array_map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR), $mutated))."\n");
                try {
                    (new Compiler($directory))->compile();
                    $this->fail('Invalid English source accepted: '.$case);
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('English activity', $exception->getMessage());
                }
            }
            File::delete($filename);
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('canonical source is missing');
            (new Compiler($directory))->compile();
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function canonical(mixed $value): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (is_array($value)) {
                if (! array_is_list($value)) {
                    ksort($value, SORT_STRING);
                }
                foreach ($value as $key => $item) {
                    $value[$key] = $normalize($item);
                }
            }

            return $value;
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
