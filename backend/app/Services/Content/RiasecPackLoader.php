<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;

final class RiasecPackLoader
{
    public const PACK_ID = 'RIASEC';

    public const PACK_VERSION = 'v1-standard-60';

    public function __construct(
        private ?ContentPackV2Resolver $v2Resolver,
        private ContentPathAliasResolver $pathAliasResolver,
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
            throw new \RuntimeException('RIASEC compiled question_index missing.');
        }

        return $out;
    }

    /**
     * @return array{locale_requested:string,locale_resolved:string,items:list<array<string,mixed>>,option_anchors:list<array<string,mixed>>,dimension_codes:list<string>,form_kind:string}
     */
    public function loadQuestionsDoc(string $locale, ?string $version = null): array
    {
        $localeResolved = $this->normalizeLocale($locale);
        $compiled = $this->requireCompiledJson('questions.compiled.json', $version);
        $docs = is_array($compiled['questions_doc_by_locale'] ?? null) ? $compiled['questions_doc_by_locale'] : [];
        $doc = $docs[$localeResolved] ?? ($docs['zh-CN'] ?? null);
        if (! is_array($doc)) {
            throw new \RuntimeException('RIASEC compiled questions_doc_by_locale missing.');
        }

        return [
            'locale_requested' => $locale,
            'locale_resolved' => isset($docs[$localeResolved]) ? $localeResolved : 'zh-CN',
            'items' => array_values(array_filter((array) ($doc['items'] ?? []), static fn ($item): bool => is_array($item))),
            'option_anchors' => array_values(array_filter((array) ($compiled['option_anchors'] ?? []), static fn ($item): bool => is_array($item))),
            'dimension_codes' => array_values(array_filter(array_map('strval', (array) ($compiled['dimension_codes'] ?? ['R', 'I', 'A', 'S', 'E', 'C'])))),
            'form_kind' => trim((string) ($compiled['form_kind'] ?? 'standard')),
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

        throw new \RuntimeException('RIASEC compiled policy missing.');
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
            throw new \RuntimeException('RIASEC compiled file missing or invalid: '.$path);
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
}
