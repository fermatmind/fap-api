<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;

final class Eq60PackLoader
{
    public const PACK_ID = 'EQ_60';

    public const PACK_VERSION = 'v1';

    public function __construct(
        private ?ContentPackV2Resolver $v2Resolver = null,
    ) {}

    public function packRoot(?string $version = null): string
    {
        return base_path('content_packs/' . self::PACK_ID . '/' . $this->normalizeVersion($version));
    }

    public function rawDir(?string $version = null): string
    {
        return $this->packRoot($version) . DIRECTORY_SEPARATOR . 'raw';
    }

    public function compiledDir(?string $version = null): string
    {
        $version = $this->normalizeVersion($version);
        $activePath = $this->v2Resolver?->resolveActiveCompiledPath(self::PACK_ID, $version);
        if (is_string($activePath) && $activePath !== '') {
            return $activePath;
        }

        return $this->packRoot($version) . DIRECTORY_SEPARATOR . 'compiled';
    }

    public function rawPath(string $file, ?string $version = null): string
    {
        return $this->rawDir($version) . DIRECTORY_SEPARATOR . ltrim($file, DIRECTORY_SEPARATOR);
    }

    public function compiledPath(string $file, ?string $version = null): string
    {
        return $this->compiledDir($version) . DIRECTORY_SEPARATOR . ltrim($file, DIRECTORY_SEPARATOR);
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
     * @return array<int,array{dimension:string,direction:int}>
     */
    public function loadQuestionIndex(?string $version = null): array
    {
        $compiled = $this->requireCompiledJson('questions.compiled.json', $version);
        $index = is_array($compiled['question_index'] ?? null) ? $compiled['question_index'] : [];

        $out = [];
        foreach ($index as $qidRaw => $meta) {
            $qid = (int) $qidRaw;
            if ($qid <= 0 || !is_array($meta)) {
                continue;
            }

            $dimension = strtoupper(trim((string) ($meta['dimension'] ?? '')));
            $direction = (int) ($meta['direction'] ?? 0);
            if (!in_array($dimension, ['SA', 'ER', 'EM', 'RM'], true)) {
                continue;
            }
            if (!in_array($direction, [1, -1], true)) {
                continue;
            }

            $out[$qid] = [
                'dimension' => $dimension,
                'direction' => $direction,
            ];
        }

        ksort($out, SORT_NUMERIC);

        if ($out === []) {
            $path = $this->compiledPath('questions.compiled.json', $version);
            throw new \RuntimeException(
                'EQ_60 compiled question_index missing or empty at ' . $path
                    . '. Run: php artisan content:compile --pack=EQ_60 --pack-version=' . $this->normalizeVersion($version)
            );
        }

        return $out;
    }

    /**
     * @return array{locale_requested:string,locale_resolved:string,items:list<array<string,mixed>>,dimension_codes:list<string>,option_anchors:list<array<string,string>>}
     */
    public function loadQuestionsDoc(string $locale, ?string $version = null): array
    {
        $localeResolved = $this->normalizeLocale($locale);
        $compiled = $this->requireCompiledJson('questions.compiled.json', $version);
        $docs = is_array($compiled['questions_doc_by_locale'] ?? null) ? $compiled['questions_doc_by_locale'] : [];
        $doc = $docs[$localeResolved] ?? ($docs['zh-CN'] ?? null);
        if (!is_array($doc)) {
            $path = $this->compiledPath('questions.compiled.json', $version);
            throw new \RuntimeException(
                'EQ_60 compiled questions_doc_by_locale missing at ' . $path
                    . '. Run: php artisan content:compile --pack=EQ_60 --pack-version=' . $this->normalizeVersion($version)
            );
        }

        $resolvedLocale = isset($docs[$localeResolved]) ? $localeResolved : 'zh-CN';
        $dimensionCodes = array_values(array_filter(array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            (array) ($compiled['dimension_codes'] ?? ['SA', 'ER', 'EM', 'RM'])
        )));

        return [
            'locale_requested' => $locale,
            'locale_resolved' => $resolvedLocale,
            'items' => array_values(array_filter((array) ($doc['items'] ?? []), static fn ($item): bool => is_array($item))),
            'dimension_codes' => $dimensionCodes,
            'option_anchors' => $this->loadOptionAnchors($resolvedLocale, $version),
        ];
    }

    /**
     * @return array{codes:list<string>,labels:array<string,list<string>>,score_map:array<string,int>}
     */
    public function loadOptions(?string $version = null): array
    {
        $compiled = $this->requireCompiledJson('options.compiled.json', $version);
        $codes = array_values(array_map(
            static fn ($code): string => strtoupper(trim((string) $code)),
            (array) ($compiled['codes'] ?? [])
        ));

        $scoreMapRaw = is_array($compiled['score_map'] ?? null) ? $compiled['score_map'] : [];
        $scoreMap = [];
        foreach ($scoreMapRaw as $code => $value) {
            $normCode = strtoupper(trim((string) $code));
            if ($normCode === '') {
                continue;
            }
            $scoreMap[$normCode] = (int) $value;
        }

        $labelsByLocale = is_array($compiled['labels_by_locale'] ?? null) ? $compiled['labels_by_locale'] : [];

        return [
            'codes' => $codes,
            'labels' => [
                'zh-CN' => array_values(array_map(static fn ($label): string => trim((string) $label), (array) ($labelsByLocale['zh-CN'] ?? []))),
                'en' => array_values(array_map(static fn ($label): string => trim((string) $label), (array) ($labelsByLocale['en'] ?? []))),
            ],
            'score_map' => $scoreMap,
        ];
    }

    /**
     * @return list<array{code:string,label:string}>
     */
    public function loadOptionAnchors(string $locale, ?string $version = null): array
    {
        $localeResolved = $this->normalizeLocale($locale);
        $compiled = $this->requireCompiledJson('options.compiled.json', $version);
        $anchorsByLocale = is_array($compiled['option_anchors_by_locale'] ?? null) ? $compiled['option_anchors_by_locale'] : [];
        $anchors = $anchorsByLocale[$localeResolved] ?? ($anchorsByLocale['zh-CN'] ?? []);
        if (!is_array($anchors)) {
            $path = $this->compiledPath('options.compiled.json', $version);
            throw new \RuntimeException(
                'EQ_60 compiled option_anchors_by_locale missing at ' . $path
                    . '. Run: php artisan content:compile --pack=EQ_60 --pack-version=' . $this->normalizeVersion($version)
            );
        }

        $normalized = [];
        foreach ($anchors as $anchor) {
            if (!is_array($anchor)) {
                continue;
            }

            $code = strtoupper(trim((string) ($anchor['code'] ?? '')));
            $label = trim((string) ($anchor['label'] ?? ''));
            if ($code === '' || $label === '') {
                continue;
            }
            $normalized[] = ['code' => $code, 'label' => $label];
        }

        if ($normalized === []) {
            $path = $this->compiledPath('options.compiled.json', $version);
            throw new \RuntimeException(
                'EQ_60 compiled option anchors are empty at ' . $path
                    . '. Run: php artisan content:compile --pack=EQ_60 --pack-version=' . $this->normalizeVersion($version)
            );
        }

        return $normalized;
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

        $path = $this->compiledPath('policy.compiled.json', $version);
        throw new \RuntimeException(
            'EQ_60 compiled policy missing at ' . $path
                . '. Run: php artisan content:compile --pack=EQ_60 --pack-version=' . $this->normalizeVersion($version)
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function loadLanding(?string $version = null): array
    {
        $compiled = $this->requireCompiledJson('landing.compiled.json', $version);
        $landing = $compiled['landing'] ?? null;
        if (is_array($landing)) {
            return $landing;
        }

        $path = $this->compiledPath('landing.compiled.json', $version);
        throw new \RuntimeException(
            'EQ_60 compiled landing missing at ' . $path
                . '. Run: php artisan content:compile --pack=EQ_60 --pack-version=' . $this->normalizeVersion($version)
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function loadGoldenCases(?string $version = null): array
    {
        $compiled = $this->requireCompiledJson('golden_cases.compiled.json', $version);
        if (is_array($compiled)) {
            $cases = $compiled['cases'] ?? null;
            if (is_array($cases)) {
                return array_values(array_filter($cases, static fn ($row): bool => is_array($row)));
            }
        }

        return [];
    }

    public function resolveManifestHash(?string $version = null): string
    {
        $manifest = $this->readCompiledJson('manifest.json', $version);
        if (!is_array($manifest)) {
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
            $rows[] = trim((string) $name) . ':' . trim((string) $value);
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
        $version = $this->normalizeVersion($version);
        $path = $this->compiledPath($file, $version);
        $decoded = $this->readJson($path);
        if (is_array($decoded)) {
            return $decoded;
        }

        throw new \RuntimeException(
            'EQ_60 compiled content missing: ' . $path
                . '. Run: php artisan content:compile --pack=EQ_60 --pack-version=' . $version
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function readJson(string $path): ?array
    {
        if (!is_file($path)) {
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
     * @return list<array{line:int,row:array<string,string>}>
     */
    public function readCsvWithLines(string $path): array
    {
        if (!is_file($path)) {
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

            if (!is_array($row) || $header === [] || $row === [null]) {
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
}
