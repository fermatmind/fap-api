<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;

final class BigFiveContentCompileService
{
    public function __construct(
        private readonly BigFivePackLoader $loader,
        private readonly BigFiveContentLintService $lint,
    ) {
    }

    /**
     * @return array{ok:bool,pack_id:string,version:string,compiled_dir:string,errors:list<array{file:string,line:int,message:string}>,hashes:array<string,string>}
     */
    public function compile(?string $version = null): array
    {
        $version = $this->normalizeVersion($version);
        $lint = $this->lint->lint($version);
        if (!($lint['ok'] ?? false)) {
            return [
                'ok' => false,
                'pack_id' => BigFivePackLoader::PACK_ID,
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

        $questionsRows = $this->loader->readCsvWithLines($this->loader->rawPath('questions_big5_bilingual.csv', $version));
        $facetRows = $this->loader->readCsvWithLines($this->loader->rawPath('facet_map.csv', $version));
        $optionsRows = $this->loader->readCsvWithLines($this->loader->rawPath('options_likert5.csv', $version));
        $normRows = $this->loader->readCsvWithLines($this->loader->rawPath('norm_stats.csv', $version));
        $copyRows = $this->loader->readCsvWithLines($this->loader->rawPath('bucket_copy.csv', $version));
        $goldenRows = $this->loader->readCsvWithLines($this->loader->rawPath('golden_cases.csv', $version));
        $landing = $this->loader->readJson($this->loader->rawPath('landing_i18n.json', $version));
        $policy = $this->loader->readJson($this->loader->rawPath('policy.json', $version));

        $facetMap = [];
        foreach ($facetRows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid <= 0) {
                continue;
            }
            $facetMap[$qid] = [
                'facet_code' => strtoupper((string) ($row['facet_code'] ?? '')),
                'domain_code' => strtoupper((string) ($row['domain_code'] ?? '')),
            ];
        }

        $options = [];
        foreach ($optionsRows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $score = (int) ($row['score'] ?? 0);
            if ($score <= 0) {
                continue;
            }
            $options[] = [
                'code' => (string) ($row['code'] ?? (string) $score),
                'score' => $score,
                'label_en' => (string) ($row['label_en'] ?? ''),
                'label_zh' => (string) ($row['label_zh'] ?? ''),
            ];
        }
        usort($options, static fn (array $a, array $b): int => ((int) $a['score']) <=> ((int) $b['score']));

        $questions = [];
        $questionsDocItems = [];
        $questionsDocItemsByLocale = [
            'zh-CN' => [],
            'en' => [],
        ];
        $questionIndex = [];
        $facetIndex = [];
        $domainIndex = [];

        foreach ($questionsRows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid <= 0) {
                continue;
            }

            $facetCode = (string) ($facetMap[$qid]['facet_code'] ?? '');
            $domainCode = (string) ($facetMap[$qid]['domain_code'] ?? strtoupper((string) ($row['dimension'] ?? '')));
            $direction = (int) ($row['direction'] ?? 1);

            $question = [
                'question_id' => $qid,
                'dimension' => strtoupper((string) ($row['dimension'] ?? $domainCode)),
                'facet_code' => strtoupper($facetCode),
                'direction' => $direction,
                'text_en' => (string) ($row['text_en'] ?? ''),
                'text_zh' => (string) ($row['text_zh'] ?? ''),
            ];
            $questions[] = $question;

            $questionIndex[$qid] = [
                'dimension' => $question['dimension'],
                'facet_code' => $question['facet_code'],
                'direction' => $question['direction'],
            ];

            $facet = $question['facet_code'];
            $domain = $question['dimension'];
            $facetIndex[$facet] = $facetIndex[$facet] ?? [];
            $facetIndex[$facet][] = $qid;
            $domainIndex[$domain] = $domainIndex[$domain] ?? [];
            $domainIndex[$domain][] = $qid;

            $docOptions = [];
            $docOptionsEn = [];
            foreach ($options as $opt) {
                $docOptions[] = [
                    'code' => (string) $opt['code'],
                    'score' => (int) $opt['score'],
                    'text' => (string) $opt['label_zh'],
                    'text_en' => (string) $opt['label_en'],
                ];

                $docOptionsEn[] = [
                    'code' => (string) $opt['code'],
                    'score' => (int) $opt['score'],
                    'text' => (string) $opt['label_en'],
                    'text_zh' => (string) $opt['label_zh'],
                    'text_en' => (string) $opt['label_en'],
                ];
            }

            $zhItem = [
                'question_id' => (string) $qid,
                'order' => $qid,
                'dimension' => $question['dimension'],
                'facet_code' => $question['facet_code'],
                'text' => $question['text_zh'],
                'text_zh' => $question['text_zh'],
                'text_en' => $question['text_en'],
                'direction' => $direction,
                'options' => $docOptions,
            ];
            $questionsDocItems[] = $zhItem;
            $questionsDocItemsByLocale['zh-CN'][] = $zhItem;

            $questionsDocItemsByLocale['en'][] = [
                'question_id' => (string) $qid,
                'order' => $qid,
                'dimension' => $question['dimension'],
                'facet_code' => $question['facet_code'],
                'text' => $question['text_en'],
                'text_zh' => $question['text_zh'],
                'text_en' => $question['text_en'],
                'direction' => $direction,
                'options' => $docOptionsEn,
            ];
        }

        ksort($questionIndex);
        ksort($facetIndex);
        ksort($domainIndex);

        $norms = ['groups' => []];
        foreach ($normRows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $groupId = trim((string) ($row['group_id'] ?? ''));
            $level = strtolower(trim((string) ($row['metric_level'] ?? '')));
            $code = strtoupper(trim((string) ($row['metric_code'] ?? '')));
            if ($groupId === '' || $level === '' || $code === '') {
                continue;
            }

            $region = strtoupper(str_replace('-', '_', trim((string) ($row['region'] ?? ($row['country'] ?? '')))));
            if ($region === '') {
                $region = 'GLOBAL';
            }

            $ageMin = (int) ($row['age_min'] ?? 0);
            $ageMax = (int) ($row['age_max'] ?? 0);
            $ageBand = trim((string) ($row['age_band'] ?? ''));
            if ($ageBand === '' && $ageMin > 0 && $ageMax > 0 && $ageMax >= $ageMin) {
                $ageBand = $ageMin . '-' . $ageMax;
            }
            if ($ageBand === '') {
                $ageBand = 'all';
            }

            $norms['groups'][$groupId] = $norms['groups'][$groupId] ?? [
                'group_id' => $groupId,
                'locale' => (string) ($row['locale'] ?? ''),
                'region' => $region,
                'country' => (string) ($row['country'] ?? $region),
                'gender' => (string) ($row['gender'] ?? ''),
                'age_min' => $ageMin > 0 ? $ageMin : null,
                'age_max' => $ageMax > 0 ? $ageMax : null,
                'age_band' => $ageBand,
                'norms_version' => (string) ($row['norms_version'] ?? ''),
                'source_id' => (string) ($row['source_id'] ?? ''),
                'source_type' => (string) ($row['source_type'] ?? ''),
                'status' => strtoupper((string) ($row['status'] ?? '')),
                'published_at' => (string) ($row['published_at'] ?? ''),
                'metrics' => [
                    'domain' => [],
                    'facet' => [],
                ],
            ];

            $norms['groups'][$groupId]['metrics'][$level][$code] = [
                'mean' => (float) ($row['mean'] ?? 0.0),
                'sd' => (float) ($row['sd'] ?? 0.0),
                'sample_n' => (int) ($row['sample_n'] ?? 0),
                'norms_version' => (string) ($row['norms_version'] ?? ''),
                'source_id' => (string) ($row['source_id'] ?? ''),
                'source_type' => (string) ($row['source_type'] ?? ''),
                'status' => strtoupper((string) ($row['status'] ?? '')),
                'published_at' => (string) ($row['published_at'] ?? ''),
            ];
        }

        $copy = [];
        foreach ($copyRows as $entry) {
            $copy[] = (array) ($entry['row'] ?? []);
        }

        $golden = [];
        foreach ($goldenRows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $golden[] = [
                'case_id' => (string) ($row['case_id'] ?? ''),
                'locale' => (string) ($row['locale'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
                'age_band' => (string) ($row['age_band'] ?? ''),
                'gender' => (string) ($row['gender'] ?? ''),
                'time_seconds_total' => (float) ($row['time_seconds_total'] ?? 0),
                'answers' => json_decode((string) ($row['answers_json'] ?? '[]'), true),
                'expected_norms_status' => (string) ($row['expected_norms_status'] ?? ''),
                'expected_domain_buckets' => json_decode((string) ($row['expected_domain_buckets_json'] ?? '{}'), true),
                'expected_tags' => json_decode((string) ($row['expected_tags_json'] ?? '[]'), true),
                'expected_free_sections' => array_values(array_filter(array_map('trim', explode(',', (string) ($row['expected_free_sections'] ?? ''))))),
                'expected_full_sections' => array_values(array_filter(array_map('trim', explode(',', (string) ($row['expected_full_sections'] ?? ''))))),
            ];
        }

        $questionsPayload = [
            'schema' => 'big5.questions.compiled.v1',
            'pack_id' => BigFivePackLoader::PACK_ID,
            'pack_version' => $version,
            'generated_at' => now()->toISOString(),
            'questions' => $questions,
            'question_index' => $questionIndex,
            'questions_doc' => [
                'schema' => 'fap.questions.v1',
                'items' => $questionsDocItems,
            ],
            'questions_doc_by_locale' => [
                'zh-CN' => [
                    'schema' => 'fap.questions.v1',
                    'locale' => 'zh-CN',
                    'items' => $questionsDocItemsByLocale['zh-CN'],
                ],
                'en' => [
                    'schema' => 'fap.questions.v1',
                    'locale' => 'en',
                    'items' => $questionsDocItemsByLocale['en'],
                ],
            ],
            'options' => $options,
        ];

        $files = [
            'questions.compiled.json' => $questionsPayload,
            'facet_index.json' => [
                'schema' => 'big5.facet_index.v1',
                'pack_id' => BigFivePackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'index' => $facetIndex,
            ],
            'domain_index.json' => [
                'schema' => 'big5.domain_index.v1',
                'pack_id' => BigFivePackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'index' => $domainIndex,
            ],
            'norms.compiled.json' => [
                'schema' => 'big5.norms.compiled.v1',
                'pack_id' => BigFivePackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'groups' => $norms['groups'],
            ],
            'copy.compiled.json' => [
                'schema' => 'big5.copy.compiled.v1',
                'pack_id' => BigFivePackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'rows' => $copy,
            ],
            'landing.compiled.json' => [
                'schema' => 'big5.landing.compiled.v1',
                'pack_id' => BigFivePackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'landing' => is_array($landing) ? $landing : [],
            ],
            'golden_cases.compiled.json' => [
                'schema' => 'big5.golden_cases.compiled.v1',
                'pack_id' => BigFivePackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'cases' => $golden,
            ],
            'policy.compiled.json' => [
                'schema' => 'big5.policy.compiled.v1',
                'pack_id' => BigFivePackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'policy' => is_array($policy) ? $policy : [],
                'variables_allowlist' => $this->loader->readJson($this->loader->rawPath('variables_allowlist.json', $version)) ?? [],
            ],
        ];

        $hashes = [];
        foreach ($files as $name => $payload) {
            $path = $this->loader->compiledPath($name, $version);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (!is_string($json)) {
                continue;
            }
            File::put($path, $json . "\n");
            $hashes[$name] = hash('sha256', $json);
        }

        $manifest = [
            'schema' => 'big5.compiled.manifest.v1',
            'pack_id' => BigFivePackLoader::PACK_ID,
            'pack_version' => $version,
            'generated_at' => now()->toISOString(),
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
            'pack_id' => BigFivePackLoader::PACK_ID,
            'version' => $version,
            'compiled_dir' => $compiledDir,
            'errors' => [],
            'hashes' => $hashes,
        ];
    }

    private function normalizeVersion(?string $version): string
    {
        $version = trim((string) $version);

        return $version !== '' ? $version : BigFivePackLoader::PACK_VERSION;
    }
}
