<?php

declare(strict_types=1);

namespace App\Services\Report\Pdf;

use App\Models\Attempt;
use App\Models\ReportSnapshot;
use App\Models\Result;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class ReportPdfDocumentService
{
    public function __construct(
        private readonly ReportPdfArtifactStore $artifactStore,
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
                'pdf_surface_version' => 'mbti.pdf_surface.v1',
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
        $locale = strtolower(trim((string) ($attempt->locale ?? '')));
        $isChinese = str_starts_with($locale, 'zh');
        $typeCode = strtoupper(trim((string) (
            $result?->type_code
            ?? data_get($result?->result_json, 'type_code')
            ?? data_get($result?->result_json, 'result.type_code')
            ?? ''
        )));
        $typeCode = $typeCode !== '' ? $typeCode : ($isChinese ? '待确认' : 'Pending');
        $axisScores = $this->mbtiAxisScores($result);
        $date = $attempt->submitted_at?->format('Y-m-d')
            ?? $attempt->created_at?->format('Y-m-d')
            ?? now()->format('Y-m-d');

        if ($isChinese) {
            return $this->buildMpdfHtmlDocument(
                '费马测试 MBTI 完整报告',
                [
                    '人格类型' => $typeCode,
                    '报告日期' => $date,
                    '报告范围' => '核心画像、维度分布、职业、成长与关系摘要',
                ],
                [
                    [
                        'heading' => '核心画像',
                        'body' => [
                            sprintf('你的人格类型是 %s. 这份报告基于本次 MBTI 作答结果，帮助你理解能量来源、信息处理、决策方式、生活节奏和压力反应。', $typeCode),
                        ],
                    ],
                    [
                        'heading' => '维度分布',
                        'body' => $this->mbtiAxisLines($axisScores, true),
                    ],
                    [
                        'heading' => '职业方向摘要',
                        'body' => [
                            '适合先从问题类型、协作节奏和决策环境三个角度筛选方向。优先寻找能让你持续积累专业判断、清晰表达方案并看到长期进展的任务。',
                        ],
                    ],
                    [
                        'heading' => '成长建议摘要',
                        'body' => [
                            '把复杂目标拆成可验证的小步骤，定期复盘证据和反馈。遇到压力时，先确认事实边界，再决定是否调整计划或沟通方式。',
                        ],
                    ],
                    [
                        'heading' => '关系相处摘要',
                        'body' => [
                            '表达观点时先说明判断依据和不确定性，给对方留下补充信息的空间。协作中把期待、时间线和交付标准写清楚，会减少误解。',
                        ],
                    ],
                ],
                'FermatMind · 费马测试',
                true
            );
        }

        return $this->buildFormalPdfDocument(
            'FermatMind MBTI Full Report',
            [
                'Personality type' => $typeCode,
                'Report date' => $date,
                'Report scope' => 'Core portrait, dimensions, career, growth, and relationship summaries',
            ],
            [
                [
                    'heading' => 'Core portrait',
                    'body' => [
                        sprintf('Your personality type is %s. This report summarizes your MBTI result across energy, information processing, decision making, structure, and stress response.', $typeCode),
                    ],
                ],
                [
                    'heading' => 'Dimension profile',
                    'body' => $this->mbtiAxisLines($axisScores, false),
                ],
                [
                    'heading' => 'Career direction summary',
                    'body' => [
                        'Start by comparing role fit through problem type, collaboration rhythm, and decision environment. Look for work that lets you build durable judgment, explain tradeoffs clearly, and track progress over time.',
                    ],
                ],
                [
                    'heading' => 'Growth focus summary',
                    'body' => [
                        'Break complex goals into smaller evidence-backed steps and review feedback on a predictable cadence. Under pressure, clarify the facts first, then decide whether to adjust the plan or the communication style.',
                    ],
                ],
                [
                    'heading' => 'Relationship style summary',
                    'body' => [
                        'When sharing a view, explain the evidence and the uncertainty behind it. In collaboration, explicit expectations, timelines, and quality bars reduce avoidable friction.',
                    ],
                ],
            ],
            'FermatMind',
            false
        );
    }

    /**
     * @return array<string,int>
     */
    private function mbtiAxisScores(?Result $result): array
    {
        $payload = is_array($result?->result_json ?? null) ? $result?->result_json : [];
        $scores = is_array($result?->scores_pct ?? null) ? $result?->scores_pct : [];
        if ($scores === []) {
            $scores = is_array(data_get($payload, 'axis_scores_json.scores_pct'))
                ? data_get($payload, 'axis_scores_json.scores_pct')
                : [];
        }

        $normalized = [];
        foreach (['EI', 'SN', 'TF', 'JP', 'AT'] as $axis) {
            $value = $scores[$axis] ?? null;
            if (is_numeric($value)) {
                $normalized[$axis] = max(0, min(100, (int) round((float) $value)));
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,int>  $axisScores
     * @return list<string>
     */
    private function mbtiAxisLines(array $axisScores, bool $isChinese): array
    {
        $labels = $isChinese
            ? [
                'EI' => '外向 / 内向',
                'SN' => '实感 / 直觉',
                'TF' => '思考 / 情感',
                'JP' => '判断 / 知觉',
                'AT' => '坚定 / 起伏',
            ]
            : [
                'EI' => 'Extraversion / Introversion',
                'SN' => 'Sensing / Intuition',
                'TF' => 'Thinking / Feeling',
                'JP' => 'Judging / Prospecting',
                'AT' => 'Assertive / Turbulent',
            ];

        $lines = [];
        foreach ($labels as $axis => $label) {
            if (! array_key_exists($axis, $axisScores)) {
                continue;
            }

            $lines[] = sprintf('%s: [%s] %d%% %s', $axis, $this->mbtiAxisBar($axisScores[$axis]), $axisScores[$axis], $label);
        }

        return $lines !== []
            ? $lines
            : [$isChinese ? '维度数据已记录在完整结果中。' : 'Dimension data is recorded in the full result.'];
    }

    private function mbtiAxisBar(int $score): string
    {
        $filled = (int) round(max(0, min(100, $score)) / 10);

        return str_repeat('#', $filled).str_repeat('-', 10 - $filled);
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
     * @param  list<array{heading:string,body:list<string>}>  $sections
     */
    private function buildMpdfHtmlDocument(
        string $title,
        array $summary,
        array $sections,
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
        $mpdf->SetTitle($title);
        $mpdf->SetAuthor('FermatMind');
        $mpdf->SetCreator('FermatMind Report PDF');
        $mpdf->SetHTMLFooter(
            '<table width="100%" style="border-top:1px solid #d9eadf;color:#64748b;font-size:9pt;padding-top:6px;">'
            .'<tr><td width="50%" align="left">'.$this->escapeHtml($footer).'</td>'
            .'<td width="50%" align="right">{PAGENO}</td></tr></table>'
        );
        $mpdf->WriteHTML($this->mbtiReportHtml($title, $summary, $sections, $isChinese));

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * @param  array<string,string>  $summary
     * @param  list<array{heading:string,body:list<string>}>  $sections
     */
    private function mbtiReportHtml(string $title, array $summary, array $sections, bool $isChinese): string
    {
        $fontStack = $isChinese
            ? 'sans-serif'
            : 'DejaVu Sans, Arial, sans-serif';
        $html = '<html><head><meta charset="utf-8"><style>'
            .'body{font-family:'.$fontStack.';color:#172033;font-size:12pt;line-height:1.55;}'
            .'.cover{border-top:5px solid #1b7f5c;padding-top:18px;margin-bottom:22px;}'
            .'h1{font-size:26pt;margin:0 0 14px 0;color:#0f172a;}'
            .'h2{font-size:15pt;margin:20px 0 8px 0;color:#123d34;border-bottom:1px solid #d9eadf;padding-bottom:5px;}'
            .'.summary{background:#edf8f2;border:1px solid #bfe9d0;border-radius:8px;padding:12px 14px;margin:12px 0 18px 0;}'
            .'.summary-row{margin:4px 0;}'
            .'.label{font-weight:bold;color:#315047;}'
            .'p{margin:0 0 8px 0;}'
            .'ul{margin:0 0 0 18px;padding:0;}'
            .'li{margin:4px 0;}'
            .'</style></head><body>';
        $html .= '<div class="cover"><h1>'.$this->escapeHtml($title).'</h1></div>';
        $html .= '<div class="summary">';
        foreach ($summary as $label => $value) {
            $html .= '<div class="summary-row"><span class="label">'.$this->escapeHtml($label).':</span> '.$this->escapeHtml($value).'</div>';
        }
        $html .= '</div>';

        foreach ($sections as $section) {
            $html .= '<h2>'.$this->escapeHtml((string) $section['heading']).'</h2>';
            $html .= '<ul>';
            foreach ($section['body'] as $paragraph) {
                $html .= '<li>'.$this->escapeHtml($paragraph).'</li>';
            }
            $html .= '</ul>';
        }

        return $html.'</body></html>';
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
