<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;

final class Eq60ContentCompileService
{
    public function __construct(
        private readonly Eq60PackLoader $loader,
        private readonly Eq60ContentLintService $lint,
    ) {}

    /**
     * @return array{ok:bool,pack_id:string,version:string,compiled_dir:string,errors:list<array{file:string,line:int,message:string}>,hashes:array<string,string>}
     */
    public function compile(?string $version = null): array
    {
        $version = $this->loader->normalizeVersion($version);
        $lint = $this->lint->lint($version);
        if (!($lint['ok'] ?? false)) {
            return [
                'ok' => false,
                'pack_id' => Eq60PackLoader::PACK_ID,
                'version' => $version,
                'compiled_dir' => $this->loader->compiledDir($version),
                'errors' => is_array($lint['errors'] ?? null) ? $lint['errors'] : [],
                'hashes' => [],
            ];
        }

        $compiledDir = $this->loader->compiledDir($version);
        if (!is_dir($compiledDir)) {
            File::makeDirectory($compiledDir, 0775, true, true);
        }

        foreach ([
            'questions.compiled.json',
            'options.compiled.json',
            'policy.compiled.json',
            'landing.compiled.json',
            'golden_cases.compiled.json',
            'manifest.json',
        ] as $compiledFile) {
            $path = $this->loader->compiledPath($compiledFile, $version);
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $questionRows = $this->loader->readCsvWithLines($this->loader->rawPath('questions_eq60_bilingual.csv', $version));
        $questionsZh = [];
        $questionsEn = [];
        $questionIndex = [];

        foreach ($questionRows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $qid = (int) ($row['question_id'] ?? 0);
            $dimension = strtoupper(trim((string) ($row['dimension'] ?? '')));
            $direction = (int) ($row['direction'] ?? 0);
            if ($qid <= 0 || !in_array($dimension, ['SA', 'ER', 'SE', 'RM'], true) || !in_array($direction, [1, -1], true)) {
                continue;
            }

            $questionIndex[(string) $qid] = [
                'dimension' => $dimension,
                'direction' => $direction,
            ];

            $questionsZh[] = [
                'question_id' => (string) $qid,
                'order' => $qid,
                'dimension' => $dimension,
                'direction' => $direction,
                'text' => trim((string) ($row['text_zh'] ?? '')),
            ];

            $questionsEn[] = [
                'question_id' => (string) $qid,
                'order' => $qid,
                'dimension' => $dimension,
                'direction' => $direction,
                'text' => trim((string) ($row['text_en'] ?? '')),
            ];
        }

        usort($questionsZh, static fn (array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));
        usort($questionsEn, static fn (array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));
        ksort($questionIndex, SORT_NATURAL);

        $optionsRaw = $this->loader->readJson($this->loader->rawPath('options_eq60_bilingual.json', $version)) ?? [];
        $codes = array_values(array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            (array) ($optionsRaw['codes'] ?? ['A', 'B', 'C', 'D', 'E'])
        ));

        $labelsByLocale = [
            'zh-CN' => array_values(array_map(static fn ($label): string => trim((string) $label), (array) data_get($optionsRaw, 'labels.zh-CN', []))),
            'en' => array_values(array_map(static fn ($label): string => trim((string) $label), (array) data_get($optionsRaw, 'labels.en', []))),
        ];

        $scoreMapRaw = is_array($optionsRaw['score_map'] ?? null) ? $optionsRaw['score_map'] : [];
        $scoreMap = [];
        foreach ($scoreMapRaw as $code => $value) {
            $normCode = strtoupper(trim((string) $code));
            if ($normCode === '') {
                continue;
            }
            $scoreMap[$normCode] = (int) $value;
        }

        $optionAnchorsByLocale = [
            'zh-CN' => $this->buildOptionAnchors($codes, $labelsByLocale['zh-CN']),
            'en' => $this->buildOptionAnchors($codes, $labelsByLocale['en']),
        ];

        $policyRaw = $this->loader->readJson($this->loader->rawPath('policy.json', $version)) ?? [];
        $landingRaw = $this->loader->readJson($this->loader->rawPath('landing_i18n.json', $version)) ?? [];
        $goldenCases = $this->compileGoldenCases($version);

        $files = [
            'questions.compiled.json' => [
                'schema' => 'eq_60.questions.compiled.v1',
                'pack_id' => Eq60PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'dimension_codes' => ['SA', 'ER', 'SE', 'RM'],
                'question_index' => $questionIndex,
                'questions_doc_by_locale' => [
                    'zh-CN' => [
                        'schema' => 'fap.questions.v1',
                        'locale' => 'zh-CN',
                        'items' => $questionsZh,
                    ],
                    'en' => [
                        'schema' => 'fap.questions.v1',
                        'locale' => 'en',
                        'items' => $questionsEn,
                    ],
                ],
            ],
            'options.compiled.json' => [
                'schema' => 'eq_60.options.compiled.v1',
                'pack_id' => Eq60PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'codes' => $codes,
                'labels_by_locale' => $labelsByLocale,
                'score_map' => $scoreMap,
                'option_anchors_by_locale' => $optionAnchorsByLocale,
            ],
            'policy.compiled.json' => [
                'schema' => 'eq_60.policy.compiled.v1',
                'pack_id' => Eq60PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'policy' => $policyRaw,
            ],
            'landing.compiled.json' => [
                'schema' => 'eq_60.landing.compiled.v1',
                'pack_id' => Eq60PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'landing' => $landingRaw,
            ],
            'golden_cases.compiled.json' => [
                'schema' => 'eq_60.golden_cases.compiled.v1',
                'pack_id' => Eq60PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'cases' => $goldenCases,
            ],
        ];

        $hashes = [];
        foreach ($files as $name => $payload) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (!is_string($json)) {
                continue;
            }

            File::put($this->loader->compiledPath($name, $version), $json . "\n");
            $hashes[$name] = hash('sha256', $json);
        }

        $manifest = [
            'schema' => 'eq_60.compiled.manifest.v1',
            'pack_id' => Eq60PackLoader::PACK_ID,
            'pack_version' => $version,
            'compiled_at' => now()->toISOString(),
            'generated_at' => now()->toISOString(),
            'content_hash' => $this->hashDirectory($this->loader->rawDir($version)),
            'compiled_hash' => $this->hashMap($hashes),
            'hashes' => $hashes,
            'compiled_files' => array_keys($files),
        ];

        $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (is_string($manifestJson)) {
            File::put($this->loader->compiledPath('manifest.json', $version), $manifestJson . "\n");
            $hashes['manifest.json'] = hash('sha256', $manifestJson);
        }

        return [
            'ok' => true,
            'pack_id' => Eq60PackLoader::PACK_ID,
            'version' => $version,
            'compiled_dir' => $compiledDir,
            'errors' => [],
            'hashes' => $hashes,
        ];
    }

    /**
     * @param list<string> $codes
     * @param list<string> $labels
     * @return list<array{code:string,label:string}>
     */
    private function buildOptionAnchors(array $codes, array $labels): array
    {
        $anchors = [];
        foreach ($codes as $idx => $codeRaw) {
            $code = strtoupper(trim((string) $codeRaw));
            $label = trim((string) ($labels[$idx] ?? ''));
            if ($code === '' || $label === '') {
                continue;
            }

            $anchors[] = [
                'code' => $code,
                'label' => $label,
            ];
        }

        return $anchors;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function compileGoldenCases(string $version): array
    {
        $rows = $this->loader->readCsvWithLines($this->loader->rawPath('golden_cases.csv', $version));
        $cases = [];
        foreach ($rows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $cases[] = [
                'case_id' => trim((string) ($row['case_id'] ?? '')),
                'locale' => trim((string) ($row['locale'] ?? 'zh-CN')),
                'answers' => strtoupper(trim((string) ($row['answers'] ?? ''))),
                'expected_total' => (int) ($row['expected_total'] ?? 0),
                'expected_sa' => (int) ($row['expected_sa'] ?? 0),
                'expected_er' => (int) ($row['expected_er'] ?? 0),
                'expected_se' => (int) ($row['expected_se'] ?? 0),
                'expected_rm' => (int) ($row['expected_rm'] ?? 0),
            ];
        }

        return $cases;
    }

    /**
     * @param array<string,string> $hashes
     */
    private function hashMap(array $hashes): string
    {
        ksort($hashes);
        $rows = [];
        foreach ($hashes as $name => $hash) {
            $rows[] = $name . ':' . $hash;
        }

        return hash('sha256', implode("\n", $rows));
    }

    private function hashDirectory(string $dir): string
    {
        if (!is_dir($dir)) {
            return '';
        }

        $files = File::allFiles($dir);
        usort($files, static fn (\SplFileInfo $a, \SplFileInfo $b): int => strcmp($a->getPathname(), $b->getPathname()));

        $prefix = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
        $rows = [];
        foreach ($files as $file) {
            $path = $file->getPathname();
            $rel = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $file->getFilename();
            $rows[] = $rel . ':' . hash_file('sha256', $path);
        }

        return hash('sha256', implode("\n", $rows));
    }
}
