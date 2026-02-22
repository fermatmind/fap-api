<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Support\Facades\File;

final class ClinicalComboPackLoader
{
    public const PACK_ID = 'CLINICAL_COMBO_68';
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

    public function hasCompiledFile(string $file, ?string $version = null): bool
    {
        return is_file($this->compiledPath($file, $version));
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
     * @return array{locale_requested:string,locale_resolved:string,items:list<array<string,mixed>>,modules:array<string,mixed>,disclaimer_text:string}
     */
    public function loadQuestionsDoc(string $locale, ?string $version = null): array
    {
        $version = $this->normalizeVersion($version);
        $localeResolved = $this->normalizeLocale($locale);

        $compiled = $this->readCompiledJson('questions.compiled.json', $version);
        $compiledOptions = $this->readCompiledJson('options_sets.compiled.json', $version);
        $compiledLanding = $this->readCompiledJson('landing.compiled.json', $version);

        if (is_array($compiled) && is_array($compiledOptions)) {
            $questionsByLocale = is_array($compiled['questions_doc_by_locale'] ?? null)
                ? $compiled['questions_doc_by_locale']
                : [];
            $doc = $questionsByLocale[$localeResolved] ?? ($questionsByLocale['zh-CN'] ?? null);
            if (is_array($doc)) {
                $resolved = isset($questionsByLocale[$localeResolved]) ? $localeResolved : 'zh-CN';
                $landing = is_array($compiledLanding['landing'] ?? null) ? $compiledLanding['landing'] : [];
                return [
                    'locale_requested' => $locale,
                    'locale_resolved' => $resolved,
                    'items' => is_array($doc['items'] ?? null) ? $doc['items'] : [],
                    'modules' => $this->resolveModules($landing, $resolved),
                    'disclaimer_text' => $this->resolveDisclaimerText($landing, $resolved),
                ];
            }
        }

        $questionRows = $this->readCsvWithLines($this->rawPath($localeResolved === 'zh-CN' ? 'questions_zh.csv' : 'questions_en.csv', $version));
        if ($questionRows === [] && $localeResolved !== 'zh-CN') {
            $localeResolved = 'zh-CN';
            $questionRows = $this->readCsvWithLines($this->rawPath('questions_zh.csv', $version));
        }

        $optionSets = $this->loadOptionSetsForLocale($localeResolved, $version);
        $items = [];

        foreach ($questionRows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid <= 0) {
                continue;
            }

            $setCode = trim((string) ($row['options_set_code'] ?? ''));
            $set = is_array($optionSets[$setCode] ?? null) ? $optionSets[$setCode] : [];
            $labels = $localeResolved === 'zh-CN'
                ? (is_array($set['labels_zh'] ?? null) ? $set['labels_zh'] : [])
                : (is_array($set['labels_en'] ?? null) ? $set['labels_en'] : []);

            $textField = $localeResolved === 'zh-CN' ? 'text_zh' : 'text_en';
            $text = trim((string) ($row[$textField] ?? ''));

            $items[] = [
                'question_id' => (string) $qid,
                'order' => $qid,
                'module_code' => trim((string) ($row['module_code'] ?? '')),
                'options_set_code' => $setCode,
                'is_reverse' => (int) ($row['is_reverse'] ?? 0),
                'text' => $text,
                'options' => $this->buildOptionsFromLabels($labels, $set),
            ];
        }

        usort($items, static fn (array $a, array $b): int => ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0)));

        $landing = $this->readJson($this->rawPath('landing_i18n.json', $version)) ?? [];

        return [
            'locale_requested' => $locale,
            'locale_resolved' => $localeResolved,
            'items' => $items,
            'modules' => $this->resolveModules($landing, $localeResolved),
            'disclaimer_text' => $this->resolveDisclaimerText($landing, $localeResolved),
        ];
    }

    /**
     * @return array<int,array{module_code:string,options_set_code:string,is_reverse:bool}>
     */
    public function loadQuestionIndex(?string $version = null): array
    {
        $version = $this->normalizeVersion($version);

        $compiled = $this->readCompiledJson('questions.compiled.json', $version);
        if (is_array($compiled)) {
            $index = is_array($compiled['question_index'] ?? null) ? $compiled['question_index'] : [];
            $out = [];
            foreach ($index as $qidRaw => $meta) {
                $qid = (int) $qidRaw;
                if ($qid <= 0 || !is_array($meta)) {
                    continue;
                }
                $out[$qid] = [
                    'module_code' => trim((string) ($meta['module_code'] ?? '')),
                    'options_set_code' => trim((string) ($meta['options_set_code'] ?? '')),
                    'is_reverse' => (bool) ($meta['is_reverse'] ?? false),
                ];
            }
            if ($out !== []) {
                ksort($out, SORT_NUMERIC);
                return $out;
            }
        }

        $rows = $this->readCsvWithLines($this->rawPath('questions_zh.csv', $version));
        $out = [];
        foreach ($rows as $entry) {
            $row = (array) ($entry['row'] ?? []);
            $qid = (int) ($row['question_id'] ?? 0);
            if ($qid <= 0) {
                continue;
            }
            $out[$qid] = [
                'module_code' => trim((string) ($row['module_code'] ?? '')),
                'options_set_code' => trim((string) ($row['options_set_code'] ?? '')),
                'is_reverse' => (int) ($row['is_reverse'] ?? 0) === 1,
            ];
        }

        ksort($out, SORT_NUMERIC);

        return $out;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function loadOptionSets(?string $version = null): array
    {
        $version = $this->normalizeVersion($version);

        $compiled = $this->readCompiledJson('options_sets.compiled.json', $version);
        if (is_array($compiled)) {
            $sets = is_array($compiled['sets'] ?? null) ? $compiled['sets'] : [];
            if ($sets !== []) {
                return $sets;
            }
        }

        $zh = $this->readJson($this->rawPath('options_sets_zh.json', $version));
        $en = $this->readJson($this->rawPath('options_sets_en.json', $version));

        $zhSets = is_array($zh['sets'] ?? null) ? $zh['sets'] : [];
        $enSets = is_array($en['sets'] ?? null) ? $en['sets'] : [];

        $allCodes = array_values(array_unique(array_merge(array_keys($zhSets), array_keys($enSets))));
        $out = [];
        foreach ($allCodes as $code) {
            $zhNode = is_array($zhSets[$code] ?? null) ? $zhSets[$code] : [];
            $enNode = is_array($enSets[$code] ?? null) ? $enSets[$code] : [];
            $out[$code] = [
                'scoring' => is_array($zhNode['scoring'] ?? null)
                    ? $zhNode['scoring']
                    : (is_array($enNode['scoring'] ?? null) ? $enNode['scoring'] : []),
                'labels_zh' => is_array($zhNode['labels_zh'] ?? null) ? $zhNode['labels_zh'] : [],
                'labels_en' => is_array($enNode['labels_en'] ?? null) ? $enNode['labels_en'] : [],
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

        $compiled = $this->readCompiledJson('policy.compiled.json', $version);
        if (is_array($compiled)) {
            $policy = $compiled['policy'] ?? null;
            if (is_array($policy)) {
                return $policy;
            }
        }

        return $this->readJson($this->rawPath('policy.json', $version)) ?? [];
    }

    /**
     * @return array<string,mixed>
     */
    public function loadLanding(?string $version = null): array
    {
        $version = $this->normalizeVersion($version);

        $compiled = $this->readCompiledJson('landing.compiled.json', $version);
        if (is_array($compiled)) {
            $landing = $compiled['landing'] ?? null;
            if (is_array($landing)) {
                return $landing;
            }
        }

        return $this->readJson($this->rawPath('landing_i18n.json', $version)) ?? [];
    }

    /**
     * @return array<string,mixed>
     */
    public function loadLayout(?string $version = null): array
    {
        $version = $this->normalizeVersion($version);

        $compiled = $this->readCompiledJson('layout.compiled.json', $version);
        if (is_array($compiled)) {
            $layout = $compiled['layout'] ?? null;
            if (is_array($layout)) {
                return $layout;
            }
        }

        $raw = $this->readJson($this->rawPath('report_layout.json', $version)) ?? [];

        return is_array($raw['layout'] ?? null) ? $raw['layout'] : $raw;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function loadBlocks(string $locale, ?string $version = null): array
    {
        $version = $this->normalizeVersion($version);
        $localeResolved = $this->normalizeLocale($locale);

        $compiled = $this->readCompiledJson('blocks.compiled.json', $version);
        if (is_array($compiled)) {
            $byLocale = is_array($compiled['blocks_by_locale'] ?? null) ? $compiled['blocks_by_locale'] : [];
            $blocks = $byLocale[$localeResolved] ?? ($byLocale['zh-CN'] ?? null);
            if (is_array($blocks)) {
                return array_values(array_filter($blocks, static fn ($b): bool => is_array($b)));
            }
        }

        $unifiedFiles = ['blocks/free_blocks.json', 'blocks/paid_blocks.json'];
        $hasUnified = false;
        $unifiedBlocks = [];
        foreach ($unifiedFiles as $file) {
            $path = $this->rawPath($file, $version);
            if (!is_file($path)) {
                continue;
            }
            $hasUnified = true;
            $doc = $this->readJson($path);
            $rows = is_array($doc['blocks'] ?? null) ? $doc['blocks'] : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $normalized = $this->normalizeBlockRow($row);
                if ($normalized === null) {
                    continue;
                }
                $unifiedBlocks[] = $normalized;
            }
        }

        if ($hasUnified) {
            $localized = array_values(array_filter($unifiedBlocks, fn (array $row): bool => $this->normalizeLocale((string) ($row['locale'] ?? '')) === $localeResolved));
            if ($localized === [] && $localeResolved !== 'zh-CN') {
                $localized = array_values(array_filter($unifiedBlocks, fn (array $row): bool => $this->normalizeLocale((string) ($row['locale'] ?? '')) === 'zh-CN'));
            }

            return $localized;
        }

        $legacyFiles = $localeResolved === 'zh-CN'
            ? ['blocks/free_blocks.zh.json', 'blocks/paid_blocks.zh.json']
            : ['blocks/free_blocks.en.json', 'blocks/paid_blocks.en.json'];

        $out = [];
        foreach ($legacyFiles as $file) {
            $doc = $this->readJson($this->rawPath($file, $version));
            $blocks = is_array($doc['blocks'] ?? null) ? $doc['blocks'] : [];
            foreach ($blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $normalized = $this->normalizeBlockRow($block);
                if ($normalized !== null) {
                    $out[] = $normalized;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    public function loadConsent(string $locale, ?string $version = null): array
    {
        $version = $this->normalizeVersion($version);
        $requested = trim($locale) !== '' ? $locale : 'zh-CN';
        $resolved = $this->normalizeLocale($requested);

        $compiled = $this->readCompiledJson('consent.compiled.json', $version);
        if (is_array($compiled)) {
            $byLocale = is_array($compiled['consent_by_locale'] ?? null) ? $compiled['consent_by_locale'] : [];
            $node = is_array($byLocale[$resolved] ?? null) ? $byLocale[$resolved] : (is_array($byLocale['zh-CN'] ?? null) ? $byLocale['zh-CN'] : []);
            if ($node !== []) {
                return $node + [
                    'locale_requested' => $requested,
                    'locale_resolved' => $resolved,
                ];
            }
        }

        $raw = $this->readJson($this->rawPath('consent_i18n.json', $version)) ?? [];

        return $this->localizeConsentDoc($raw, $requested, $resolved);
    }

    /**
     * @return array<string,mixed>
     */
    public function loadPrivacyAddendum(string $locale, ?string $version = null): array
    {
        $version = $this->normalizeVersion($version);
        $requested = trim($locale) !== '' ? $locale : 'zh-CN';
        $resolved = $this->normalizeLocale($requested);

        $compiled = $this->readCompiledJson('privacy_addendum.compiled.json', $version);
        if (is_array($compiled)) {
            $byLocale = is_array($compiled['privacy_addendum_by_locale'] ?? null) ? $compiled['privacy_addendum_by_locale'] : [];
            $node = is_array($byLocale[$resolved] ?? null) ? $byLocale[$resolved] : (is_array($byLocale['zh-CN'] ?? null) ? $byLocale['zh-CN'] : []);
            if ($node !== []) {
                return $node + [
                    'locale_requested' => $requested,
                    'locale_resolved' => $resolved,
                ];
            }
        }

        $raw = $this->readJson($this->rawPath('privacy_addendum_i18n.json', $version)) ?? [];

        return $this->localizePrivacyDoc($raw, $requested, $resolved);
    }

    /**
     * @return array<string,mixed>
     */
    public function loadCrisisResources(string $locale, string $region, ?string $version = null): array
    {
        $version = $this->normalizeVersion($version);
        $requestedLocale = trim($locale) !== '' ? $locale : 'zh-CN';
        $resolvedLocale = $this->normalizeLocale($requestedLocale);
        $requestedRegion = strtoupper(trim($region));

        $compiled = $this->readCompiledJson('crisis_resources.compiled.json', $version);
        if (is_array($compiled)) {
            $doc = is_array($compiled['crisis_resources'] ?? null) ? $compiled['crisis_resources'] : [];
            if ($doc !== []) {
                return $this->resolveCrisisResources($doc, $requestedLocale, $resolvedLocale, $requestedRegion);
            }
        }

        $raw = $this->readJson($this->rawPath('crisis_resources.json', $version)) ?? [];

        return $this->resolveCrisisResources($raw, $requestedLocale, $resolvedLocale, $requestedRegion);
    }

    public function resolveManifestHash(?string $version = null): string
    {
        $version = $this->normalizeVersion($version);
        $manifest = $this->readCompiledJson('manifest.json', $version);
        if (!is_array($manifest)) {
            return '';
        }

        $hash = trim((string) ($manifest['compiled_hash'] ?? ''));
        if ($hash !== '') {
            return $hash;
        }

        return trim((string) ($manifest['content_hash'] ?? ''));
    }

    public function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));
        if (str_starts_with($locale, 'zh')) {
            return 'zh-CN';
        }
        if (str_starts_with($locale, 'en')) {
            return 'en';
        }

        return 'zh-CN';
    }

    private function normalizeVersion(?string $version): string
    {
        $version = trim((string) $version);

        return $version !== '' ? $version : self::PACK_VERSION;
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function loadOptionSetsForLocale(string $locale, string $version): array
    {
        $sets = $this->loadOptionSets($version);

        if ($locale === 'zh-CN') {
            return $sets;
        }

        foreach ($sets as $code => $set) {
            if (!is_array($set)) {
                continue;
            }
            $labels = is_array($set['labels_en'] ?? null) ? $set['labels_en'] : [];
            $sets[$code]['labels_en'] = $labels;
        }

        return $sets;
    }

    /**
     * @param array<string,string> $labels
     * @param array<string,mixed> $set
     * @return list<array{code:string,text:string,score:int}>
     */
    private function buildOptionsFromLabels(array $labels, array $set): array
    {
        $scoring = is_array($set['scoring'] ?? null) ? $set['scoring'] : [];
        $out = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $code) {
            $out[] = [
                'code' => $code,
                'text' => trim((string) ($labels[$code] ?? '')),
                'score' => (int) ($scoring[$code] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $landing
     * @return array<string,mixed>
     */
    private function resolveModules(array $landing, string $locale): array
    {
        $modules = is_array($landing['modules'] ?? null) ? $landing['modules'] : [];
        $node = is_array($modules[$locale] ?? null) ? $modules[$locale] : [];

        return $node;
    }

    /**
     * @param array<string,mixed> $landing
     */
    private function resolveDisclaimerText(array $landing, string $locale): string
    {
        $disclaimer = is_array($landing['disclaimer'] ?? null) ? $landing['disclaimer'] : [];
        $text = trim((string) ($disclaimer[$locale] ?? ''));

        if ($text === '') {
            $text = trim((string) ($disclaimer['zh-CN'] ?? ''));
        }

        return $text;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function localizeConsentDoc(array $raw, string $requested, string $resolved): array
    {
        $titleMap = is_array($raw['title'] ?? null) ? $raw['title'] : [];
        $checkboxMap = is_array($raw['checkboxes'] ?? null) ? $raw['checkboxes'] : [];
        $primaryMap = is_array($raw['primary_button'] ?? null) ? $raw['primary_button'] : [];
        $secondaryMap = is_array($raw['secondary_button'] ?? null) ? $raw['secondary_button'] : [];

        $checkboxes = is_array($checkboxMap[$resolved] ?? null) ? $checkboxMap[$resolved] : [];
        if ($checkboxes === []) {
            $checkboxes = is_array($checkboxMap['zh-CN'] ?? null) ? $checkboxMap['zh-CN'] : [];
        }

        return [
            'version' => (string) ($raw['version'] ?? ''),
            'locale_requested' => $requested,
            'locale_resolved' => $resolved,
            'title' => (string) ($titleMap[$resolved] ?? ($titleMap['zh-CN'] ?? '')),
            'checkboxes' => array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $checkboxes), static fn (string $v): bool => $v !== '')),
            'primary_button' => (string) ($primaryMap[$resolved] ?? ($primaryMap['zh-CN'] ?? '')),
            'secondary_button' => (string) ($secondaryMap[$resolved] ?? ($secondaryMap['zh-CN'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function localizePrivacyDoc(array $raw, string $requested, string $resolved): array
    {
        $titleMap = is_array($raw['title'] ?? null) ? $raw['title'] : [];
        $bulletsMap = is_array($raw['bullets'] ?? null) ? $raw['bullets'] : [];

        $bullets = is_array($bulletsMap[$resolved] ?? null) ? $bulletsMap[$resolved] : [];
        if ($bullets === []) {
            $bullets = is_array($bulletsMap['zh-CN'] ?? null) ? $bulletsMap['zh-CN'] : [];
        }

        return [
            'version' => (string) ($raw['version'] ?? ''),
            'locale_requested' => $requested,
            'locale_resolved' => $resolved,
            'title' => (string) ($titleMap[$resolved] ?? ($titleMap['zh-CN'] ?? '')),
            'bullets' => array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $bullets), static fn (string $v): bool => $v !== '')),
        ];
    }

    /**
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private function resolveCrisisResources(array $doc, string $requestedLocale, string $resolvedLocale, string $requestedRegion): array
    {
        $defaultRegion = strtoupper(trim((string) ($doc['default_region'] ?? 'CN_MAINLAND')));
        $locales = is_array($doc['locales'] ?? null) ? $doc['locales'] : [];
        $localeNode = is_array($locales[$resolvedLocale] ?? null) ? $locales[$resolvedLocale] : [];
        if ($localeNode === []) {
            $resolvedLocale = 'zh-CN';
            $localeNode = is_array($locales[$resolvedLocale] ?? null) ? $locales[$resolvedLocale] : [];
        }

        $regionCandidates = [];
        if ($requestedRegion !== '') {
            $regionCandidates[] = $requestedRegion;
        }
        $regionCandidates[] = $defaultRegion;
        $regionCandidates[] = 'GLOBAL';
        $regionCandidates = array_values(array_unique($regionCandidates));

        $resolvedRegion = $defaultRegion;
        $rows = [];
        foreach ($regionCandidates as $regionCode) {
            $candidateRows = is_array($localeNode[$regionCode] ?? null) ? $localeNode[$regionCode] : [];
            if ($candidateRows !== []) {
                $rows = $candidateRows;
                $resolvedRegion = $regionCode;
                break;
            }
        }

        $resources = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $resources[] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'phone' => trim((string) ($row['phone'] ?? '')),
                'note' => trim((string) ($row['note'] ?? '')),
                'url' => trim((string) ($row['url'] ?? '')),
            ];
        }

        return [
            'version' => (string) ($doc['version'] ?? ''),
            'locale_requested' => $requestedLocale,
            'locale_resolved' => $resolvedLocale,
            'region_requested' => $requestedRegion,
            'region_resolved' => $resolvedRegion,
            'resources' => $resources,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function normalizeBlockRow(array $row): ?array
    {
        $blockId = trim((string) ($row['block_id'] ?? $row['id'] ?? ''));
        if ($blockId === '') {
            return null;
        }

        $section = trim((string) ($row['section'] ?? ''));
        if ($section === '') {
            return null;
        }

        $bodyMd = trim((string) ($row['body_md'] ?? $row['body'] ?? ''));
        $normalized = $row;
        $normalized['block_id'] = $blockId;
        $normalized['id'] = $blockId;
        $normalized['section'] = $section;
        $normalized['locale'] = $this->normalizeLocale((string) ($row['locale'] ?? 'zh-CN'));
        $normalized['access_level'] = strtolower(trim((string) ($row['access_level'] ?? 'free')));
        $normalized['priority'] = (int) ($row['priority'] ?? 0);
        $normalized['exclusive_group'] = trim((string) ($row['exclusive_group'] ?? ''));
        $normalized['title'] = trim((string) ($row['title'] ?? ''));
        $normalized['body_md'] = $bodyMd;
        $normalized['body'] = $bodyMd;
        $normalized['conditions'] = is_array($row['conditions'] ?? null) ? $row['conditions'] : [];

        return $normalized;
    }
}
