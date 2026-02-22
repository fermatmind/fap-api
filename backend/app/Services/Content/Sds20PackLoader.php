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
        return base_path('content_packs/' . self::PACK_ID . '/' . $this->normalizeVersion($version));
    }

    public function rawDir(?string $version = null): string
    {
        return $this->packRoot($version) . DIRECTORY_SEPARATOR . 'raw';
    }

    public function compiledDir(?string $version = null): string
    {
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
        $version = $this->normalizeVersion($version);
        $rows = $this->readCsvWithLines($this->rawPath('questions_sds20_bilingual.csv', $version));

        $out = [];
        foreach ($rows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $qid = (int) ($row['question_id'] ?? 0);
            $direction = (int) ($row['direction'] ?? 0);
            if ($qid <= 0 || !in_array($direction, [1, -1], true)) {
                continue;
            }

            $out[$qid] = [
                'direction' => $direction,
            ];
        }

        ksort($out, SORT_NUMERIC);

        return $out;
    }

    /**
     * @return array{locale_requested:string,locale_resolved:string,items:list<array<string,mixed>>}
     */
    public function loadQuestionsDoc(string $locale, ?string $version = null): array
    {
        $version = $this->normalizeVersion($version);
        $localeResolved = $this->normalizeLocale($locale);
        $rows = $this->readCsvWithLines($this->rawPath('questions_sds20_bilingual.csv', $version));

        $items = [];
        foreach ($rows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $qid = (int) ($row['question_id'] ?? 0);
            $direction = (int) ($row['direction'] ?? 0);
            if ($qid <= 0 || !in_array($direction, [1, -1], true)) {
                continue;
            }

            $text = $localeResolved === 'zh-CN'
                ? trim((string) ($row['text_zh'] ?? ''))
                : trim((string) ($row['text_en'] ?? ''));
            if ($text === '') {
                continue;
            }

            $items[] = [
                'question_id' => (string) $qid,
                'order' => $qid,
                'direction' => $direction,
                'text' => $text,
            ];
        }

        usort($items, static fn (array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));

        return [
            'locale_requested' => $locale,
            'locale_resolved' => $localeResolved,
            'items' => $items,
        ];
    }

    /**
     * @return list<string>
     */
    public function loadOptionsFormat(string $locale, ?string $version = null): array
    {
        $version = $this->normalizeVersion($version);
        $localeResolved = $this->normalizeLocale($locale);
        $doc = $this->readJson($this->rawPath('options_sds20_bilingual.json', $version));
        if (!is_array($doc)) {
            return [];
        }

        $labels = is_array($doc['labels'] ?? null) ? $doc['labels'] : [];
        $format = $labels[$localeResolved] ?? ($labels['zh-CN'] ?? []);

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
        $version = $this->normalizeVersion($version);
        $localeResolved = $this->normalizeLocale($locale);
        $doc = $this->readJson($this->rawPath('landing_i18n.json', $version));
        if (!is_array($doc)) {
            return [
                'locale_resolved' => $localeResolved,
                'consent' => [
                    'required' => true,
                    'version' => '',
                    'text' => '',
                ],
                'disclaimer' => [
                    'version' => '',
                    'hash' => '',
                    'text' => '',
                ],
                'title' => '',
                'crisis_hotline' => '',
            ];
        }

        $node = is_array($doc[$localeResolved] ?? null)
            ? $doc[$localeResolved]
            : (is_array($doc['zh-CN'] ?? null) ? $doc['zh-CN'] : []);

        $consent = is_array($node['consent'] ?? null) ? $node['consent'] : [];
        $disclaimer = is_array($node['disclaimer'] ?? null) ? $node['disclaimer'] : [];

        $disclaimerVersion = trim((string) ($disclaimer['version'] ?? ''));
        if ($disclaimerVersion === '') {
            $disclaimerVersion = 'SDS_20_disclaimer_' . $version;
        }

        $disclaimerText = trim((string) ($disclaimer['text'] ?? ''));

        return [
            'locale_resolved' => $localeResolved,
            'title' => trim((string) ($node['title'] ?? '')),
            'consent' => [
                'required' => (bool) ($consent['required'] ?? true),
                'version' => trim((string) ($consent['version'] ?? '')),
                'text' => trim((string) ($consent['text'] ?? '')),
            ],
            'disclaimer' => [
                'version' => $disclaimerVersion,
                'text' => $disclaimerText,
                'hash' => hash('sha256', $disclaimerVersion . '|' . $disclaimerText),
            ],
            'crisis_hotline' => trim((string) ($node['crisis_hotline'] ?? '')),
        ];
    }

    /**
     * @return list<array<string,string>>
     */
    public function loadSourceCatalog(?string $version = null): array
    {
        $version = $this->normalizeVersion($version);
        $rows = $this->readCsvWithLines($this->rawPath('source_catalog.csv', $version));

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
     * @return array<string,mixed>
     */
    public function loadPolicy(?string $version = null): array
    {
        $version = $this->normalizeVersion($version);

        return $this->readJson($this->rawPath('policy.json', $version)) ?? [];
    }

    /**
     * @return array<string,mixed>
     */
    public function loadReportLayout(?string $version = null): array
    {
        $version = $this->normalizeVersion($version);
        $compiled = $this->readJson($this->compiledPath('report.compiled.json', $version));
        if (is_array($compiled) && is_array($compiled['layout'] ?? null)) {
            return $compiled['layout'];
        }

        $raw = $this->readJson($this->rawPath('report_layout.json', $version)) ?? [];
        if (is_array($raw['layout'] ?? null)) {
            return $raw['layout'];
        }

        return is_array($raw) ? $raw : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function loadReportBlocks(string $locale, ?string $version = null): array
    {
        $version = $this->normalizeVersion($version);
        $localeResolved = $this->normalizeLocale($locale);

        $compiled = $this->readJson($this->compiledPath('report.compiled.json', $version));
        if (is_array($compiled)) {
            $blocksByLocale = is_array($compiled['blocks_by_locale'] ?? null) ? $compiled['blocks_by_locale'] : [];
            $blocks = $blocksByLocale[$localeResolved] ?? ($blocksByLocale['zh-CN'] ?? null);
            if (is_array($blocks)) {
                return array_values(array_filter($blocks, static fn ($row): bool => is_array($row)));
            }
        }

        $freeDoc = $this->readJson($this->rawPath('blocks/free_blocks.json', $version)) ?? [];
        $paidDoc = $this->readJson($this->rawPath('blocks/paid_blocks.json', $version)) ?? [];

        $free = $this->normalizeReportBlockDoc($freeDoc, $localeResolved, 'free');
        $paid = $this->normalizeReportBlockDoc($paidDoc, $localeResolved, 'paid');

        return array_values(array_merge($free, $paid));
    }

    public function resolveManifestHash(?string $version = null): string
    {
        $version = $this->normalizeVersion($version);
        $manifest = $this->readJson($this->compiledPath('manifest.json', $version));
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
     * @param array<string,mixed> $doc
     * @return list<array<string,mixed>>
     */
    private function normalizeReportBlockDoc(array $doc, string $locale, string $accessLevel): array
    {
        $source = $doc[$locale] ?? ($doc['zh-CN'] ?? []);
        if (!is_array($source)) {
            return [];
        }

        $out = [];
        foreach ($source as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sectionKey = trim((string) ($row['section_key'] ?? ''));
            if ($sectionKey === '') {
                continue;
            }

            $out[] = [
                'block_id' => (string) ($row['block_id'] ?? ''),
                'section_key' => $sectionKey,
                'title' => (string) ($row['title'] ?? ''),
                'body_md' => (string) ($row['body_md'] ?? ''),
                'access_level' => $accessLevel,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{line:int,row:array<string,string>}>
     */
    private function readCsvWithLines(string $path): array
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

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $path): ?array
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
}
