<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;

final class EnneagramPackLoader
{
    public const PACK_ID = 'ENNEAGRAM';

    public const PACK_VERSION = 'v1-likert-105';

    public const REGISTRY_PACK_VERSION = 'v2';

    /**
     * @var array<string,string>
     */
    private const REGISTRY_FILE_MAP = [
        'enneagram_type_registry' => 'type_registry.json',
        'enneagram_pair_registry' => 'pair_registry.json',
        'enneagram_group_registry' => 'group_registry.json',
        'enneagram_scenario_registry' => 'scenario_registry.json',
        'enneagram_state_registry' => 'state_registry.json',
        'enneagram_theory_hint_registry' => 'theory_hint_registry.json',
        'enneagram_observation_registry' => 'observation_registry.json',
        'enneagram_method_registry' => 'method_registry.json',
        'enneagram_ui_copy_registry' => 'ui_copy_registry.json',
        'enneagram_sample_report_registry' => 'sample_report_registry.json',
        'enneagram_technical_note_registry' => 'technical_note_registry.json',
    ];

    public function __construct(
        private ?ContentPackV2Resolver $v2Resolver,
        private ContentPathAliasResolver $pathAliasResolver,
        private EnneagramRegistryReleaseResolver $registryReleaseResolver,
    ) {}

    public function packRoot(?string $version = null): string
    {
        $packBase = $this->pathAliasResolver->resolveBackendPackRoot(self::PACK_ID);

        return rtrim($packBase, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$this->normalizeVersion($version);
    }

    public function compiledDir(?string $version = null): string
    {
        $version = $this->normalizeVersion($version);
        $activePath = $this->v2Resolver?->resolveActiveCompiledPath(self::PACK_ID, $version);
        if (is_string($activePath) && $activePath !== '') {
            return $activePath;
        }

        return $this->packRoot($version).DIRECTORY_SEPARATOR.'compiled';
    }

    public function compiledPath(string $file, ?string $version = null): string
    {
        return $this->compiledDir($version).DIRECTORY_SEPARATOR.ltrim($file, DIRECTORY_SEPARATOR);
    }

    public function registryRoot(?string $version = null): string
    {
        return $this->registryReleaseResolver->runtimeRegistryRoot($version);
    }

    public function registryPath(string $file, ?string $version = null): string
    {
        return $this->registryRoot($version).DIRECTORY_SEPARATOR.ltrim($file, DIRECTORY_SEPARATOR);
    }

    public function normalizeLocale(string $locale): string
    {
        return str_starts_with(strtolower(trim($locale)), 'zh') ? 'zh-CN' : 'en';
    }

    public function normalizeVersion(?string $version): string
    {
        $version = trim((string) $version);

        return $version !== '' ? $version : self::PACK_VERSION;
    }

    public function getQuestionCount(?string $version = null): int
    {
        return count($this->loadQuestionIndex($version));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function loadQuestionIndex(?string $version = null): array
    {
        $compiled = $this->requireCompiledJson('questions.compiled.json', $version);
        $index = is_array($compiled['question_index'] ?? null) ? $compiled['question_index'] : [];

        $out = [];
        foreach ($index as $qidRaw => $meta) {
            $qid = (int) $qidRaw;
            if ($qid <= 0 || ! is_array($meta)) {
                continue;
            }

            $out[$qid] = $meta + ['question_id' => $qid];
        }

        ksort($out, SORT_NUMERIC);
        if ($out === []) {
            throw new \RuntimeException('ENNEAGRAM compiled question_index missing.');
        }

        return $out;
    }

    /**
     * @return array{locale_requested:string,locale_resolved:string,items:list<array<string,mixed>>}
     */
    public function loadQuestionsDoc(string $locale, ?string $version = null): array
    {
        $localeResolved = $this->normalizeLocale($locale);
        $compiled = $this->requireCompiledJson('questions.compiled.json', $version);
        $docs = is_array($compiled['questions_doc_by_locale'] ?? null) ? $compiled['questions_doc_by_locale'] : [];
        $doc = $docs[$localeResolved] ?? ($docs['zh-CN'] ?? null);
        if (! is_array($doc)) {
            throw new \RuntimeException('ENNEAGRAM compiled questions_doc_by_locale missing.');
        }

        return [
            'locale_requested' => $locale,
            'locale_resolved' => isset($docs[$localeResolved]) ? $localeResolved : 'zh-CN',
            'items' => array_values(array_filter((array) ($doc['items'] ?? []), static fn ($item): bool => is_array($item))),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function loadPolicy(?string $version = null): array
    {
        $compiled = $this->requireCompiledJson('policy.compiled.json', $version);
        $policy = $compiled['policy'] ?? null;
        if (is_array($policy)) {
            return $policy;
        }

        throw new \RuntimeException('ENNEAGRAM compiled policy missing.');
    }

    public function resolveManifestHash(?string $version = null): string
    {
        $manifest = $this->readCompiledJson('manifest.json', $version);
        if (! is_array($manifest)) {
            return '';
        }

        $hash = trim((string) ($manifest['compiled_hash'] ?? ''));
        if ($hash !== '') {
            return $hash;
        }

        $hashes = is_array($manifest['hashes'] ?? null) ? $manifest['hashes'] : [];
        if ($hashes === []) {
            return '';
        }

        ksort($hashes);
        $rows = [];
        foreach ($hashes as $name => $value) {
            $rows[] = trim((string) $name).':'.trim((string) $value);
        }

        return hash('sha256', implode("\n", $rows));
    }

    /**
     * @return array<string,mixed>
     */
    public function loadRegistryManifest(?string $version = null): array
    {
        $decoded = $this->readRegistryJson('manifest.json', $version);
        if (! is_array($decoded)) {
            throw new \RuntimeException('ENNEAGRAM registry manifest missing or invalid: '.$this->registryPath('manifest.json', $version));
        }

        return $decoded;
    }

    /**
     * @return array<string,mixed>
     */
    public function loadRegistryPack(?string $version = null): array
    {
        $manifest = $this->loadRegistryManifest($version);
        $registries = [];
        foreach (self::REGISTRY_FILE_MAP as $registryKey => $file) {
            $decoded = $this->readRegistryJson($file, $version);
            if (! is_array($decoded)) {
                throw new \RuntimeException("ENNEAGRAM registry file missing or invalid for {$registryKey}: ".$this->registryPath($file, $version));
            }
            $registries[$registryKey] = $decoded;
        }

        $releaseHash = $this->resolveRegistryReleaseHash($version);

        return [
            'root' => $this->registryRoot($version),
            'manifest' => $manifest,
            'release_hash' => $releaseHash,
            'registries' => $registries,
            'type_registry' => $registries['enneagram_type_registry'],
            'pair_registry' => $registries['enneagram_pair_registry'],
            'group_registry' => $registries['enneagram_group_registry'],
            'scenario_registry' => $registries['enneagram_scenario_registry'],
            'state_registry' => $registries['enneagram_state_registry'],
            'theory_hint_registry' => $registries['enneagram_theory_hint_registry'],
            'observation_registry' => $registries['enneagram_observation_registry'],
            'method_registry' => $registries['enneagram_method_registry'],
            'ui_copy_registry' => $registries['enneagram_ui_copy_registry'],
            'sample_report_registry' => $registries['enneagram_sample_report_registry'],
            'technical_note_registry' => $registries['enneagram_technical_note_registry'],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function readRegistryJson(string $file, ?string $version = null): ?array
    {
        return $this->readJson($this->registryPath($file, $version));
    }

    public function resolveRegistryReleaseHash(?string $version = null): string
    {
        $files = array_merge(['manifest.json'], array_values(self::REGISTRY_FILE_MAP));
        sort($files);

        $rows = [];
        foreach ($files as $file) {
            $path = $this->registryPath($file, $version);
            if (! is_file($path)) {
                return '';
            }
            $raw = File::get($path);
            $rows[] = $file.':'.hash('sha256', $raw);
        }

        return 'sha256:'.hash('sha256', implode("\n", $rows));
    }

    /**
     * @return array<string,string>
     */
    public function registryFileMap(): array
    {
        return self::REGISTRY_FILE_MAP;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function readCompiledJson(string $file, ?string $version = null): ?array
    {
        return $this->readJson($this->compiledPath($file, $version));
    }

    /**
     * @return array<string,mixed>
     */
    private function requireCompiledJson(string $file, ?string $version = null): array
    {
        $path = $this->compiledPath($file, $version);
        $decoded = $this->readJson($path);
        if (! is_array($decoded)) {
            throw new \RuntimeException('ENNEAGRAM compiled file missing or invalid: '.$path);
        }

        return $decoded;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $raw = File::get($path);
        } catch (\Throwable) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeRegistryVersion(?string $version): string
    {
        $version = trim((string) $version);

        return $version !== '' ? $version : self::REGISTRY_PACK_VERSION;
    }
}
