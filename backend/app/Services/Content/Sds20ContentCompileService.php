<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;

final class Sds20ContentCompileService
{
    public function __construct(
        private readonly Sds20PackLoader $loader,
        private readonly Sds20ContentLintService $lint,
    ) {
    }

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
                'pack_id' => Sds20PackLoader::PACK_ID,
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
            'sources.compiled.json',
            'report.compiled.json',
            'golden_cases.compiled.json',
            'manifest.json',
        ] as $compiledFile) {
            $path = $this->loader->compiledPath($compiledFile, $version);
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $questionRows = $this->loader->readCsvWithLines($this->loader->rawPath('questions_sds20_bilingual.csv', $version));
        $questionsZh = [];
        $questionsEn = [];
        $questionIndex = [];
        foreach ($questionRows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $qid = (int) ($row['question_id'] ?? 0);
            $direction = (int) ($row['direction'] ?? 0);
            if ($qid <= 0 || !in_array($direction, [1, -1], true)) {
                continue;
            }

            $questionIndex[(string) $qid] = [
                'direction' => $direction,
            ];

            $questionsZh[] = [
                'question_id' => (string) $qid,
                'order' => $qid,
                'direction' => $direction,
                'text' => trim((string) ($row['text_zh'] ?? '')),
            ];
            $questionsEn[] = [
                'question_id' => (string) $qid,
                'order' => $qid,
                'direction' => $direction,
                'text' => trim((string) ($row['text_en'] ?? '')),
            ];
        }

        usort($questionsZh, static fn (array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));
        usort($questionsEn, static fn (array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));
        ksort($questionIndex, SORT_NATURAL);

        $optionsDoc = $this->loader->readJson($this->loader->rawPath('options_sds20_bilingual.json', $version)) ?? [];
        $optionsPayload = [
            'schema' => 'sds_20.options.compiled.v1',
            'pack_id' => Sds20PackLoader::PACK_ID,
            'pack_version' => $version,
            'generated_at' => now()->toISOString(),
            'set_code' => (string) ($optionsDoc['set_code'] ?? 'SDS_LIKERT4'),
            'codes' => array_values(array_map(
                static fn ($code): string => strtoupper(trim((string) $code)),
                (array) ($optionsDoc['codes'] ?? ['A', 'B', 'C', 'D'])
            )),
            'options_format_by_locale' => [
                'zh-CN' => array_values(array_map(
                    static fn ($label): string => trim((string) $label),
                    (array) data_get($optionsDoc, 'labels.zh-CN', [])
                )),
                'en' => array_values(array_map(
                    static fn ($label): string => trim((string) $label),
                    (array) data_get($optionsDoc, 'labels.en', [])
                )),
            ],
        ];

        $policyDoc = $this->loader->readJson($this->loader->rawPath('policy.json', $version)) ?? [];
        $landingDoc = $this->loader->readJson($this->loader->rawPath('landing_i18n.json', $version)) ?? [];

        $sources = $this->compileSourceCatalog($version);

        $reportLayoutRaw = $this->loader->readJson($this->loader->rawPath('report_layout.json', $version)) ?? [];
        $reportLayout = is_array($reportLayoutRaw['layout'] ?? null)
            ? $reportLayoutRaw['layout']
            : $reportLayoutRaw;

        $freeDoc = $this->loader->readJson($this->loader->rawPath('blocks/free_blocks.json', $version)) ?? [];
        $paidDoc = $this->loader->readJson($this->loader->rawPath('blocks/paid_blocks.json', $version)) ?? [];

        $reportBlocks = [
            'zh-CN' => array_values(array_merge(
                $this->normalizeBlockRows($freeDoc, 'zh-CN', 'free'),
                $this->normalizeBlockRows($paidDoc, 'zh-CN', 'paid')
            )),
            'en' => array_values(array_merge(
                $this->normalizeBlockRows($freeDoc, 'en', 'free'),
                $this->normalizeBlockRows($paidDoc, 'en', 'paid')
            )),
        ];

        $goldenCases = $this->compileGoldenCases($version);

        $files = [
            'questions.compiled.json' => [
                'schema' => 'sds_20.questions.compiled.v1',
                'pack_id' => Sds20PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
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
            'options.compiled.json' => $optionsPayload,
            'policy.compiled.json' => [
                'schema' => 'sds_20.policy.compiled.v1',
                'pack_id' => Sds20PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'policy' => $policyDoc,
            ],
            'landing.compiled.json' => [
                'schema' => 'sds_20.landing.compiled.v1',
                'pack_id' => Sds20PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'landing' => $landingDoc,
            ],
            'sources.compiled.json' => [
                'schema' => 'sds_20.sources.compiled.v1',
                'pack_id' => Sds20PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'sources' => $sources,
            ],
            'report.compiled.json' => [
                'schema' => 'sds_20.report.compiled.v1',
                'pack_id' => Sds20PackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'layout' => $reportLayout,
                'blocks_by_locale' => $reportBlocks,
            ],
            'golden_cases.compiled.json' => [
                'schema' => 'sds_20.golden_cases.compiled.v1',
                'pack_id' => Sds20PackLoader::PACK_ID,
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
            File::put($this->loader->compiledPath($name, $version), $json."\n");
            $hashes[$name] = hash('sha256', $json);
        }

        $manifest = [
            'schema' => 'sds_20.compiled.manifest.v1',
            'pack_id' => Sds20PackLoader::PACK_ID,
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
            File::put($this->loader->compiledPath('manifest.json', $version), $manifestJson."\n");
            $hashes['manifest.json'] = hash('sha256', $manifestJson);
        }

        return [
            'ok' => true,
            'pack_id' => Sds20PackLoader::PACK_ID,
            'version' => $version,
            'compiled_dir' => $compiledDir,
            'errors' => [],
            'hashes' => $hashes,
        ];
    }

    /**
     * @param array<string,mixed> $doc
     * @return list<array<string,mixed>>
     */
    private function normalizeBlockRows(array $doc, string $locale, string $accessLevel): array
    {
        $rows = $doc[$locale] ?? [];
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $out[] = [
                'block_id' => (string) ($row['block_id'] ?? ''),
                'section_key' => (string) ($row['section_key'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'body_md' => (string) ($row['body_md'] ?? ''),
                'access_level' => $accessLevel,
            ];
        }

        return $out;
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
                'duration_ms' => (int) ($row['duration_ms'] ?? 98000),
                'expected_index_score' => (int) ($row['expected_index_score'] ?? 0),
                'expected_clinical_level' => trim((string) ($row['expected_clinical_level'] ?? 'normal')),
                'expected_crisis_alert' => trim((string) ($row['expected_crisis_alert'] ?? '0')) === '1',
                'expected_quality_level' => strtoupper(trim((string) ($row['expected_quality_level'] ?? 'A'))),
                'expected_has_somatic_exhaustion_mask' => trim((string) ($row['expected_has_somatic_exhaustion_mask'] ?? '0')) === '1',
            ];
        }

        return $cases;
    }

    /**
     * @return list<array<string,string>>
     */
    private function compileSourceCatalog(string $version): array
    {
        $rows = $this->loader->readCsvWithLines($this->loader->rawPath('source_catalog.csv', $version));
        $out = [];
        foreach ($rows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $sourceId = trim((string) ($row['source_id'] ?? ''));
            if ($sourceId === '') {
                continue;
            }

            $out[] = [
                'source_id' => $sourceId,
                'source_name' => trim((string) ($row['source_name'] ?? '')),
                'license_note' => trim((string) ($row['license_note'] ?? '')),
                'citation' => trim((string) ($row['citation'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param array<string,string> $hashes
     */
    private function hashMap(array $hashes): string
    {
        ksort($hashes);
        $rows = [];
        foreach ($hashes as $name => $hash) {
            $rows[] = $name.':'.$hash;
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

        $prefix = rtrim($dir, '/\\').DIRECTORY_SEPARATOR;
        $rows = [];
        foreach ($files as $file) {
            $path = $file->getPathname();
            $rel = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $file->getFilename();
            $rows[] = $rel.':'.hash_file('sha256', $path);
        }

        return hash('sha256', implode("\n", $rows));
    }
}
