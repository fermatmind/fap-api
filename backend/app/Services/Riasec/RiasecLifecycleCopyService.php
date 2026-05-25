<?php

declare(strict_types=1);

namespace App\Services\Riasec;

final class RiasecLifecycleCopyService
{
    private const DEFAULT_LOCALE = 'zh-CN';

    private const SUPPORTED_LOCALES = [
        'en',
        'zh-CN',
    ];

    private const SHARE_PDF_HISTORY_ASSET_PATH_TEMPLATE = 'content_assets/riasec/share_pdf_history_v1.%s.json';

    private const FAQ_JSON_ASSET_PATH_TEMPLATE = 'content_assets/riasec/faq_v1.%s.json';

    private const FAQ_MARKDOWN_ASSET_PATH_TEMPLATE = 'content_assets/riasec/faq_v1.%s.md';

    private const TECHNICAL_NOTE_SUMMARY_ASSET_PATH_TEMPLATE = 'content_assets/riasec/technical_note_user_summary_v1.%s.json';

    private const PROFESSIONAL_METHOD_BOUNDARY_ASSET_PATH_TEMPLATE = 'content_assets/riasec/professional_method_boundary_v1.%s.json';

    /** @var array<string,array<string,mixed>> */
    private array $sharePdfHistoryCache = [];

    /** @var array<string,array<string,mixed>> */
    private array $faqJsonCache = [];

    /** @var array<string,array<string,mixed>> */
    private array $technicalNoteSummaryCache = [];

    /** @var array<string,array<string,mixed>> */
    private array $professionalMethodBoundaryCache = [];

    /**
     * @return list<array{title:string,copy:string}>
     */
    public function technicalNoteSummarySections(string $locale = self::DEFAULT_LOCALE): array
    {
        $asset = $this->technicalNoteSummaryAsset($this->normalizeLocale($locale));
        $rows = is_array($asset['summary_sections'] ?? null) ? $asset['summary_sections'] : [];
        $sections = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = trim((string) ($row['title'] ?? ''));
            $copy = trim((string) ($row['copy'] ?? ''));
            if ($title === '' || $copy === '') {
                continue;
            }

            $sections[] = [
                'title' => $title,
                'copy' => $copy,
            ];
        }

        return $sections;
    }

    /**
     * @return list<array{key:string,title:string,body:string}>
     */
    public function professionalMethodBoundarySections(string $locale = self::DEFAULT_LOCALE): array
    {
        $asset = $this->professionalMethodBoundaryAsset($this->normalizeLocale($locale));
        $rows = is_array($asset['sections'] ?? null) ? $asset['sections'] : [];
        $sections = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $key = trim((string) ($row['key'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));
            $body = trim((string) ($row['body'] ?? ''));
            if ($key === '' || $title === '' || $body === '') {
                continue;
            }

            $sections[] = [
                'key' => $key,
                'title' => $title,
                'body' => $body,
            ];
        }

        return $sections;
    }

    /**
     * @return array<string,mixed>
     */
    public function lifecycleCopyContract(bool $snapshotBound = false, string $locale = self::DEFAULT_LOCALE): array
    {
        $normalizedLocale = $this->normalizeLocale($locale);
        $shareAsset = $this->sharePdfHistoryAsset($normalizedLocale);
        $faqAsset = $this->faqAsset($normalizedLocale);
        $technicalNoteSummaryAsset = $this->technicalNoteSummaryAsset($normalizedLocale);
        $professionalMethodBoundaryAsset = $this->professionalMethodBoundaryAsset($normalizedLocale);

        return [
            'schema_version' => 'riasec.lifecycle_copy.v1',
            'content_authority' => 'backend_riasec_lifecycle_assets',
            'locale' => $normalizedLocale,
            'status' => $shareAsset !== [] && $faqAsset !== [] && $technicalNoteSummaryAsset !== [] && $professionalMethodBoundaryAsset !== [] ? 'available' : 'unavailable',
            'snapshot_bound' => $snapshotBound,
            'share_pdf_history_asset_id' => (string) ($shareAsset['asset_id'] ?? ''),
            'faq_asset_id' => (string) ($faqAsset['asset_id'] ?? ''),
            'technical_note_summary_asset_id' => (string) ($technicalNoteSummaryAsset['asset_id'] ?? ''),
            'professional_method_boundary_asset_id' => (string) ($professionalMethodBoundaryAsset['asset_id'] ?? ''),
            'surfaces' => $this->normalizedShareSurfaces($shareAsset),
            'faq_items' => $this->normalizedFaqItems($faqAsset),
            'faq_markdown_reference_available' => $this->faqMarkdownAvailable($normalizedLocale),
            'public_safe_default_surface_keys' => [
                'share_safe_card',
                'share_detail_boundary',
                'low_quality_share',
            ],
            'frontend_fallback_allowed' => false,
            'missing_content_behavior' => 'omit_module_fail_closed',
            'measured_payload_mutation_allowed' => false,
            'report_snapshot_mutation_allowed' => false,
            'raw_feedback_public_exposure_allowed' => false,
            'internal_snapshot_id_public_exposure_allowed' => false,
            'life_stage_public_exposure_allowed' => false,
            'organization_context_public_exposure_allowed' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function sharePdfHistoryAsset(string $locale): array
    {
        if (array_key_exists($locale, $this->sharePdfHistoryCache)) {
            return $this->sharePdfHistoryCache[$locale];
        }

        return $this->sharePdfHistoryCache[$locale] = $this->loadJsonAsset($this->assetPath(self::SHARE_PDF_HISTORY_ASSET_PATH_TEMPLATE, $locale));
    }

    /**
     * @return array<string,mixed>
     */
    private function faqAsset(string $locale): array
    {
        if (array_key_exists($locale, $this->faqJsonCache)) {
            return $this->faqJsonCache[$locale];
        }

        return $this->faqJsonCache[$locale] = $this->loadJsonAsset($this->assetPath(self::FAQ_JSON_ASSET_PATH_TEMPLATE, $locale));
    }

    /**
     * @return array<string,mixed>
     */
    private function technicalNoteSummaryAsset(string $locale): array
    {
        if (array_key_exists($locale, $this->technicalNoteSummaryCache)) {
            return $this->technicalNoteSummaryCache[$locale];
        }

        return $this->technicalNoteSummaryCache[$locale] = $this->loadJsonAsset($this->assetPath(self::TECHNICAL_NOTE_SUMMARY_ASSET_PATH_TEMPLATE, $locale));
    }

    /**
     * @return array<string,mixed>
     */
    private function professionalMethodBoundaryAsset(string $locale): array
    {
        if (array_key_exists($locale, $this->professionalMethodBoundaryCache)) {
            return $this->professionalMethodBoundaryCache[$locale];
        }

        return $this->professionalMethodBoundaryCache[$locale] = $this->loadJsonAsset($this->assetPath(self::PROFESSIONAL_METHOD_BOUNDARY_ASSET_PATH_TEMPLATE, $locale));
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return list<array<string,mixed>>
     */
    private function normalizedShareSurfaces(array $asset): array
    {
        $rows = is_array($asset['surfaces'] ?? null) ? $asset['surfaces'] : [];
        $surfaces = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $surface = trim((string) ($row['surface'] ?? ''));
            $copy = trim((string) ($row['copy'] ?? ''));
            if ($surface === '' || $copy === '') {
                continue;
            }

            $surfaces[] = [
                'surface' => $surface,
                'copy' => $copy,
                'public_safe' => (bool) ($row['public_safe'] ?? false),
                'raw_scores_allowed' => false,
                'raw_feedback_allowed' => false,
            ];
        }

        return $surfaces;
    }

    /**
     * @param  array<string,mixed>  $asset
     * @return list<array<string,string>>
     */
    private function normalizedFaqItems(array $asset): array
    {
        $rows = is_array($asset['questions'] ?? null) ? $asset['questions'] : [];
        $items = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $question = trim((string) ($row['q'] ?? ''));
            $answer = trim((string) ($row['a'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }

            $items[] = [
                'q' => $question,
                'a' => $answer,
            ];
        }

        return $items;
    }

    private function faqMarkdownAvailable(string $locale): bool
    {
        $path = base_path($this->assetPath(self::FAQ_MARKDOWN_ASSET_PATH_TEMPLATE, $locale));

        return is_file($path) && trim((string) file_get_contents($path)) !== '';
    }

    private function assetPath(string $template, string $locale): string
    {
        return sprintf($template, $locale);
    }

    private function normalizeLocale(string $locale): string
    {
        $normalized = trim($locale);

        if (str_starts_with(strtolower($normalized), 'en')) {
            return 'en';
        }

        if ($normalized === 'zh' || $normalized === 'zh_CN' || $normalized === 'zh-CN') {
            return 'zh-CN';
        }

        return in_array($normalized, self::SUPPORTED_LOCALES, true) ? $normalized : self::DEFAULT_LOCALE;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadJsonAsset(string $relativePath): array
    {
        $path = base_path($relativePath);
        if (! is_file($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
