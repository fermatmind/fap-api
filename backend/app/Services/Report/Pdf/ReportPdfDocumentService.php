<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf;

use App\Models\Attempt;
use App\Models\ReportSnapshot;
use App\Models\Result;
use App\Services\Report\Pdf\Mbti\MbtiPdfPayloadBuilder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Throwable;

final class ReportPdfDocumentService
{
    private const MBTI_PDF_SURFACE_VERSION = 'mbti.pdf_surface.v4';

    public const MBTI_RESULT_PAGE_EXPORT_SURFACE_VERSION = 'mbti.result_page_export.v1';

    public const RESULT_PAGE_EXPORT_ENGINE = 'gotenberg_chromium';

    public function __construct(
        private readonly ReportPdfArtifactStore $artifactStore,
        private readonly MbtiPdfPayloadBuilder $mbtiPdfPayloadBuilder,
        private readonly GotenbergChromiumPdfClient $gotenbergChromiumPdfClient,
        private readonly ResultPagePdfTokenService $resultPagePdfTokenService,
    ) {}

    public function normalizeVariant(string $variant): string
    {
        $variant = strtolower(trim($variant));

        return in_array($variant, ['free', 'full'], true) ? $variant : 'free';
    }

    public function fileName(string $scaleCode, string $attemptId): string
    {
        $scaleSlug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $scaleCode));

        return trim($scaleSlug, '_').'_report_'.$attemptId.'.pdf';
    }

    public function fileNameForAttempt(Attempt $attempt, array $gate = [], ?Result $result = null): string
    {
        $metadata = $this->metadata($attempt, $gate, $result);
        $hint = trim((string) ($metadata['filename_hint'] ?? ''));

        return $hint !== '' ? $hint : $this->fileName((string) ($attempt->scale_code ?? 'report'), (string) $attempt->id);
    }

    /**
     * @param  array<string,mixed>  $gate
     * @return array<string,mixed>
     */
    public function metadata(Attempt $attempt, array $gate = [], ?Result $result = null): array
    {
        $scaleCode = strtoupper(trim((string) ($attempt->scale_code ?? '')));
        if ($scaleCode === 'RIASEC') {
            return $this->riasecMetadata($attempt, $gate);
        }

        if ($scaleCode === 'MBTI') {
            $date = $attempt->submitted_at?->format('Y-m-d')
                ?? $attempt->created_at?->format('Y-m-d')
                ?? now()->format('Y-m-d');

            return [
                'pdf_surface_version' => self::MBTI_PDF_SURFACE_VERSION,
                'scale_code' => 'MBTI',
                'form_code' => null,
                'form_label' => null,
                'filename_hint' => sprintf('fermatmind-mbti-report-%s.pdf', $date),
                'report_schema_version' => null,
                'projection_version' => null,
                'report_engine_version' => null,
                'interpretation_context_id' => null,
                'content_release_hash' => null,
                'content_snapshot_status' => null,
                'snapshot_binding_v1' => [],
                'compare_compatibility_group' => null,
                'cross_form_comparable' => null,
            ];
        }

        if ($scaleCode !== 'ENNEAGRAM') {
            return [
                'pdf_surface_version' => 'report_pdf.surface.v1',
                'scale_code' => $scaleCode !== '' ? $scaleCode : 'UNKNOWN',
                'form_code' => null,
                'form_label' => null,
                'filename_hint' => $this->fileName((string) ($attempt->scale_code ?? 'report'), (string) $attempt->id),
                'report_schema_version' => null,
                'projection_version' => null,
                'report_engine_version' => null,
                'interpretation_context_id' => null,
                'content_release_hash' => null,
                'content_snapshot_status' => null,
                'snapshot_binding_v1' => [],
                'compare_compatibility_group' => null,
                'cross_form_comparable' => null,
            ];
        }

        $report = is_array($gate['report'] ?? null) ? $gate['report'] : [];
        if ($report === []) {
            $snapshot = ReportSnapshot::query()
                ->where('org_id', (int) ($attempt->org_id ?? 0))
                ->where('attempt_id', (string) $attempt->id)
                ->where('status', 'ready')
                ->first();
            if ($snapshot instanceof ReportSnapshot) {
                $report = is_array($snapshot->report_full_json) ? $snapshot->report_full_json : [];
                if ($report === []) {
                    $report = is_array($snapshot->report_json) ? $snapshot->report_json : [];
                }
            }
        }

        $resultJson = is_array($result?->result_json ?? null) ? $result?->result_json : [];
        $reportV2 = $this->extractEnneagramReportV2($report);
        $projectionV2 = $this->extractEnneagramProjectionV2($report, $resultJson);
        $snapshotBinding = $this->extractSnapshotBinding($report);
        $formCode = trim((string) (
            data_get($reportV2, 'form.form_code')
            ?? data_get($projectionV2, 'form.form_code')
            ?? $attempt->form_code
        ));
        $date = $attempt->submitted_at?->format('Y-m-d')
            ?? $attempt->created_at?->format('Y-m-d')
            ?? now()->format('Y-m-d');

        return [
            'pdf_surface_version' => 'enneagram.pdf_surface.v1',
            'scale_code' => 'ENNEAGRAM',
            'form_code' => $formCode !== '' ? $formCode : null,
            'form_label' => $this->enneagramFormLabel($formCode),
            'filename_hint' => $this->enneagramFileNameHint($formCode, $date),
            'report_schema_version' => data_get($reportV2, 'schema_version') ?? data_get($snapshotBinding, 'report_schema_version'),
            'projection_version' => data_get($projectionV2, 'algorithmic_meta.projection_version') ?? data_get($snapshotBinding, 'projection_version'),
            'report_engine_version' => data_get($reportV2, 'provenance.report_engine_version') ?? data_get($projectionV2, 'algorithmic_meta.report_engine_version'),
            'interpretation_context_id' => data_get($reportV2, 'provenance.interpretation_context_id')
                ?? data_get($projectionV2, 'content_binding.interpretation_context_id')
                ?? data_get($snapshotBinding, 'interpretation_context_id'),
            'content_release_hash' => data_get($reportV2, 'provenance.content_release_hash')
                ?? data_get($projectionV2, 'content_binding.content_release_hash')
                ?? data_get($snapshotBinding, 'content_release_hash'),
            'content_snapshot_status' => data_get($reportV2, 'provenance.content_snapshot_status')
                ?? data_get($projectionV2, 'content_binding.content_snapshot_status')
                ?? data_get($snapshotBinding, 'content_snapshot_status'),
            'snapshot_binding_v1' => $snapshotBinding,
            'compare_compatibility_group' => data_get($projectionV2, 'methodology.compare_compatibility_group')
                ?? data_get($snapshotBinding, 'compare_compatibility_group'),
            'cross_form_comparable' => data_get($projectionV2, 'methodology.cross_form_comparable')
                ?? data_get($snapshotBinding, 'cross_form_comparable'),
        ];
    }

    /**
     * @param  array<string,mixed>  $gate
     * @return array<string,mixed>
     */
    private function riasecMetadata(Attempt $attempt, array $gate): array
    {
        $report = is_array($gate['report'] ?? null) ? $gate['report'] : [];
        if ($report === []) {
            $snapshot = ReportSnapshot::query()
                ->where('org_id', (int) ($attempt->org_id ?? 0))
                ->where('attempt_id', (string) $attempt->id)
                ->where('status', 'ready')
                ->first();
            if ($snapshot instanceof ReportSnapshot) {
                $report = is_array($snapshot->report_full_json) ? $snapshot->report_full_json : [];
                if ($report === []) {
                    $report = is_array($snapshot->report_json) ? $snapshot->report_json : [];
                }
            }
        }

        $projectionV2 = is_array(data_get($report, '_meta.riasec_public_projection_v2'))
            ? data_get($report, '_meta.riasec_public_projection_v2')
            : [];
        $snapshotBinding = $this->extractSnapshotBinding($report);
        $formCode = trim((string) (
            data_get($projectionV2, 'form.form_code')
            ?? data_get($snapshotBinding, 'form_code')
            ?? $attempt->form_code
        ));
        $date = $attempt->submitted_at?->format('Y-m-d')
            ?? $attempt->created_at?->format('Y-m-d')
            ?? now()->format('Y-m-d');

        return [
            'pdf_surface_version' => 'riasec.pdf_surface.v1',
            'scale_code' => 'RIASEC',
            'form_code' => $formCode !== '' ? $formCode : null,
            'form_label' => $this->riasecFormLabel($formCode),
            'filename_hint' => $this->riasecFileNameHint($formCode, $date),
            'report_schema_version' => data_get($report, 'schema_version'),
            'projection_version' => data_get($projectionV2, 'schema_version'),
            'report_engine_version' => data_get($snapshotBinding, 'report_engine_version'),
            'interpretation_context_id' => null,
            'content_release_hash' => null,
            'content_snapshot_status' => null,
            'snapshot_binding_v1' => $snapshotBinding,
            'compare_compatibility_group' => data_get($projectionV2, 'form.compare_compatibility_group'),
            'cross_form_comparable' => false,
        ];
    }

    public function resolveArtifactPath(Attempt $attempt, string $variant, ?Result $result = null): string
    {
        return $this->artifactStore->path(
            (string) ($attempt->scale_code ?? 'UNKNOWN'),
            (string) $attempt->id,
            $this->resolveManifestHash($attempt, $result),
            $this->normalizeVariant($variant)
        );
    }

    public function readArtifact(Attempt $attempt, string $variant, ?Result $result = null): ?string
    {
        $variant = $this->normalizeVariant($variant);
        $manifestHash = $this->resolveManifestHash($attempt, $result);
        $path = $this->artifactStore->path(
            (string) ($attempt->scale_code ?? 'UNKNOWN'),
            (string) $attempt->id,
            $manifestHash,
            $variant
        );
        $candidates = array_merge([
            $path,
        ], $this->artifactStore->legacyPaths(
            (string) ($attempt->scale_code ?? 'UNKNOWN'),
            (string) $attempt->id,
            $manifestHash,
            $variant
        ));
        $cached = $this->artifactStore->getFirst($candidates);
        if (is_string($cached) && $cached !== '') {
            if (! $this->legacyDrainEnabled() && ! $this->artifactStore->exists($path)) {
                $this->artifactStore->put($path, $cached);
            }

            return $cached;
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $gate
     * @return array{binary:string,storage_path:string,variant:string,locked:bool,manifest_hash:string,cached:bool}
     */
    public function getOrGenerate(Attempt $attempt, array $gate, ?Result $result = null): array
    {
        $variant = $this->normalizeVariant((string) ($gate['variant'] ?? 'free'));
        $locked = (bool) ($gate['locked'] ?? true);
        $manifestHash = $this->resolveManifestHash($attempt, $result);
        $path = $this->artifactStore->path(
            (string) ($attempt->scale_code ?? 'UNKNOWN'),
            (string) $attempt->id,
            $manifestHash,
            $variant
        );
        $candidates = array_merge([
            $path,
        ], $this->artifactStore->legacyPaths(
            (string) ($attempt->scale_code ?? 'UNKNOWN'),
            (string) $attempt->id,
            $manifestHash,
            $variant
        ));
        $cached = $this->artifactStore->getFirst($candidates);
        if (is_string($cached) && $cached !== '') {
            if (! $this->legacyDrainEnabled() && ! $this->artifactStore->exists($path)) {
                $this->artifactStore->put($path, $cached);
            }

            return [
                'binary' => $cached,
                'storage_path' => $path,
                'variant' => $variant,
                'locked' => $locked,
                'manifest_hash' => $manifestHash,
                'cached' => true,
            ];
        }

        $report = is_array($gate['report'] ?? null) ? $gate['report'] : [];
        $sections = array_map(
            'strval',
            array_values(
                array_filter(
                    array_column((array) ($report['sections'] ?? []), 'key'),
                    static fn ($value): bool => is_string($value) && trim($value) !== ''
                )
            )
        );
        $normsStatus = strtoupper(trim((string) (
            data_get($gate, 'norms.status')
            ?? data_get($result?->result_json, 'normed_json.norms.status', '')
        )));
        $qualityLevel = strtoupper(trim((string) (
            data_get($gate, 'quality.level')
            ?? data_get($result?->result_json, 'quality.level')
            ?? data_get($result?->result_json, 'normed_json.quality.level', '')
        )));

        $pdfBinary = strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'MBTI'
            ? $this->buildMbtiDocument($attempt, $result)
            : $this->buildDocument(
                (string) $attempt->id,
                (string) ($attempt->scale_code ?? ''),
                $locked,
                $variant,
                $normsStatus,
                $qualityLevel,
                $sections
            );
        $this->artifactStore->put($path, $pdfBinary);

        return [
            'binary' => $pdfBinary,
            'storage_path' => $path,
            'variant' => $variant,
            'locked' => $locked,
            'manifest_hash' => $manifestHash,
            'cached' => false,
        ];
    }

    public function resolveManifestHash(Attempt $attempt, ?Result $result = null): string
    {
        $summary = is_array($attempt->answers_summary_json ?? null) ? $attempt->answers_summary_json : [];
        $meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];
        $hash = trim((string) ($meta['pack_release_manifest_hash'] ?? ''));
        if ($hash !== '') {
            return $hash;
        }

        $resultPayload = is_array($result?->result_json ?? null) ? $result?->result_json : [];
        $hash = trim((string) (
            data_get($resultPayload, 'version_snapshot.content_manifest_hash')
            ?? data_get($resultPayload, 'normed_json.version_snapshot.content_manifest_hash')
            ?? data_get($resultPayload, 'content_manifest_hash')
            ?? ''
        ));

        $hash = $hash !== '' ? $hash : 'nohash';

        if (strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'MBTI') {
            return $hash.'-'.self::MBTI_PDF_SURFACE_VERSION;
        }

        return $hash;
    }

    /**
     * @param  array<string,mixed>  $gate
     * @return array{
     *   binary:string,
     *   storage_path:string,
     *   variant:string,
     *   locked:bool,
     *   manifest_hash:string,
     *   cached:bool,
     *   engine:string,
     *   surface:string,
     *   surface_version:string,
     *   trace_id:string
     * }
     */
    public function getOrGenerateMbtiResultPageExport(Attempt $attempt, array $gate, ?Result $result = null): array
    {
        $scaleCode = strtoupper(trim((string) ($attempt->scale_code ?? '')));
        if ($scaleCode !== 'MBTI') {
            throw new \RuntimeException('Result-page PDF export is only available for MBTI attempts.');
        }

        $variant = $this->normalizeVariant((string) ($gate['variant'] ?? 'free'));
        $locked = (bool) ($gate['locked'] ?? true);
        $traceId = $this->newMbtiResultPageExportTraceId($attempt);
        $manifestHash = $this->resolveResultPageExportManifestHash($attempt, $gate, $result);
        $path = $this->artifactStore->path('MBTI', (string) $attempt->id, $manifestHash, $variant);

        $cached = $this->artifactStore->get($path);
        if (is_string($cached) && $cached !== '') {
            return [
                'binary' => $cached,
                'storage_path' => $path,
                'variant' => $variant,
                'locked' => $locked,
                'manifest_hash' => $manifestHash,
                'cached' => true,
                'engine' => self::RESULT_PAGE_EXPORT_ENGINE,
                'surface' => 'mbti_result_page_export',
                'surface_version' => self::MBTI_RESULT_PAGE_EXPORT_SURFACE_VERSION,
                'trace_id' => $traceId,
            ];
        }

        $pdfBinary = $this->buildMbtiResultPageExportDocument($attempt, $gate, $traceId);
        $this->artifactStore->put($path, $pdfBinary);

        return [
            'binary' => $pdfBinary,
            'storage_path' => $path,
            'variant' => $variant,
            'locked' => $locked,
            'manifest_hash' => $manifestHash,
            'cached' => false,
            'engine' => self::RESULT_PAGE_EXPORT_ENGINE,
            'surface' => 'mbti_result_page_export',
            'surface_version' => self::MBTI_RESULT_PAGE_EXPORT_SURFACE_VERSION,
            'trace_id' => $traceId,
        ];
    }

    /**
     * @param  array<string,mixed>  $gate
     */
    private function resolveResultPageExportManifestHash(Attempt $attempt, array $gate, ?Result $result = null): string
    {
        $baseHash = $this->baseContentManifestHash($attempt, $result);
        $rawLocale = strtolower(trim((string) ($attempt->locale ?? '')));
        $locale = str_starts_with($rawLocale, 'zh') ? 'zh' : 'en';
        $variant = $this->normalizeVariant((string) ($gate['variant'] ?? 'free'));
        $entitlement = ((bool) ($gate['locked'] ?? true)) ? 'locked' : 'unlocked';

        return implode('-', [
            $baseHash,
            self::MBTI_RESULT_PAGE_EXPORT_SURFACE_VERSION,
            self::RESULT_PAGE_EXPORT_ENGINE,
            preg_replace('/[^a-z0-9_.-]+/i', '_', $locale) ?: 'locale',
            $entitlement,
            $variant,
        ]);
    }

    private function baseContentManifestHash(Attempt $attempt, ?Result $result = null): string
    {
        $summary = is_array($attempt->answers_summary_json ?? null) ? $attempt->answers_summary_json : [];
        $meta = is_array($summary['meta'] ?? null) ? $summary['meta'] : [];
        $hash = trim((string) ($meta['pack_release_manifest_hash'] ?? ''));
        if ($hash !== '') {
            return $hash;
        }

        $resultPayload = is_array($result?->result_json ?? null) ? $result?->result_json : [];
        $hash = trim((string) (
            data_get($resultPayload, 'version_snapshot.content_manifest_hash')
            ?? data_get($resultPayload, 'normed_json.version_snapshot.content_manifest_hash')
            ?? data_get($resultPayload, 'content_manifest_hash')
            ?? ''
        ));

        return $hash !== '' ? $hash : 'nohash';
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function extractEnneagramReportV2(array $report): array
    {
        $candidates = [
            data_get($report, 'report._meta.enneagram_report_v2'),
            data_get($report, '_meta.enneagram_report_v2'),
            data_get($report, 'enneagram_report_v2'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && (string) ($candidate['schema_version'] ?? '') === 'enneagram.report.v2') {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $resultJson
     * @return array<string,mixed>
     */
    private function extractEnneagramProjectionV2(array $report, array $resultJson): array
    {
        $candidates = [
            data_get($report, 'report._meta.enneagram_public_projection_v2'),
            data_get($report, '_meta.enneagram_public_projection_v2'),
            data_get($report, 'enneagram_public_projection_v2'),
            data_get($resultJson, 'enneagram_public_projection_v2'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && (string) ($candidate['schema_version'] ?? '') === 'enneagram.public_projection.v2') {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function extractSnapshotBinding(array $report): array
    {
        $binding = data_get($report, '_meta.snapshot_binding_v1');

        return is_array($binding) ? $binding : [];
    }

    private function enneagramFormLabel(string $formCode): ?string
    {
        return match (trim($formCode)) {
            'enneagram_likert_105' => 'E105 标准版',
            'enneagram_forced_choice_144' => 'FC144 深度版',
            default => null,
        };
    }

    private function riasecFormLabel(string $formCode): ?string
    {
        return match (trim($formCode)) {
            'riasec_60' => 'RIASEC 60Q',
            'riasec_140' => 'RIASEC 140Q',
            default => null,
        };
    }

    private function riasecFileNameHint(string $formCode, string $date): string
    {
        $suffix = match (trim($formCode)) {
            'riasec_140' => '140q',
            default => '60q',
        };

        return 'riasec_'.$suffix.'_report_'.$date.'.pdf';
    }

    private function enneagramFileNameHint(string $formCode, string $date): string
    {
        $slug = match (trim($formCode)) {
            'enneagram_likert_105' => 'e105',
            'enneagram_forced_choice_144' => 'fc144',
            default => 'unknown',
        };

        return sprintf('fermatmind-enneagram-%s-%s.pdf', $slug, $date);
    }

    private function buildMbtiDocument(Attempt $attempt, ?Result $result): string
    {
        $gotenbergPdf = $this->buildMbtiGotenbergDocument($attempt);
        if (is_string($gotenbergPdf)) {
            return $gotenbergPdf;
        }

        $locale = strtolower(trim((string) ($attempt->locale ?? '')));
        $isChinese = str_starts_with($locale, 'zh');
        $payload = $this->mbtiPdfPayload($attempt, $result);
        $document = is_array($payload['document'] ?? null) ? $payload['document'] : [];
        $type = is_array($payload['type'] ?? null) ? $payload['type'] : [];
        $axisScores = is_array($payload['axis_scores'] ?? null) ? $payload['axis_scores'] : [];
        $resultPageSections = is_array($payload['result_page_sections'] ?? null) ? $payload['result_page_sections'] : [];
        $typeCode = strtoupper(trim((string) ($type['type_code'] ?? '')));
        $typeCode = $typeCode !== '' && $typeCode !== 'UNKNOWN' ? $typeCode : ($isChinese ? '待确认' : 'Pending');
        $typeName = trim((string) ($type['type_name'] ?? ''));
        $tagline = trim((string) ($type['tagline'] ?? ''));
        $displayType = trim(implode(' · ', array_filter([$typeCode, $typeName, $tagline])));
        $date = $attempt->submitted_at?->format('Y-m-d')
            ?? $attempt->created_at?->format('Y-m-d')
            ?? now()->format('Y-m-d');
        $title = trim((string) ($document['title'] ?? ''));
        if ($title === '') {
            $title = $isChinese ? 'MBTI 完整人格报告' : 'MBTI Full Personality Report';
        }
        $subtitle = trim((string) ($document['subtitle'] ?? ''));
        $summary = $isChinese
            ? [
                '人格类型' => $displayType !== '' ? $displayType : $typeCode,
                '报告日期' => $date,
                '报告范围' => '类型画像、维度解释、结果页核心模块、职业、成长、关系与使用边界',
            ]
            : [
                'Personality type' => $displayType !== '' ? $displayType : $typeCode,
                'Report date' => $date,
                'Report scope' => 'Type portrait, dimensions, result-page sections, career, growth, relationships, and use boundaries',
            ];
        $chapters = $this->mbtiDocumentChapters($document);

        return $this->buildMpdfHtmlDocument(
            $title,
            $subtitle,
            $summary,
            $chapters,
            $axisScores,
            $resultPageSections,
            $isChinese ? 'FermatMind · 费马测试' : 'FermatMind',
            $isChinese
        );
    }

    private function buildMbtiGotenbergDocument(Attempt $attempt): ?string
    {
        if (! $this->gotenbergChromiumPdfClient->enabled()) {
            return null;
        }

        $printUrl = $this->mbtiResultPrintUrl($attempt);
        if ($printUrl === null) {
            return null;
        }

        try {
            return $this->gotenbergChromiumPdfClient->convertUrl($printUrl);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $gate
     */
    private function buildMbtiResultPageExportDocument(Attempt $attempt, array $gate, string $traceId): string
    {
        if (! $this->gotenbergChromiumPdfClient->enabled()) {
            throw new \RuntimeException('Gotenberg result-page PDF engine is disabled.');
        }

        $printUrl = $this->mbtiResultPrintUrl($attempt, $gate);
        if ($printUrl === null) {
            throw new \RuntimeException('Gotenberg result-page print URL is not configured.');
        }

        $options = [
            'printBackground' => true,
            'preferCssPageSize' => true,
            'emulatedMediaType' => 'print',
            'waitForExpression' => 'window.__FERMAT_PDF_READY__ === true',
            'skipNetworkIdleEvent' => true,
            'skipNetworkAlmostIdleEvent' => true,
            'failOnHttpStatusCodes' => '[499,599]',
            'extraHttpHeaders' => json_encode([
                'X-Fermat-Pdf-Trace' => $traceId,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];

        $this->logMbtiResultPageGotenbergRequest($attempt, $printUrl, $options, $traceId);

        try {
            return $this->gotenbergChromiumPdfClient->convertUrl($printUrl, $options, $traceId);
        } catch (Throwable $e) {
            throw new ResultPagePdfExportException(
                $traceId,
                $this->classifyResultPagePdfExportFailure($e),
                'Gotenberg result-page PDF export failed.',
                $e,
            );
        }
    }

    private function newMbtiResultPageExportTraceId(Attempt $attempt): string
    {
        $attemptId = preg_replace('/[^A-Za-z0-9-]+/', '', (string) $attempt->id) ?: 'unknown';

        return sprintf(
            'mbti-result-page-pdf-%s-%s-%s',
            $attemptId,
            now()->utc()->format('Ymd\THis\Z'),
            Str::lower(Str::random(8)),
        );
    }

    /**
     * @param  array<string,string|int|float|bool|null>  $options
     */
    private function logMbtiResultPageGotenbergRequest(Attempt $attempt, string $printUrl, array $options, string $traceId): void
    {
        $parts = parse_url($printUrl) ?: [];

        Log::info('MBTI_RESULT_PAGE_PDF_GOTENBERG_REQUEST', [
            'event' => 'MBTI_RESULT_PAGE_PDF_GOTENBERG_REQUEST',
            'attempt_id' => (string) $attempt->id,
            'surface' => self::MBTI_RESULT_PAGE_EXPORT_SURFACE_VERSION,
            'engine' => self::RESULT_PAGE_EXPORT_ENGINE,
            'print_url_host' => (string) ($parts['host'] ?? ''),
            'print_url_path' => (string) ($parts['path'] ?? ''),
            'wait_for_expression' => (string) ($options['waitForExpression'] ?? ''),
            'wait_for_selector' => (string) ($options['waitForSelector'] ?? ''),
            'wait_delay' => (string) ($options['waitDelay'] ?? ''),
            'skip_network_idle_event' => $options['skipNetworkIdleEvent'] ?? null,
            'skip_network_almost_idle_event' => $options['skipNetworkAlmostIdleEvent'] ?? null,
            'fail_on_http_status_codes' => (string) ($options['failOnHttpStatusCodes'] ?? ''),
            'print_background' => $options['printBackground'] ?? null,
            'prefer_css_page_size' => $options['preferCssPageSize'] ?? null,
            'emulated_media_type' => (string) ($options['emulatedMediaType'] ?? ''),
            'client_timeout_seconds' => (int) config('gotenberg.timeout_seconds', 60),
            'gotenberg_trace' => $traceId,
        ]);
    }

    private function classifyResultPagePdfExportFailure(Throwable $e): string
    {
        $messages = [];
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $messages[] = strtolower($current->getMessage());
        }

        $message = implode(' ', $messages);
        if (
            str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'time limit')
            || str_contains($message, 'curl error 28')
        ) {
            return 'PDF_GENERATION_TIMEOUT';
        }

        return 'RESULT_PAGE_PDF_EXPORT_FAILED';
    }

    /**
     * @param  array<string,mixed>  $gate
     */
    private function mbtiResultPrintUrl(Attempt $attempt, array $gate = []): ?string
    {
        $baseUrl = rtrim(trim((string) config('gotenberg.result_print_base_url', '')), '/');
        if ($baseUrl === '') {
            return null;
        }

        $rawLocale = strtolower(trim((string) ($attempt->locale ?? '')));
        $locale = str_starts_with($rawLocale, 'zh') ? 'zh' : 'en';
        $attemptId = trim((string) $attempt->id);
        if ($attemptId === '') {
            return null;
        }

        $pathTemplate = trim((string) config('gotenberg.result_print_path_template', '/{locale}/result/{attempt_id}'));
        $pathTemplate = $pathTemplate !== '' ? $pathTemplate : '/{locale}/result/{attempt_id}';
        $path = strtr($pathTemplate, [
            '{locale}' => rawurlencode($locale),
            '{attempt_id}' => rawurlencode($attemptId),
        ]);

        $url = $baseUrl.'/'.ltrim($path, '/');
        if (self::MBTI_RESULT_PAGE_EXPORT_SURFACE_VERSION !== '') {
            $pdfToken = $this->resultPagePdfTokenService->issueForMbtiResultPageExport($attempt, $gate, $locale);
            $separator = str_contains($url, '?') ? '&' : '?';
            $url .= $separator.http_build_query([
                'pdf' => '1',
                'surface' => self::MBTI_RESULT_PAGE_EXPORT_SURFACE_VERSION,
                'pdf_token' => $pdfToken,
                'result_access_token' => $pdfToken,
            ], '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    /**
     * @return array<string,mixed>
     */
    private function mbtiPdfPayload(Attempt $attempt, ?Result $result): array
    {
        $payload = $this->mbtiPdfPayloadBuilder->build($attempt, $result);
        $mbtiPayload = $payload[MbtiPdfPayloadBuilder::PAYLOAD_KEY] ?? [];

        return is_array($mbtiPayload) ? $mbtiPayload : [];
    }

    /**
     * @param  array<string,mixed>  $document
     * @return list<array{heading:string,body:list<string>,bullets:list<string>,chapter_key:string}>
     */
    private function mbtiDocumentChapters(array $document): array
    {
        $chapters = [];
        $sourceChapters = is_array($document['chapters'] ?? null) ? $document['chapters'] : [];
        foreach ($sourceChapters as $chapter) {
            if (! is_array($chapter)) {
                continue;
            }

            $heading = trim((string) ($chapter['title'] ?? ''));
            if ($heading === '') {
                continue;
            }

            $chapters[] = [
                'chapter_key' => trim((string) ($chapter['chapter_key'] ?? 'chapter')),
                'heading' => $heading,
                'body' => $this->stringList($chapter['body'] ?? []),
                'bullets' => $this->stringList($chapter['bullets'] ?? []),
            ];
        }

        return $chapters;
    }

    /**
     * @param  list<string>  $sections
     */
    private function buildDocument(
        string $attemptId,
        string $scaleCode,
        bool $locked,
        string $variant,
        string $normsStatus,
        string $qualityLevel,
        array $sections
    ): string {
        $normalizedScale = strtoupper($scaleCode);
        $lines = [
            'Scale: '.$normalizedScale,
            'Variant: '.strtolower($variant),
            'Locked: '.($locked ? 'true' : 'false'),
        ];
        if ($normalizedScale !== 'ENNEAGRAM') {
            array_unshift($lines, 'Attempt ID: '.$attemptId);
        }

        if ($normsStatus !== '') {
            $lines[] = 'Norms Status: '.strtoupper($normsStatus);
        }
        if ($qualityLevel !== '') {
            $lines[] = 'Quality Level: '.strtoupper($qualityLevel);
        }
        if ($sections !== []) {
            $lines[] = 'Sections: '.implode(', ', $sections);
        }

        return $this->buildSimplePdfDocument('FermatMind Report', $lines);
    }

    /**
     * @param  list<string>  $lines
     */
    private function buildSimplePdfDocument(string $title, array $lines): string
    {
        $title = $this->sanitizePdfText($title);
        $stream = "BT\n/F1 14 Tf\n1 0 0 1 40 800 Tm\n(".$title.") Tj\n/F1 10 Tf\n";

        $y = 780;
        foreach ($lines as $line) {
            $line = $this->sanitizePdfText($line);
            if ($line === '') {
                continue;
            }
            $stream .= "1 0 0 1 40 {$y} Tm\n(".$line.") Tj\n";
            $y -= 14;
            if ($y < 48) {
                break;
            }
        }
        $stream .= "ET\n";

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length ".strlen($stream)." >>\nstream\n".$stream."endstream\nendobj\n",
        ];

        $offsets = [0];
        $pdf = "%PDF-1.4\n";
        foreach ($objects as $index => $object) {
            $offsets[$index + 1] = strlen($pdf);
            $pdf .= $object;
        }

        $startXref = strlen($pdf);
        $pdf .= "xref\n0 6\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i])."\n";
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\n";
        $pdf .= "startxref\n".$startXref."\n%%EOF\n";

        return $pdf;
    }

    /**
     * @param  array<string,string>  $summary
     * @param  list<array{heading:string,body:list<string>}>  $sections
     */
    private function buildFormalPdfDocument(
        string $title,
        array $summary,
        array $sections,
        string $footer,
        bool $useCjkFont
    ): string {
        $pages = [];
        $operations = [];
        $pageNumber = 1;
        $y = 792;

        $addPage = function () use (&$pages, &$operations, &$pageNumber, &$y, $footer, $useCjkFont): void {
            if ($operations !== []) {
                $this->appendPdfText($operations, 48, 34, $footer.' · '.$pageNumber, 8, $useCjkFont);
                $pages[] = implode('', $operations);
                $pageNumber++;
            }

            $operations = [];
            $y = 792;
        };

        $writeLine = function (string $text, int $size = 10, int $indent = 0, int $gap = 15) use (&$operations, &$y, $addPage, $useCjkFont): void {
            if ($y < 72) {
                $addPage();
            }

            $this->appendPdfText($operations, 48 + $indent, $y, $text, $size, $useCjkFont);
            $y -= $gap;
        };

        $writeLine($title, 20, 0, 28);
        foreach ($summary as $label => $value) {
            $writeLine($label.': '.$value, 10, 0, 15);
        }

        $y -= 10;
        foreach ($sections as $section) {
            $heading = trim($section['heading']);
            if ($heading !== '') {
                $writeLine($heading, 13, 0, 20);
            }

            foreach ($section['body'] as $paragraph) {
                foreach ($this->wrapPdfText($paragraph, $useCjkFont ? 34 : 88) as $line) {
                    $writeLine($line, 10, 10, 15);
                }

                $y -= 3;
            }

            $y -= 8;
        }

        $addPage();

        return $this->buildPdfFromPageStreams($pages, $useCjkFont);
    }

    /**
     * @param  array<string,string>  $summary
     * @param  list<array{heading:string,body:list<string>,bullets:list<string>,chapter_key:string}>  $sections
     * @param  list<array<string,mixed>>  $axisScores
     * @param  list<array<string,mixed>>  $resultPageSections
     */
    private function buildMpdfHtmlDocument(
        string $title,
        string $subtitle,
        array $summary,
        array $sections,
        array $axisScores,
        array $resultPageSections,
        string $footer,
        bool $isChinese
    ): string {
        $tempDir = storage_path('framework/cache/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'tempDir' => $tempDir,
            'margin_left' => 18,
            'margin_right' => 18,
            'margin_top' => 18,
            'margin_bottom' => 16,
            'margin_footer' => 8,
        ]);
        $mpdf->SetCompression(false);
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor('FermatMind');
        $mpdf->SetCreator('FermatMind Report PDF');
        $mpdf->SetHTMLHeader(
            '<table width="100%" style="color:#64748b;font-size:8.5pt;">'
            .'<tr><td width="50%" align="left">FermatMind MBTI</td>'
            .'<td width="50%" align="right">'.$this->escapeHtml($title).'</td></tr></table>'
        );
        $mpdf->SetHTMLFooter(
            '<table width="100%" style="border-top:1px solid #d9eadf;color:#64748b;font-size:9pt;padding-top:6px;">'
            .'<tr><td width="50%" align="left">'.$this->escapeHtml($footer).'</td>'
            .'<td width="50%" align="right">{PAGENO} / {nbpg}</td></tr></table>'
        );
        $mpdf->WriteHTML($this->mbtiReportHtml($title, $subtitle, $summary, $sections, $axisScores, $resultPageSections, $isChinese));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * @param  array<string,string>  $summary
     * @param  list<array{heading:string,body:list<string>,bullets:list<string>,chapter_key:string}>  $sections
     * @param  list<array<string,mixed>>  $axisScores
     * @param  list<array<string,mixed>>  $resultPageSections
     */
    private function mbtiReportHtml(
        string $title,
        string $subtitle,
        array $summary,
        array $sections,
        array $axisScores,
        array $resultPageSections,
        bool $isChinese
    ): string {
        $fontStack = $isChinese
            ? 'sans-serif'
            : 'DejaVu Sans, Arial, sans-serif';
        $html = '<html><head><meta charset="utf-8"><style>'
            .'@page{margin:18mm 18mm 18mm 18mm;}'
            .'body{font-family:'.$fontStack.';color:#172033;font-size:11.5pt;line-height:1.58;}'
            .'.cover{height:230mm;border-top:7px solid #14946f;padding-top:28mm;background:#f3fbf7;}'
            .'.brand{font-size:10pt;letter-spacing:.08em;text-transform:uppercase;color:#14946f;font-weight:bold;margin-bottom:18mm;}'
            .'h1{font-size:30pt;line-height:1.12;margin:0 0 10mm 0;color:#0f172a;}'
            .'.subtitle{font-size:13pt;color:#475569;width:82%;margin-bottom:16mm;}'
            .'.summary{background:#ffffff;border:1px solid #bfe9d0;border-radius:8px;padding:12px 15px;margin:12px 0 18px 0;}'
            .'.summary-row{margin:5px 0;}'
            .'.label{font-weight:bold;color:#315047;}'
            .'.toc-title{font-size:12pt;color:#14946f;font-weight:bold;margin:20mm 0 5mm;}'
            .'.toc-item{border-bottom:1px solid #e2e8f0;padding:5px 0;color:#334155;}'
            .'.chapter{page-break-before:always;}'
            .'.chapter:first-of-type{page-break-before:always;}'
            .'.chapter-kicker{font-size:9pt;letter-spacing:.08em;text-transform:uppercase;color:#14946f;font-weight:bold;margin-bottom:6px;}'
            .'h2{font-size:20pt;margin:0 0 10px 0;color:#123d34;border-bottom:2px solid #d9eadf;padding-bottom:7px;}'
            .'p{margin:0 0 9px 0;}'
            .'ul{margin:0 0 0 18px;padding:0;}'
            .'li{margin:5px 0;}'
            .'h3{font-size:13.5pt;margin:0 0 5px 0;color:#123d34;}'
            .'.card{border:1px solid #d9eadf;border-radius:8px;padding:10px 12px;margin:0 0 10px 0;background:#ffffff;page-break-inside:avoid;}'
            .'.card-desc{color:#334155;margin-bottom:6px;}'
            .'.tag{display:inline-block;margin:3px 4px 0 0;padding:2px 7px;border-radius:10px;background:#eef8f2;color:#15745b;font-size:8.5pt;}'
            .'.axis-grid{margin:12px 0 18px 0;}'
            .'.axis{margin:0 0 10px 0;}'
            .'.axis-label{font-size:9.5pt;color:#475569;margin-bottom:3px;}'
            .'.axis-track{height:8px;background:#e2e8f0;border-radius:4px;}'
            .'.axis-fill{height:8px;background:#14946f;border-radius:4px;}'
            .'.axis-poles{width:100%;font-size:8.5pt;color:#64748b;margin-top:2px;}'
            .'.boundary{margin-top:14px;padding:10px 12px;background:#f8fafc;border-left:4px solid #14946f;color:#475569;}'
            .'</style></head><body>';
        $html .= '<div class="cover">';
        $html .= '<div class="brand">FermatMind</div>';
        $html .= '<h1>'.$this->escapeHtml($title).'</h1>';
        if ($subtitle !== '') {
            $html .= '<p class="subtitle">'.$this->escapeHtml($subtitle).'</p>';
        }
        $html .= '<div class="summary">';
        foreach ($summary as $label => $value) {
            $html .= '<div class="summary-row"><span class="label">'.$this->escapeHtml($label).':</span> '.$this->escapeHtml($value).'</div>';
        }
        $html .= '</div>';
        $html .= '<div class="toc-title">'.$this->escapeHtml($isChinese ? '报告章节' : 'Report sections').'</div>';
        foreach ($sections as $index => $section) {
            $html .= '<div class="toc-item">'.($index + 1).'. '.$this->escapeHtml((string) $section['heading']).'</div>';
        }
        foreach ($resultPageSections as $index => $section) {
            $title = trim((string) ($section['title'] ?? ''));
            if ($title !== '') {
                $html .= '<div class="toc-item">'.(count($sections) + $index + 1).'. '.$this->escapeHtml($title).'</div>';
            }
        }
        $html .= '</div>';

        foreach ($sections as $index => $section) {
            $chapterKey = (string) ($section['chapter_key'] ?? '');
            $html .= '<div class="chapter">';
            $html .= '<div class="chapter-kicker">'.$this->escapeHtml($isChinese ? '第 '.($index + 1).' 章' : 'Chapter '.($index + 1)).'</div>';
            $html .= '<h2>'.$this->escapeHtml((string) $section['heading']).'</h2>';
            if ($chapterKey === 'dimension_explanation' && $axisScores !== []) {
                $html .= '<div class="axis-grid">';
                foreach ($axisScores as $axis) {
                    $axisCode = $this->escapeHtml((string) ($axis['axis'] ?? ''));
                    $axisName = trim((string) ($axis['label'] ?? ''));
                    $leftLabel = trim((string) ($axis['left_label'] ?? ''));
                    $rightLabel = trim((string) ($axis['right_label'] ?? ''));
                    $state = trim((string) ($axis['state'] ?? ''));
                    $percent = max(0, min(100, (int) round((float) ($axis['percent'] ?? 0))));
                    $labelParts = array_filter([$axisName !== '' ? $axisName : $axisCode, $axisCode, $state]);
                    $label = implode(' · ', $labelParts);
                    $html .= '<div class="axis">';
                    $html .= '<div class="axis-label">'.$this->escapeHtml($label).' · '.$percent.'%</div>';
                    $html .= '<div class="axis-track"><div class="axis-fill" style="width:'.$percent.'%;"></div></div>';
                    if ($leftLabel !== '' || $rightLabel !== '') {
                        $html .= '<table class="axis-poles"><tr><td align="left">'.$this->escapeHtml($leftLabel).'</td><td align="right">'.$this->escapeHtml($rightLabel).'</td></tr></table>';
                    }
                    $html .= '</div>';
                }
                $html .= '</div>';
            }
            $html .= '<ul>';
            foreach ($section['body'] as $paragraph) {
                $html .= '<li>'.$this->escapeHtml($paragraph).'</li>';
            }
            foreach ($section['bullets'] as $bullet) {
                $html .= '<li>'.$this->escapeHtml($bullet).'</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }

        foreach ($resultPageSections as $index => $section) {
            $title = trim((string) ($section['title'] ?? ''));
            $cards = is_array($section['cards'] ?? null) ? $section['cards'] : [];
            if ($title === '' || $cards === []) {
                continue;
            }

            $html .= '<div class="chapter">';
            $html .= '<div class="chapter-kicker">'.$this->escapeHtml($isChinese ? '结果页模块 '.($index + 1) : 'Result page section '.($index + 1)).'</div>';
            $html .= '<h2>'.$this->escapeHtml($title).'</h2>';
            foreach ($cards as $card) {
                if (! is_array($card)) {
                    continue;
                }

                $cardTitle = trim((string) ($card['title'] ?? ''));
                $description = trim((string) ($card['description'] ?? ''));
                $bullets = $this->stringList($card['bullets'] ?? []);
                $tips = $this->stringList($card['tips'] ?? []);
                $tags = $this->stringList($card['tags'] ?? []);
                if ($cardTitle === '' && $description === '' && $bullets === [] && $tips === []) {
                    continue;
                }

                $html .= '<div class="card">';
                if ($cardTitle !== '') {
                    $html .= '<h3>'.$this->escapeHtml($cardTitle).'</h3>';
                }
                if ($description !== '') {
                    $html .= '<p class="card-desc">'.$this->escapeHtml($description).'</p>';
                }
                if ($bullets !== [] || $tips !== []) {
                    $html .= '<ul>';
                    foreach (array_merge($bullets, $tips) as $line) {
                        $html .= '<li>'.$this->escapeHtml($line).'</li>';
                    }
                    $html .= '</ul>';
                }
                foreach ($tags as $tag) {
                    $html .= '<span class="tag">'.$this->escapeHtml($tag).'</span>';
                }
                $html .= '</div>';
            }
            $html .= '</div>';
        }

        $html .= '<div class="boundary">'.$this->escapeHtml($isChinese
            ? '本报告用于自我理解和讨论，不用于诊断、录用筛选、升学录取或结果保证。'
            : 'This report supports reflection and discussion. It is not a diagnosis, hiring screen, admission predictor, or outcome guarantee.').'</div>';

        return $html.'</body></html>';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $line = is_scalar($item) ? trim((string) $item) : '';
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $operations
     */
    private function appendPdfText(array &$operations, int $x, int $y, string $text, int $size, bool $useCjkFont): void
    {
        if ($useCjkFont) {
            $operations[] = sprintf("BT /F2 %d Tf 1 0 0 1 %d %d Tm <%s> Tj ET\n", $size, $x, $y, $this->utf16Hex($text));

            return;
        }

        $operations[] = sprintf("BT /F1 %d Tf 1 0 0 1 %d %d Tm (%s) Tj ET\n", $size, $x, $y, $this->sanitizePdfText($text));
    }

    /**
     * @param  list<string>  $pages
     */
    private function buildPdfFromPageStreams(array $pages, bool $includeCjkFont): string
    {
        $objects = [
            1 => "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
        ];
        $objects[3] = "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        if ($includeCjkFont) {
            $objects[4] = "4 0 obj\n<< /Type /Font /Subtype /Type0 /BaseFont /STSong-Light /Encoding /UniGB-UCS2-H /DescendantFonts [5 0 R] >>\nendobj\n";
            $objects[5] = "5 0 obj\n<< /Type /Font /Subtype /CIDFontType0 /BaseFont /STSong-Light /CIDSystemInfo << /Registry (Adobe) /Ordering (GB1) /Supplement 2 >> /FontDescriptor 6 0 R >>\nendobj\n";
            $objects[6] = "6 0 obj\n<< /Type /FontDescriptor /FontName /STSong-Light /Flags 6 /FontBBox [0 -200 1000 900] /ItalicAngle 0 /Ascent 880 /Descent -120 /CapHeight 700 /StemV 80 >>\nendobj\n";
        }

        $nextObjectId = $includeCjkFont ? 7 : 4;
        $pageObjectIds = [];
        foreach ($pages as $stream) {
            $pageObjectId = $nextObjectId++;
            $contentObjectId = $nextObjectId++;
            $pageObjectIds[] = $pageObjectId;

            $fontResources = '/F1 3 0 R';
            if ($includeCjkFont) {
                $fontResources .= ' /F2 4 0 R';
            }

            $objects[$pageObjectId] = sprintf(
                "%d 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << %s >> >> /Contents %d 0 R >>\nendobj\n",
                $pageObjectId,
                $fontResources,
                $contentObjectId
            );
            $objects[$contentObjectId] = sprintf(
                "%d 0 obj\n<< /Length %d >>\nstream\n%sendstream\nendobj\n",
                $contentObjectId,
                strlen($stream),
                $stream
            );
        }

        $objects[2] = sprintf(
            "2 0 obj\n<< /Type /Pages /Kids [%s] /Count %d >>\nendobj\n",
            implode(' ', array_map(static fn (int $id): string => $id.' 0 R', $pageObjectIds)),
            count($pageObjectIds)
        );
        ksort($objects);

        $offsets = [0];
        $pdf = "%PDF-1.4\n";
        foreach ($objects as $index => $object) {
            $offsets[$index] = strlen($pdf);
            $pdf .= $object;
        }

        $maxObjectId = max(array_keys($objects));
        $startXref = strlen($pdf);
        $pdf .= "xref\n0 ".($maxObjectId + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObjectId; $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i] ?? 0)."\n";
        }
        $pdf .= "trailer\n<< /Size ".($maxObjectId + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n".$startXref."\n%%EOF\n";

        return $pdf;
    }

    /**
     * @return list<string>
     */
    private function wrapPdfText(string $text, int $limit): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $lines = [];
        while ($this->textLength($text) > $limit) {
            $lines[] = $this->textSlice($text, 0, $limit);
            $text = trim($this->textSlice($text, $limit));
        }
        if ($text !== '') {
            $lines[] = $text;
        }

        return $lines;
    }

    private function utf16Hex(string $value): string
    {
        $encoded = mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');

        return strtoupper(bin2hex($encoded));
    }

    private function textLength(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    private function textSlice(string $value, int $start, ?int $length = null): string
    {
        return mb_substr($value, $start, $length, 'UTF-8');
    }

    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function sanitizePdfText(string $value): string
    {
        $value = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';
    }

    private function legacyDrainEnabled(): bool
    {
        return (bool) config('storage_rollout.legacy_drain_enabled', false);
    }
}
