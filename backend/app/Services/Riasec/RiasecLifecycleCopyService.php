<?php

declare(strict_types=1);

namespace App\Services\Riasec;

final class RiasecLifecycleCopyService
{
    private const SHARE_PDF_HISTORY_ASSET_PATH = 'content_assets/riasec/share_pdf_history_v1.zh-CN.json';

    private const FAQ_JSON_ASSET_PATH = 'content_assets/riasec/faq_v1.zh-CN.json';

    private const FAQ_MARKDOWN_ASSET_PATH = 'content_assets/riasec/faq_v1.zh-CN.md';

    private const TECHNICAL_NOTE_SUMMARY_ASSET_PATH = 'content_assets/riasec/technical_note_user_summary_v1.zh-CN.json';

    private const PROFESSIONAL_METHOD_BOUNDARY_ASSET_PATH = 'content_assets/riasec/professional_method_boundary_v1.zh-CN.json';

    /** @var array<string,mixed>|null */
    private ?array $sharePdfHistoryCache = null;

    /** @var array<string,mixed>|null */
    private ?array $faqJsonCache = null;

    /** @var array<string,mixed>|null */
    private ?array $technicalNoteSummaryCache = null;

    /** @var array<string,mixed>|null */
    private ?array $professionalMethodBoundaryCache = null;

    /**
     * @return list<array{title:string,copy:string}>
     */
    public function technicalNoteSummarySections(): array
    {
        $asset = $this->technicalNoteSummaryAsset();
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
    public function professionalMethodBoundarySections(): array
    {
        $asset = $this->professionalMethodBoundaryAsset();
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
    public function lifecycleCopyContract(bool $snapshotBound = false): array
    {
        $shareAsset = $this->sharePdfHistoryAsset();
        $faqAsset = $this->faqAsset();

        return [
            'schema_version' => 'riasec.lifecycle_copy.v1',
            'content_authority' => 'backend_riasec_lifecycle_assets',
            'status' => $shareAsset !== [] && $faqAsset !== [] ? 'available' : 'unavailable',
            'snapshot_bound' => $snapshotBound,
            'share_pdf_history_asset_id' => (string) ($shareAsset['asset_id'] ?? ''),
            'faq_asset_id' => (string) ($faqAsset['asset_id'] ?? ''),
            'technical_note_summary_asset_id' => (string) ($this->technicalNoteSummaryAsset()['asset_id'] ?? ''),
            'professional_method_boundary_asset_id' => (string) ($this->professionalMethodBoundaryAsset()['asset_id'] ?? ''),
            'surfaces' => $this->normalizedShareSurfaces($shareAsset),
            'faq_items' => $this->normalizedFaqItems($faqAsset),
            'faq_markdown_reference_available' => $this->faqMarkdownAvailable(),
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
    private function sharePdfHistoryAsset(): array
    {
        if ($this->sharePdfHistoryCache !== null) {
            return $this->sharePdfHistoryCache;
        }

        return $this->sharePdfHistoryCache = $this->loadJsonAsset(self::SHARE_PDF_HISTORY_ASSET_PATH);
    }

    /**
     * @return array<string,mixed>
     */
    private function faqAsset(): array
    {
        if ($this->faqJsonCache !== null) {
            return $this->faqJsonCache;
        }

        return $this->faqJsonCache = $this->loadJsonAsset(self::FAQ_JSON_ASSET_PATH);
    }

    /**
     * @return array<string,mixed>
     */
    private function technicalNoteSummaryAsset(): array
    {
        if ($this->technicalNoteSummaryCache !== null) {
            return $this->technicalNoteSummaryCache;
        }

        return $this->technicalNoteSummaryCache = $this->loadJsonAsset(self::TECHNICAL_NOTE_SUMMARY_ASSET_PATH);
    }

    /**
     * @return array<string,mixed>
     */
    private function professionalMethodBoundaryAsset(): array
    {
        if ($this->professionalMethodBoundaryCache !== null) {
            return $this->professionalMethodBoundaryCache;
        }

        return $this->professionalMethodBoundaryCache = $this->loadJsonAsset(self::PROFESSIONAL_METHOD_BOUNDARY_ASSET_PATH);
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

    private function faqMarkdownAvailable(): bool
    {
        $path = base_path(self::FAQ_MARKDOWN_ASSET_PATH);

        return is_file($path) && trim((string) file_get_contents($path)) !== '';
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
