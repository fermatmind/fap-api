<?php

declare(strict_types=1);

namespace App\Console\Commands;

final class CareerDisplayAssetPublishGate
{
    public const VERSION = 'career_display_asset_publish_gate_v0.1';

    /**
     * @param  list<string>  $componentOrder
     * @param  array<string, mixed>  $pagePayload
     * @param  array<string, mixed>  $structuredData
     * @param  array<string, mixed>  $metadata
     * @return array{
     *   validator_version: string,
     *   decision: 'pass'|'fail',
     *   errors: list<string>,
     *   module_parity: array<string, mixed>,
     *   source_page_type_valid: bool,
     *   product_schema_absent: bool,
     *   reviewed_chinese_valid: bool
     * }
     */
    public function validatePayload(
        string $slug,
        array $componentOrder,
        array $pagePayload,
        array $structuredData,
        array $metadata = [],
        bool $requireReviewedChinese = true,
    ): array {
        $errors = [];
        $componentOrder = $this->normalizeComponentOrder($componentOrder);
        $pages = $this->localizedPages($pagePayload);
        $en = is_array($pages['en'] ?? null) ? $pages['en'] : null;
        $zh = is_array($pages['zh'] ?? null) ? $pages['zh'] : null;

        if ($componentOrder === []) {
            $errors[] = 'component_order_json must contain a non-empty ordered module list.';
        }
        if ($en === null) {
            $errors[] = 'page_payload_json.page.en must be present before publishing.';
        }
        if ($zh === null) {
            $errors[] = 'page_payload_json.page.zh must be present before publishing.';
        }

        $moduleParity = $this->moduleParity($componentOrder, $en, $zh);
        foreach ($moduleParity['errors'] as $error) {
            $errors[] = $error;
        }

        $sourcePageTypeValid = $this->sourcePageTypeValid($en, $zh);
        if (! $sourcePageTypeValid) {
            $errors[] = 'primary_cta/final_cta source_page_type must be career_job_detail for both en and zh.';
        }

        $productSchemaAbsent = ! $this->containsProductSchema([
            'page_payload_json' => $pagePayload,
            'structured_data_json' => $structuredData,
        ]);
        if (! $productSchemaAbsent) {
            $errors[] = 'Product schema is forbidden in career job display assets.';
        }

        $reviewedChineseValid = $this->reviewedChineseValid($en, $zh);
        if ($requireReviewedChinese && ! $reviewedChineseValid) {
            $errors[] = 'Chinese hero title/H1 must contain reviewed Chinese text and must not be copied from English.';
        }

        if ($this->hasNotReadyFlag($metadata)) {
            $errors[] = 'metadata_json locale/release readiness flags indicate this asset is not publishable.';
        }

        return [
            'validator_version' => self::VERSION,
            'decision' => $errors === [] ? 'pass' : 'fail',
            'errors' => array_values(array_unique($errors)),
            'module_parity' => $moduleParity,
            'source_page_type_valid' => $sourcePageTypeValid,
            'product_schema_absent' => $productSchemaAbsent,
            'reviewed_chinese_valid' => $reviewedChineseValid,
        ];
    }

    /**
     * @param  list<string>  $componentOrder
     * @param  array<string, mixed>|null  $en
     * @param  array<string, mixed>|null  $zh
     * @return array{en_missing: list<string>, zh_missing: list<string>, asymmetric_modules: list<string>, errors: list<string>}
     */
    private function moduleParity(array $componentOrder, ?array $en, ?array $zh): array
    {
        if ($componentOrder === [] || $en === null || $zh === null) {
            return [
                'en_missing' => [],
                'zh_missing' => [],
                'asymmetric_modules' => [],
                'errors' => [],
            ];
        }

        $enKeys = array_keys($en);
        $zhKeys = array_keys($zh);
        $enMissing = array_values(array_diff($componentOrder, $enKeys));
        $zhMissing = array_values(array_diff($componentOrder, $zhKeys));
        $asymmetric = array_values(array_unique(array_merge(
            array_diff($enKeys, $zhKeys),
            array_diff($zhKeys, $enKeys),
        )));

        $errors = [];
        if ($enMissing !== []) {
            $errors[] = 'en page is missing component_order modules: '.implode(', ', $enMissing).'.';
        }
        if ($zhMissing !== []) {
            $errors[] = 'zh page is missing component_order modules: '.implode(', ', $zhMissing).'.';
        }
        if ($asymmetric !== []) {
            $errors[] = 'en/zh page module keys must be symmetric before publishing: '.implode(', ', $asymmetric).'.';
        }

        return [
            'en_missing' => $enMissing,
            'zh_missing' => $zhMissing,
            'asymmetric_modules' => $asymmetric,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<string>  $componentOrder
     * @return list<string>
     */
    private function normalizeComponentOrder(array $componentOrder): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $module): string => is_string($module) ? trim($module) : '',
            $componentOrder,
        ), static fn (string $module): bool => $module !== '')));
    }

    /**
     * @param  array<string, mixed>  $pagePayload
     * @return array<string, mixed>
     */
    private function localizedPages(array $pagePayload): array
    {
        $pages = $pagePayload['page'] ?? $pagePayload;

        return is_array($pages) ? $pages : [];
    }

    /**
     * @param  array<string, mixed>|null  $en
     * @param  array<string, mixed>|null  $zh
     */
    private function sourcePageTypeValid(?array $en, ?array $zh): bool
    {
        if ($en === null || $zh === null) {
            return false;
        }

        foreach ([$en, $zh] as $page) {
            foreach (['primary_cta', 'final_cta'] as $key) {
                $cta = $page[$key] ?? null;
                if (! is_array($cta) || (string) ($cta['source_page_type'] ?? '') !== 'career_job_detail') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $en
     * @param  array<string, mixed>|null  $zh
     */
    private function reviewedChineseValid(?array $en, ?array $zh): bool
    {
        if ($en === null || $zh === null) {
            return false;
        }

        $zhTitle = $this->firstText(data_get($zh, 'hero.h1'), data_get($zh, 'hero.title'));
        $enTitle = $this->firstText(data_get($en, 'hero.h1'), data_get($en, 'hero.title'));

        return $zhTitle !== null
            && $this->containsCjk($zhTitle)
            && $this->normalizedText($zhTitle) !== $this->normalizedText((string) $enTitle);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasNotReadyFlag(array $payload): bool
    {
        foreach ([
            'locale_readiness.zh-CN',
            'locale_readiness.zh',
            'locales.zh-CN',
            'locales.zh',
            'release_gates.zh-CN',
            'release_gates.zh',
        ] as $path) {
            $value = data_get($payload, $path);
            if ($value === false) {
                return true;
            }
            if (is_array($value) && in_array(strtolower((string) ($value['status'] ?? $value['state'] ?? '')), ['not_ready', 'held', 'hold', 'blocked'], true)) {
                return true;
            }
            if (is_string($value) && in_array(strtolower(trim($value)), ['not_ready', 'held', 'hold', 'blocked'], true)) {
                return true;
            }
        }

        return false;
    }

    private function containsProductSchema(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        if (($value['@type'] ?? null) === 'Product') {
            return true;
        }
        foreach ($value as $child) {
            if ($this->containsProductSchema($child)) {
                return true;
            }
        }

        return false;
    }

    private function firstText(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    private function containsCjk(string $value): bool
    {
        return preg_match('/\p{Han}/u', $value) === 1;
    }

    private function normalizedText(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
