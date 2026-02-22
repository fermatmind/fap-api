<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;

final class BigFiveContentCompileService
{
    public function __construct(
        private readonly BigFivePackLoader $loader,
        private readonly BigFiveContentLintService $lint,
    ) {}

    /**
     * @return array{ok:bool,pack_id:string,version:string,compiled_dir:string,errors:list<array{file:string,line:int,message:string}>,hashes:array<string,string>}
     */
    public function compile(?string $version = null): array
    {
        $version = $this->normalizeVersion($version);
        $lint = $this->lint->lint($version);
        if (! ($lint['ok'] ?? false)) {
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
        if (! is_dir($compiledDir)) {
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
        $legal = $this->loader->readJson($this->loader->rawPath('legal/disclaimer.json', $version));
        $layout = $this->loader->readJson($this->loader->rawPath('report_layout.json', $version));
        $blocks = $this->readRawBlocks($version);

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
                $ageBand = $ageMin.'-'.$ageMax;
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

        $questionsMinPayload = $this->buildQuestionsMinPayload(
            $version,
            $questionIndex,
            $questionsDocItemsByLocale,
            $options
        );

        $files = [
            'questions.compiled.json' => $questionsPayload,
            'questions.min.compiled.json' => $questionsMinPayload,
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
            'layout.compiled.json' => [
                'schema' => 'big5.layout.compiled.v1',
                'pack_id' => BigFivePackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'layout' => is_array($layout) ? $layout : [],
            ],
            'blocks.compiled.json' => [
                'schema' => 'big5.blocks.compiled.v1',
                'pack_id' => BigFivePackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'blocks' => $blocks,
            ],
            'landing.compiled.json' => [
                'schema' => 'big5.landing.compiled.v1',
                'pack_id' => BigFivePackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'landing' => is_array($landing) ? $landing : [],
            ],
            'legal.compiled.json' => [
                'schema' => 'big5.legal.compiled.v1',
                'pack_id' => BigFivePackLoader::PACK_ID,
                'pack_version' => $version,
                'generated_at' => now()->toISOString(),
                'legal' => $this->buildLegalPayload($legal),
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
            if (! is_string($json)) {
                continue;
            }
            File::put($path, $json."\n");
            $hashes[$name] = hash('sha256', $json);
        }

        $rawDir = $this->loader->rawDir($version);
        $contentHash = $this->hashDirectory($rawDir);
        $compiledHash = $this->hashMap($hashes);
        $normsVersion = $this->resolveManifestNormsVersion(is_array($norms['groups'] ?? null) ? $norms['groups'] : []);

        $manifest = [
            'schema' => 'big5.compiled.manifest.v1',
            'pack_id' => BigFivePackLoader::PACK_ID,
            'pack_version' => $version,
            'dir_version' => $version,
            'generated_at' => now()->toISOString(),
            'compiled_at' => now()->toISOString(),
            'content_hash' => $contentHash,
            'compiled_hash' => $compiledHash,
            'norms_version' => $normsVersion,
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

    /**
     * @return list<array<string,mixed>>
     */
    private function readRawBlocks(string $version): array
    {
        $dir = $this->loader->rawPath('blocks', $version);
        if (! is_dir($dir)) {
            return [];
        }

        $files = File::files($dir);
        usort($files, static fn (\SplFileInfo $a, \SplFileInfo $b): int => strcmp($a->getFilename(), $b->getFilename()));

        $all = [];
        foreach ($files as $file) {
            $ext = strtolower((string) $file->getExtension());
            if ($ext !== 'json') {
                continue;
            }

            $doc = $this->loader->readJson($file->getPathname());
            if (! is_array($doc)) {
                continue;
            }

            $blocks = is_array($doc['blocks'] ?? null) ? $doc['blocks'] : [];
            foreach ($blocks as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $all[] = $block;
            }
        }

        return $all;
    }

    /**
     * @param  array<string,array<string,mixed>>  $groups
     */
    private function resolveManifestNormsVersion(array $groups): string
    {
        $preferred = '';
        $fallback = '';

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }
            $version = trim((string) ($group['norms_version'] ?? ''));
            if ($version === '') {
                continue;
            }

            $status = strtoupper(trim((string) ($group['status'] ?? '')));
            if ($status === 'CALIBRATED') {
                return $version;
            }

            if ($preferred === '' && $status === 'PROVISIONAL') {
                $preferred = $version;
            }
            if ($fallback === '') {
                $fallback = $version;
            }
        }

        return $preferred !== '' ? $preferred : $fallback;
    }

    private function hashMap(array $hashes): string
    {
        ksort($hashes);
        $rows = [];
        foreach ($hashes as $name => $hash) {
            $rows[] = (string) $name.':'.(string) $hash;
        }

        return hash('sha256', implode("\n", $rows));
    }

    private function hashDirectory(string $dir): string
    {
        if (! is_dir($dir)) {
            return '';
        }

        $files = File::allFiles($dir);
        usort($files, static fn (\SplFileInfo $a, \SplFileInfo $b): int => strcmp($a->getPathname(), $b->getPathname()));

        $rows = [];
        $prefix = rtrim($dir, '/\\').DIRECTORY_SEPARATOR;
        foreach ($files as $file) {
            $path = $file->getPathname();
            $relative = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $file->getFilename();
            $rows[] = $relative.':'.hash_file('sha256', $path);
        }

        return hash('sha256', implode("\n", $rows));
    }

    /**
     * @param  array<int,array<string,mixed>>  $questionIndex
     * @param  array<string,list<array<string,mixed>>>  $questionsDocItemsByLocale
     * @param  list<array<string,mixed>>  $options
     * @return array<string,mixed>
     */
    private function buildQuestionsMinPayload(
        string $version,
        array $questionIndex,
        array $questionsDocItemsByLocale,
        array $options
    ): array {
        $textsByLocale = [
            'zh-CN' => [],
            'en' => [],
        ];

        foreach (['zh-CN', 'en'] as $locale) {
            $items = is_array($questionsDocItemsByLocale[$locale] ?? null) ? $questionsDocItemsByLocale[$locale] : [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $qid = (int) ($item['question_id'] ?? 0);
                if ($qid <= 0) {
                    continue;
                }

                $textsByLocale[$locale][$qid] = (string) ($item['text'] ?? '');
            }
            ksort($textsByLocale[$locale], SORT_NUMERIC);
        }

        $optionSetId = 'LIKERT5';
        $optionSets = [
            $optionSetId => $this->buildStableOptionsSet($options),
        ];
        $questionOptionSetRef = [];
        foreach (array_keys($questionIndex) as $qid) {
            $qidInt = (int) $qid;
            if ($qidInt <= 0) {
                continue;
            }
            $questionOptionSetRef[$qidInt] = $optionSetId;
        }
        ksort($questionOptionSetRef, SORT_NUMERIC);

        return [
            'schema' => 'big5.questions.min.compiled.v1',
            'pack_id' => BigFivePackLoader::PACK_ID,
            'pack_version' => $version,
            'generated_at' => now()->toISOString(),
            'question_index' => $questionIndex,
            'texts_by_locale' => $textsByLocale,
            'option_sets' => $optionSets,
            'question_option_set_ref' => $questionOptionSetRef,
            'content_evidence' => [
                'question_index_sha256' => $this->stableHashValue($questionIndex),
                'texts_by_locale_sha256' => $this->stableHashValue($textsByLocale),
                'option_sets_sha256' => $this->stableHashValue($optionSets),
                'question_option_set_ref_sha256' => $this->stableHashValue($questionOptionSetRef),
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $options
     * @return list<array{code:string,score:int,label_zh:string,label_en:string}>
     */
    private function buildStableOptionsSet(array $options): array
    {
        $normalized = [];
        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $normalized[] = [
                'code' => trim((string) ($option['code'] ?? '')),
                'score' => (int) ($option['score'] ?? 0),
                'label_zh' => (string) ($option['label_zh'] ?? ''),
                'label_en' => (string) ($option['label_en'] ?? ''),
            ];
        }

        usort($normalized, static function (array $a, array $b): int {
            $scoreCmp = ((int) $a['score']) <=> ((int) $b['score']);
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            return strcmp((string) $a['code'], (string) $b['code']);
        });

        return $normalized;
    }

    private function stableHashValue(mixed $value): string
    {
        $normalized = $this->normalizeForStableHash($value);
        $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', is_string($encoded) ? $encoded : '{}');
    }

    private function normalizeForStableHash(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(fn (mixed $item): mixed => $this->normalizeForStableHash($item), $value);
            }

            ksort($value);
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeForStableHash($item);
            }

            return $value;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return trim((string) $value);
    }

    /**
     * @param  array<string,mixed>|null  $legal
     * @return array<string,mixed>
     */
    private function buildLegalPayload(?array $legal): array
    {
        $legal = is_array($legal) ? $legal : [];
        $textsNode = is_array($legal['texts'] ?? null) ? $legal['texts'] : [];
        $texts = [
            'zh-CN' => trim((string) ($textsNode['zh-CN'] ?? '')),
            'en' => trim((string) ($textsNode['en'] ?? '')),
        ];

        $requiredNode = is_array($legal['required_fragments'] ?? null) ? $legal['required_fragments'] : [];
        $requiredFragments = [
            'zh-CN' => $this->normalizeStringList($requiredNode['zh-CN'] ?? null),
            'en' => $this->normalizeStringList($requiredNode['en'] ?? null),
        ];

        $prohibitedNode = is_array($legal['prohibited_terms'] ?? null) ? $legal['prohibited_terms'] : [];
        $prohibitedTerms = [
            'zh-CN' => $this->normalizeStringList($prohibitedNode['zh-CN'] ?? null),
            'en' => $this->normalizeStringList($prohibitedNode['en'] ?? null),
        ];

        $scope = $this->normalizeStringList($legal['scope'] ?? null);
        $hashSource = [
            'disclaimer_version' => trim((string) ($legal['disclaimer_version'] ?? '')),
            'effective_date' => trim((string) ($legal['effective_date'] ?? '')),
            'scope' => $scope,
            'texts' => $texts,
        ];

        return [
            'disclaimer_version' => trim((string) ($legal['disclaimer_version'] ?? '')),
            'effective_date' => trim((string) ($legal['effective_date'] ?? '')),
            'scope' => $scope,
            'hash' => hash('sha256', $this->canonicalJson($hashSource)),
            'texts' => $texts,
            'required_fragments' => $requiredFragments,
            'prohibited_terms' => $prohibitedTerms,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }
            $out[] = $text;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function canonicalJson(array $payload): string
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    $payload[$key] = array_map(static function (mixed $item): mixed {
                        return is_array($item) ? $item : (string) $item;
                    }, $value);
                } else {
                    $payload[$key] = $this->canonicalizeAssoc($value);
                }
            }
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '{}';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function canonicalizeAssoc(array $payload): array
    {
        ksort($payload);
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    $payload[$key] = array_map(static fn (mixed $item): string => trim((string) $item), $value);
                } else {
                    $payload[$key] = $this->canonicalizeAssoc($value);
                }
            } else {
                $payload[$key] = trim((string) $value);
            }
        }

        return $payload;
    }
}
