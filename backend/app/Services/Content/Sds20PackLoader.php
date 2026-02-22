<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;

final class Sds20PackLoader
{
    public const PACK_ID = 'SDS_20';

    public const PACK_VERSION = 'v1';

    public function packRoot(?string $version = null): string
    {
        return base_path('content_packs/'.self::PACK_ID.'/'.$this->normalizeVersion($version));
    }

    public function rawDir(?string $version = null): string
    {
        return $this->packRoot($version).DIRECTORY_SEPARATOR.'raw';
    }

    public function compiledDir(?string $version = null): string
    {
        return $this->packRoot($version).DIRECTORY_SEPARATOR.'compiled';
    }

    public function rawPath(string $file, ?string $version = null): string
    {
        return $this->rawDir($version).DIRECTORY_SEPARATOR.ltrim($file, DIRECTORY_SEPARATOR);
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

    public function hasCompiledFile(string $file, ?string $version = null): bool
    {
        return is_file($this->compiledPath($file, $version));
    }

    public function getQuestionCount(?string $version = null): int
    {
        $doc = $this->loadQuestionsDoc('zh-CN', $version);
        $items = is_array($doc['items'] ?? null) ? $doc['items'] : [];

        return count($items);
    }

    /**
     * @return array<int,array{direction:int}>
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

            $direction = (int) ($meta['direction'] ?? 0);
            if (! in_array($direction, [1, -1], true)) {
                continue;
            }

            $out[$qid] = ['direction' => $direction];
        }

        ksort($out, SORT_NUMERIC);

        return $out;
    }

    /**
     * @return array{locale_requested:string,locale_resolved:string,items:list<array<string,mixed>>}
     */
    public function loadQuestionsDoc(string $locale, ?string $version = null): array
    {
        $compiled = $this->requireCompiledJson('questions.compiled.json', $version);
        $localeResolved = $this->normalizeLocale($locale);

        $docs = is_array($compiled['questions_doc_by_locale'] ?? null) ? $compiled['questions_doc_by_locale'] : [];
        $doc = $docs[$localeResolved] ?? ($docs['zh-CN'] ?? null);
        if (! is_array($doc)) {
            throw new \RuntimeException('SDS20_COMPILED_INVALID: questions_doc_by_locale missing.');
        }

        $resolvedLocale = isset($docs[$localeResolved]) ? $localeResolved : 'zh-CN';

        return [
            'locale_requested' => $locale,
            'locale_resolved' => $resolvedLocale,
            'items' => is_array($doc['items'] ?? null) ? $doc['items'] : [],
        ];
    }

    /**
     * @return list<string>
     */
    public function loadOptionsFormat(string $locale, ?string $version = null): array
    {
        $compiled = $this->requireCompiledJson('options.compiled.json', $version);
        $localeResolved = $this->normalizeLocale($locale);

        $formats = is_array($compiled['options_format_by_locale'] ?? null)
            ? $compiled['options_format_by_locale']
            : [];
        $format = $formats[$localeResolved] ?? ($formats['zh-CN'] ?? []);

        $out = [];
        if (is_array($format)) {
            foreach ($format as $item) {
                $value = trim((string) $item);
                if ($value !== '') {
                    $out[] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public function loadLanding(string $locale, ?string $version = null): array
    {
        $compiled = $this->requireCompiledJson('landing.compiled.json', $version);
        $localeResolved = $this->normalizeLocale($locale);

        $landing = is_array($compiled['landing'] ?? null) ? $compiled['landing'] : [];
        $node = is_array($landing[$localeResolved] ?? null)
            ? $landing[$localeResolved]
            : (is_array($landing['zh-CN'] ?? null) ? $landing['zh-CN'] : []);

        $consent = is_array($node['consent'] ?? null) ? $node['consent'] : [];
        $disclaimer = is_array($node['disclaimer'] ?? null) ? $node['disclaimer'] : [];

        $versionResolved = $this->normalizeVersion($version);
        $consentVersion = trim((string) ($consent['version'] ?? ''));
        $consentText = trim((string) ($consent['text'] ?? ''));
        $consentHash = trim((string) ($consent['hash'] ?? ''));
        if ($consentHash === '') {
            $consentHash = hash('sha256', $consentVersion.'|'.$consentText);
        }

        $disclaimerVersion = trim((string) ($disclaimer['version'] ?? ''));
        if ($disclaimerVersion === '') {
            $disclaimerVersion = 'SDS_20_disclaimer_'.$versionResolved;
        }

        $disclaimerText = trim((string) ($disclaimer['text'] ?? ''));

        return [
            'locale_resolved' => $localeResolved,
            'title' => trim((string) ($node['title'] ?? '')),
            'consent' => [
                'required' => (bool) ($consent['required'] ?? true),
                'version' => $consentVersion,
                'text' => $consentText,
                'hash' => $consentHash,
            ],
            'disclaimer' => [
                'version' => $disclaimerVersion,
                'text' => $disclaimerText,
                'hash' => hash('sha256', $disclaimerVersion.'|'.$disclaimerText),
            ],
            'crisis_hotline' => trim((string) ($node['crisis_hotline'] ?? '')),
        ];
    }

    /**
     * @return list<array<string,string>>
     */
    public function loadSourceCatalog(?string $version = null): array
    {
        $compiled = $this->requireCompiledJson('sources.compiled.json', $version);
        $sources = is_array($compiled['sources'] ?? null) ? $compiled['sources'] : [];

        $out = [];
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $sourceId = trim((string) ($source['source_id'] ?? ''));
            if ($sourceId === '') {
                continue;
            }

            $out[] = [
                'source_id' => $sourceId,
                'source_name' => trim((string) ($source['source_name'] ?? '')),
                'license_note' => trim((string) ($source['license_note'] ?? '')),
                'citation' => trim((string) ($source['citation'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public function loadPolicy(?string $version = null): array
    {
        $compiled = $this->requireCompiledJson('policy.compiled.json', $version);

        return is_array($compiled['policy'] ?? null) ? $compiled['policy'] : [];
    }

    /**
     * @return array<string,mixed>
     */
    public function loadReportLayout(?string $version = null): array
    {
        $compiled = $this->requireCompiledJson('report.compiled.json', $version);

        return is_array($compiled['layout'] ?? null) ? $compiled['layout'] : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function loadReportBlocks(string $locale, ?string $version = null): array
    {
        $compiled = $this->requireCompiledJson('report.compiled.json', $version);
        $localeResolved = $this->normalizeLocale($locale);

        $blocksByLocale = is_array($compiled['blocks_by_locale'] ?? null) ? $compiled['blocks_by_locale'] : [];
        $blocks = $blocksByLocale[$localeResolved] ?? ($blocksByLocale['zh-CN'] ?? []);

        if (! is_array($blocks)) {
            return [];
        }

        return array_values(array_filter($blocks, static fn ($row): bool => is_array($row)));
    }

    public function resolveManifestHash(?string $version = null): string
    {
        $manifest = $this->requireCompiledJson('manifest.json', $version);

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
     * @return list<array{line:int,row:array<string,string>}>
     */
    public function readCsvWithLines(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $fp = fopen($path, 'rb');
        if ($fp === false) {
            return [];
        }

        $rows = [];
        $header = null;
        $lineNo = 0;

        while (($row = fgetcsv($fp, 0, ',', '"', '\\')) !== false) {
            $lineNo++;
            if ($lineNo === 1) {
                $header = is_array($row) ? array_map(static fn ($v): string => trim((string) $v), $row) : [];

                continue;
            }

            if (! is_array($row) || $header === [] || $row === [null]) {
                continue;
            }

            $assoc = [];
            foreach ($header as $idx => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = trim((string) ($row[$idx] ?? ''));
            }

            $rows[] = [
                'line' => $lineNo,
                'row' => $assoc,
            ];
        }

        fclose($fp);

        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function readJson(string $path): ?array
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

    /**
     * @return array<string,mixed>
     */
    private function requireCompiledJson(string $file, ?string $version = null): array
    {
        $versionResolved = $this->normalizeVersion($version);
        $path = $this->compiledPath($file, $versionResolved);

        if (! is_file($path)) {
            throw $this->compiledMissingException($path, $versionResolved);
        }

        $decoded = $this->readJson($path);
        if (! is_array($decoded)) {
            throw new \RuntimeException('SDS20_COMPILED_INVALID: '.$path);
        }

        return $decoded;
    }

    private function compiledMissingException(string $path, string $version): \RuntimeException
    {
        return new \RuntimeException(
            'SDS20_COMPILED_MISSING: '.$path.'. Run: php artisan content:compile --pack=SDS_20 --pack-version='.$version
        );
    }
}
