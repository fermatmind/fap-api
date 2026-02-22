<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;

final class ClinicalComboContentCompileService
{
    public function __construct(
        private readonly ClinicalComboPackLoader $loader,
        private readonly ClinicalComboContentLintService $lint,
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
                'pack_id' => ClinicalComboPackLoader::PACK_ID,
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
            'options_sets.compiled.json',
            'policy.compiled.json',
            'layout.compiled.json',
            'blocks.compiled.json',
            'landing.compiled.json',
            'consent.compiled.json',
            'privacy_addendum.compiled.json',
            'crisis_resources.compiled.json',
            'golden_cases.compiled.json',
            'manifest.json',
        ] as $compiledFile) {
            $path = $this->loader->compiledPath($compiledFile, $version);
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $questionsZh = $this->loader->loadQuestionsDoc('zh-CN', $version);
        $questionsEn = $this->loader->loadQuestionsDoc('en', $version);
        $questionIndex = $this->loader->loadQuestionIndex($version);
        $optionSets = $this->loader->loadOptionSets($version);
        $policy = $this->loader->loadPolicy($version);
        $layout = $this->loader->loadLayout($version);
        $landing = $this->loader->loadLanding($version);
        $blocksZh = $this->loader->loadBlocks('zh-CN', $version);
        $blocksEn = $this->loader->loadBlocks('en', $version);
        $consentZh = $this->loader->loadConsent('zh-CN', $version);
        $consentEn = $this->loader->loadConsent('en', $version);
        $privacyZh = $this->loader->loadPrivacyAddendum('zh-CN', $version);
        $privacyEn = $this->loader->loadPrivacyAddendum('en', $version);
        $crisisResources = $this->loader->readJson($this->loader->rawPath('crisis_resources.json', $version)) ?? [];
        $goldenCases = $this->compileGoldenCases($version);

        $questionsPayload = [
            'schema' => 'clinical_combo_68.questions.compiled.v1',
            'pack_id' => ClinicalComboPackLoader::PACK_ID,
            'pack_version' => $version,
            'generated_at' => now()->toISOString(),
            'question_index' => $questionIndex,
            'questions_doc_by_locale' => [
                'zh-CN' => [
                    'schema' => 'fap.questions.v1',
                    'locale' => 'zh-CN',
                    'items' => is_array($questionsZh['items'] ?? null) ? $questionsZh['items'] : [],
                ],
                'en' => [
                    'schema' => 'fap.questions.v1',
                    'locale' => 'en',
                    'items' => is_array($questionsEn['items'] ?? null) ? $questionsEn['items'] : [],
                ],
            ],
        ];

        $optionsPayload = [
            'schema' => 'clinical_combo_68.options.compiled.v1',
            'pack_id' => ClinicalComboPackLoader::PACK_ID,
            'pack_version' => $version,
            'generated_at' => now()->toISOString(),
            'sets' => $optionSets,
        ];

        $policyPayload = [
            'schema' => 'clinical_combo_68.policy.compiled.v1',
            'pack_id' => ClinicalComboPackLoader::PACK_ID,
            'pack_version' => $version,
            'generated_at' => now()->toISOString(),
            'policy' => $policy,
            'variables_allowlist' => $this->loader->readJson($this->loader->rawPath('variables_allowlist.json', $version)) ?? [],
        ];

        $layoutPayload = [
            'schema' => 'clinical_combo_68.layout.compiled.v1',
            'pack_id' => ClinicalComboPackLoader::PACK_ID,
            'pack_version' => $version,
            'generated_at' => now()->toISOString(),
            'layout' => $layout,
        ];

        $blocksPayload = [
            'schema' => 'clinical_combo_68.blocks.compiled.v1',
            'pack_id' => ClinicalComboPackLoader::PACK_ID,
            'pack_version' => $version,
            'generated_at' => now()->toISOString(),
            'blocks_by_locale' => [
                'zh-CN' => $blocksZh,
                'en' => $blocksEn,
            ],
        ];

        $landingPayload = [
            'schema' => 'clinical_combo_68.landing.compiled.v1',
            'pack_id' => ClinicalComboPackLoader::PACK_ID,
            'pack_version' => $version,
            'generated_at' => now()->toISOString(),
            'landing' => $landing,
        ];

        $consentPayload = [
            'schema' => 'clinical_combo_68.consent.compiled.v1',
            'pack_id' => ClinicalComboPackLoader::PACK_ID,
            'pack_version' => $version,
            'generated_at' => now()->toISOString(),
            'consent_by_locale' => [
                'zh-CN' => $consentZh,
                'en' => $consentEn,
            ],
        ];

        $privacyPayload = [
            'schema' => 'clinical_combo_68.privacy.compiled.v1',
            'pack_id' => ClinicalComboPackLoader::PACK_ID,
            'pack_version' => $version,
            'generated_at' => now()->toISOString(),
            'privacy_addendum_by_locale' => [
                'zh-CN' => $privacyZh,
                'en' => $privacyEn,
            ],
        ];

        $crisisResourcesPayload = [
            'schema' => 'clinical_combo_68.crisis_resources.compiled.v1',
            'pack_id' => ClinicalComboPackLoader::PACK_ID,
            'pack_version' => $version,
            'generated_at' => now()->toISOString(),
            'crisis_resources' => $crisisResources,
        ];

        $goldenPayload = [
            'schema' => 'clinical_combo_68.golden_cases.compiled.v1',
            'pack_id' => ClinicalComboPackLoader::PACK_ID,
            'pack_version' => $version,
            'generated_at' => now()->toISOString(),
            'cases' => $goldenCases,
        ];

        $files = [
            'questions.compiled.json' => $questionsPayload,
            'options_sets.compiled.json' => $optionsPayload,
            'policy.compiled.json' => $policyPayload,
            'layout.compiled.json' => $layoutPayload,
            'blocks.compiled.json' => $blocksPayload,
            'landing.compiled.json' => $landingPayload,
            'consent.compiled.json' => $consentPayload,
            'privacy_addendum.compiled.json' => $privacyPayload,
            'crisis_resources.compiled.json' => $crisisResourcesPayload,
            'golden_cases.compiled.json' => $goldenPayload,
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
            'schema' => 'clinical_combo_68.compiled.manifest.v1',
            'pack_id' => ClinicalComboPackLoader::PACK_ID,
            'pack_version' => $version,
            'policy_version' => (string) ($policy['policy_version'] ?? ''),
            'compiled_at' => now()->toISOString(),
            'generated_at' => now()->toISOString(),
            'content_hash' => $this->hashDirectory($this->loader->rawDir($version)),
            'compiled_hash' => $this->hashMap($hashes),
            'files' => $this->manifestFiles($hashes),
        ];

        $manifestJson = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (is_string($manifestJson)) {
            File::put($this->loader->compiledPath('manifest.json', $version), $manifestJson."\n");
            $hashes['manifest.json'] = hash('sha256', $manifestJson);
        }

        return [
            'ok' => true,
            'pack_id' => ClinicalComboPackLoader::PACK_ID,
            'version' => $version,
            'compiled_dir' => $compiledDir,
            'errors' => [],
            'hashes' => $hashes,
        ];
    }

    private function normalizeVersion(?string $version): string
    {
        $version = trim((string) $version);

        return $version !== '' ? $version : ClinicalComboPackLoader::PACK_VERSION;
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
                'case_id' => (string) ($row['case_id'] ?? ''),
                'answers' => (string) ($row['answers'] ?? ''),
                'time_seconds_total' => (int) ($row['time_seconds_total'] ?? 0),
                'expected_crisis_alert' => (int) ($row['expected_crisis_alert'] ?? 0) === 1,
                'expected_masked_depression' => (int) ($row['expected_masked_depression'] ?? 0) === 1,
                'expected_crisis_reasons' => is_array(json_decode((string) ($row['expected_crisis_reasons_json'] ?? '[]'), true))
                    ? json_decode((string) ($row['expected_crisis_reasons_json'] ?? '[]'), true)
                    : [],
            ];
        }

        return $cases;
    }

    /**
     * @param array<string,string> $hashes
     * @return array<string,array{sha256:string}>
     */
    private function manifestFiles(array $hashes): array
    {
        $out = [];
        foreach ($hashes as $name => $hash) {
            $out[$name] = ['sha256' => $hash];
        }

        ksort($out);

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
