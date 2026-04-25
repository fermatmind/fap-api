<?php

namespace App\Services\V0_3;

use App\Models\Attempt;
use App\Models\PersonalityProfile;
use App\Models\ReportSnapshot;
use App\Models\Result;
use App\Models\Share;
use App\Services\BigFive\BigFivePublicProjectionService;
use App\Services\Cms\PersonalityProfileService;
use App\Services\Enneagram\EnneagramPublicFormSummaryBuilder;
use App\Services\InsightGraph\InsightGraphContractService;
use App\Services\InsightGraph\PartnerReadContractService;
use App\Services\InsightGraph\WidgetSurfaceContractService;
use App\Services\Mbti\MbtiPrivacyConsentContractService;
use App\Services\Mbti\MbtiPublicProjectionService;
use App\Services\Mbti\MbtiPublicSummaryV1Builder;
use App\Services\PublicSurface\AnswerSurfaceContractService;
use App\Services\PublicSurface\LandingSurfaceContractService;
use App\Services\PublicSurface\PublicSurfaceContractService;
use App\Services\PublicSurface\SeoSurfaceContractService;
use App\Services\Report\ReportAccess;
use App\Services\Report\ReportComposer;
use App\Services\Riasec\RiasecPublicProjectionService;
use App\Services\Scale\ScaleIdentityWriteProjector;
use App\Services\Scale\ScaleRegistry;
use App\Support\OrgContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

class ShareService
{
    public function __construct(
        private readonly ScaleIdentityWriteProjector $identityProjector,
        private readonly ReportComposer $reportComposer,
        private readonly ScaleRegistry $scaleRegistry,
        private readonly PersonalityProfileService $personalityProfileService,
        private readonly MbtiPrivacyConsentContractService $mbtiPrivacyConsentContractService,
        private readonly MbtiPublicProjectionService $mbtiPublicProjectionService,
        private readonly MbtiPublicSummaryV1Builder $mbtiPublicSummaryV1Builder,
        private readonly BigFivePublicProjectionService $bigFivePublicProjectionService,
        private readonly EnneagramPublicFormSummaryBuilder $enneagramPublicFormSummaryBuilder,
        private readonly RiasecPublicProjectionService $riasecPublicProjectionService,
        private readonly AnswerSurfaceContractService $answerSurfaceContractService,
        private readonly LandingSurfaceContractService $landingSurfaceContractService,
        private readonly PublicSurfaceContractService $publicSurfaceContractService,
        private readonly SeoSurfaceContractService $seoSurfaceContractService,
        private readonly InsightGraphContractService $insightGraphContractService,
        private readonly PartnerReadContractService $partnerReadContractService,
        private readonly WidgetSurfaceContractService $widgetSurfaceContractService,
    ) {}

    public function getOrCreateShare(string $attemptId, OrgContext $ctx): array
    {
        $attempt = $this->findAccessibleAttempt($attemptId, $ctx);

        $result = Result::query()
            ->where('attempt_id', $attemptId)
            ->where('org_id', $ctx->orgId())
            ->firstOrFail();

        $share = Share::query()
            ->where('attempt_id', $attemptId)
            ->first();

        if (! $share) {
            $share = $this->createShare($attempt, $ctx->anonId());
        } else {
            $this->backfillShareScaleIdentityIfNeeded($share, $attempt);
        }

        return $this->buildSharePayload($share, $attempt, $result);
    }

    public function getShareView(string $shareId): array
    {
        $share = Share::query()->where('id', $shareId)->firstOrFail();

        $attempt = Attempt::query()
            ->where('id', $share->attempt_id)
            ->firstOrFail();

        $orgId = (int) ($attempt->org_id ?? 0);
        $ctxOrgId = max(0, (int) app(OrgContext::class)->orgId());
        if ($ctxOrgId > 0 && $ctxOrgId !== $orgId) {
            throw (new ModelNotFoundException)->setModel(Share::class, [$shareId]);
        }

        $result = Result::query()
            ->where('attempt_id', $attempt->id)
            ->where('org_id', $orgId)
            ->firstOrFail();

        return $this->buildSharePayload($share, $attempt, $result);
    }

    public function buildPublicSummaryPayload(
        Attempt $attempt,
        Result $result,
        ?string $shareId = null,
        ?string $createdAt = null
    ): array {
        return $this->buildCanonicalPayload(null, $attempt, $result, $shareId, $createdAt);
    }

    public function resolveAttemptForAuth(string $attemptId, OrgContext $ctx): Attempt
    {
        return $this->findAccessibleAttempt($attemptId, $ctx);
    }

    private function findAccessibleAttempt(string $attemptId, OrgContext $ctx): Attempt
    {
        $query = Attempt::query()
            ->where('id', $attemptId)
            ->where('org_id', $ctx->orgId());

        if (! $this->isAdmin($ctx)) {
            $userId = $ctx->userId();
            $anonId = $ctx->anonId();

            if ($userId === null && ($anonId === null || $anonId === '')) {
                throw (new ModelNotFoundException)->setModel(Attempt::class, [$attemptId]);
            }

            $query->where(function (Builder $sub) use ($userId, $anonId): void {
                if ($userId !== null) {
                    $sub->orWhere('user_id', (string) $userId);
                }
                if ($anonId !== null && $anonId !== '') {
                    $sub->orWhere('anon_id', $anonId);
                }
            });
        }

        return $query->firstOrFail();
    }

    private function isAdmin(OrgContext $ctx): bool
    {
        return strtolower((string) ($ctx->role() ?? '')) === 'admin';
    }

    private function createShare(Attempt $attempt, ?string $anonId): Share
    {
        $share = new Share;
        $share->id = bin2hex(random_bytes(16));
        $share->attempt_id = (string) $attempt->id;
        $share->anon_id = $anonId;
        $share->scale_code = (string) ($attempt->scale_code ?? '');
        $share->scale_version = (string) ($attempt->scale_version ?? '');
        $share->content_package_version = (string) ($attempt->content_package_version ?? '');
        if ($this->shouldWriteScaleIdentityColumns()) {
            [$scaleCodeV2, $scaleUid] = $this->resolveScaleIdentityValues($attempt);
            $share->scale_code_v2 = $scaleCodeV2;
            $share->scale_uid = $scaleUid;
        }

        try {
            $share->save();

            return $share;
        } catch (QueryException $e) {
            return Share::query()
                ->where('attempt_id', (string) $attempt->id)
                ->firstOrFail();
        }
    }

    private function backfillShareScaleIdentityIfNeeded(Share $share, Attempt $attempt): void
    {
        if (! $this->shouldWriteScaleIdentityColumns()) {
            return;
        }

        [$scaleCodeV2, $scaleUid] = $this->resolveScaleIdentityValues($attempt);
        $dirty = false;

        if (trim((string) ($share->scale_code_v2 ?? '')) === '' && $scaleCodeV2 !== null && $scaleCodeV2 !== '') {
            $share->scale_code_v2 = $scaleCodeV2;
            $dirty = true;
        }
        if (trim((string) ($share->scale_uid ?? '')) === '' && $scaleUid !== null && $scaleUid !== '') {
            $share->scale_uid = $scaleUid;
            $dirty = true;
        }

        if (! $dirty) {
            return;
        }

        try {
            $share->save();
        } catch (QueryException $e) {
            // best effort backfill; keep read path unaffected on contention.
        }
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function resolveScaleIdentityValues(Attempt $attempt): array
    {
        $identity = $this->identityProjector->projectFromAttempt($attempt);

        return [
            $identity['scale_code_v2'],
            $identity['scale_uid'],
        ];
    }

    private function shouldWriteScaleIdentityColumns(): bool
    {
        $mode = strtolower(trim((string) config('scale_identity.write_mode', 'legacy')));

        return in_array($mode, ['dual', 'v2'], true);
    }

    private function buildSharePayload(Share $share, Attempt $attempt, Result $result): array
    {
        return $this->buildCanonicalPayload(
            $share,
            $attempt,
            $result,
            (string) $share->id,
            $share->created_at?->toISOString()
        );
    }

    /**
     * @return array{
     *   scale_code:string,
     *   locale:string,
     *   title:string,
     *   subtitle:?string,
     *   summary:?string,
     *   type_code:string,
     *   type_name:string,
     *   tagline:?string,
     *   rarity:string|int|float|null,
     *   tags:list<string>,
     *   dimensions:list<array<string,mixed>>,
     *   primary_cta_label:string,
     *   primary_cta_path:string
     * }
     */
    private function buildShareSummary(
        ?Share $share,
        Attempt $attempt,
        Result $result,
        ?array $report = null,
        ?array $big5Projection = null,
        ?array $riasecProjection = null
    ): array {
        $resultJson = $this->normalizeArray($result->result_json ?? null);
        $report = is_array($report) ? $report : $this->buildPublicSafeReportSnapshot($attempt, $result);
        $reportProfile = $this->normalizeArray($report['profile'] ?? null);
        $identityCard = $this->normalizeArray($report['identity_card'] ?? null);
        $identityLayer = $this->normalizeArray(data_get($report, 'layers.identity'));

        $scaleCode = strtoupper(trim((string) ($attempt->scale_code ?? $result->scale_code ?? ($share?->scale_code ?? '') ?? '')));
        if ($scaleCode === '') {
            $scaleCode = 'MBTI';
        }

        $locale = $this->normalizeLocale((string) ($attempt->locale ?? config('content_packs.default_locale', 'zh-CN')));
        if ($scaleCode === 'BIG5_OCEAN') {
            return $this->buildBigFiveShareSummary($attempt, $locale, $big5Projection ?? []);
        }
        if ($scaleCode === 'ENNEAGRAM') {
            return $this->buildEnneagramShareSummary($attempt, $result, $locale, $report);
        }
        if ($scaleCode === 'RIASEC') {
            return $this->buildRiasecShareSummary($attempt, $locale, $riasecProjection ?? []);
        }

        $typeCode = trim((string) ($result->type_code ?? $resultJson['type_code'] ?? ''));
        $typeName = $this->firstNonEmpty(
            $this->stringOrNull($resultJson['type_name'] ?? null),
            $this->stringOrNull($resultJson['type'] ?? null),
            $this->stringOrNull($reportProfile['type_name'] ?? null),
            $typeCode
        ) ?? $typeCode;

        $publicProfile = $this->resolvePublicProfile(
            $typeCode,
            (int) ($attempt->org_id ?? 0),
            $scaleCode,
            $locale
        );

        $dimensions = $this->buildDimensions(
            is_array($result->scores_pct ?? null) ? $result->scores_pct : $this->normalizeArray($report['scores_pct'] ?? null),
            is_array($result->axis_states ?? null) ? $result->axis_states : $this->normalizeArray($report['axis_states'] ?? null),
            $locale
        );

        $tags = $this->resolvePublicTags($resultJson, $reportProfile, $identityCard, $dimensions);

        return [
            'scale_code' => $scaleCode,
            'locale' => $locale,
            'title' => $this->firstNonEmpty(
                $publicProfile?->title,
                $this->stringOrNull($identityCard['title'] ?? null),
                $this->stringOrNull($identityLayer['title'] ?? null),
                $typeName,
                $typeCode
            ) ?? $typeCode,
            'subtitle' => $this->firstNonEmpty(
                $publicProfile?->subtitle,
                $this->stringOrNull($resultJson['subtitle'] ?? null),
                $this->stringOrNull($identityCard['subtitle'] ?? null),
                $this->stringOrNull($identityLayer['subtitle'] ?? null)
            ),
            'summary' => $this->firstNonEmpty(
                $this->stringOrNull($resultJson['summary'] ?? null),
                $this->stringOrNull($resultJson['short_summary'] ?? null),
                $this->stringOrNull($identityCard['summary'] ?? null),
                $this->stringOrNull($reportProfile['short_summary'] ?? null),
                $publicProfile?->excerpt,
                $this->stringOrNull($identityLayer['one_liner'] ?? null)
            ),
            'type_code' => $typeCode,
            'type_name' => $typeName,
            'tagline' => $this->firstNonEmpty(
                $this->stringOrNull($resultJson['tagline'] ?? null),
                $this->stringOrNull($identityCard['tagline'] ?? null),
                $this->stringOrNull($reportProfile['tagline'] ?? null),
                $this->extractTaglineFromTitle($publicProfile?->title)
            ),
            'rarity' => $this->scalarOrNull($resultJson['rarity'] ?? null)
                ?? $this->scalarOrNull($reportProfile['rarity'] ?? null),
            'tags' => $tags,
            'dimensions' => $dimensions,
            'primary_cta_label' => $this->resolvePrimaryCtaLabel($locale),
            'primary_cta_path' => $this->resolvePrimaryCtaPath($scaleCode, $locale, (int) ($attempt->org_id ?? 0)),
        ];
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function buildBigFiveShareSummary(Attempt $attempt, string $locale, array $projection): array
    {
        $isZh = $locale === 'zh-CN';
        $traitVector = is_array($projection['trait_vector'] ?? null) ? $projection['trait_vector'] : [];
        $dominantTraits = is_array($projection['dominant_traits'] ?? null) ? $projection['dominant_traits'] : [];
        $explainabilitySummary = is_array($projection['explainability_summary'] ?? null) ? $projection['explainability_summary'] : [];
        $actionPlanSummary = is_array($projection['action_plan_summary'] ?? null) ? $projection['action_plan_summary'] : [];
        $controlledNarrative = is_array($projection['controlled_narrative_v1'] ?? null) ? $projection['controlled_narrative_v1'] : [];

        $dominantLabels = array_values(array_filter(array_map(
            fn (mixed $item): string => trim((string) (is_array($item) ? ($item['label'] ?? '') : '')),
            $dominantTraits
        )));

        $dimensions = array_values(array_map(
            static fn (array $trait): array => [
                'code' => trim((string) ($trait['key'] ?? '')),
                'label' => trim((string) ($trait['label'] ?? '')),
                'pct' => (int) ($trait['percentile'] ?? 0),
                'state' => trim((string) ($trait['band_label'] ?? $trait['band'] ?? '')),
            ],
            array_filter($traitVector, static fn (mixed $item): bool => is_array($item))
        ));

        $summary = $this->firstNonEmpty(
            $this->stringOrNull($controlledNarrative['narrative_summary'] ?? null),
            $this->stringOrNull($explainabilitySummary['headline'] ?? null),
            $this->stringOrNull($actionPlanSummary['headline'] ?? null),
            $isZh ? '这是一个公开安全的大五人格摘要，只保留核心特质与结果入口。'
                : 'This is a public-safe Big Five summary that keeps only the core traits and entry path.'
        );

        return [
            'scale_code' => 'BIG5_OCEAN',
            'locale' => $locale,
            'title' => $isZh ? '大五人格公开摘要' : 'Big Five public summary',
            'subtitle' => $this->firstNonEmpty(
                $this->stringOrNull($controlledNarrative['narrative_intro'] ?? null),
                $this->stringOrNull($explainabilitySummary['headline'] ?? null)
            ),
            'summary' => $summary,
            'type_code' => 'BIG5',
            'type_name' => $isZh ? '大五人格' : 'Big Five personality',
            'tagline' => $this->firstNonEmpty(
                implode(' · ', array_slice($dominantLabels, 0, 3)),
                $this->stringOrNull($actionPlanSummary['headline'] ?? null)
            ),
            'rarity' => null,
            'tags' => array_slice($dominantLabels, 0, 5),
            'dimensions' => $dimensions,
            'primary_cta_label' => $this->resolvePrimaryCtaLabel($locale),
            'primary_cta_path' => $this->resolvePrimaryCtaPath('BIG5_OCEAN', $locale, (int) ($attempt->org_id ?? 0)),
        ];
    }

    /**
     * @param  array<string,mixed>  $projection
     * @return array<string,mixed>
     */
    private function buildRiasecShareSummary(Attempt $attempt, string $locale, array $projection): array
    {
        $isZh = $locale === 'zh-CN';
        $topCode = trim((string) ($projection['top_code'] ?? ''));
        $scores = is_array($projection['scores_0_100'] ?? null) ? $projection['scores_0_100'] : [];
        $labels = is_array($projection['dimension_labels'] ?? null) ? $projection['dimension_labels'] : [];

        $ranked = [];
        foreach ($scores as $code => $score) {
            $ranked[] = [
                'code' => trim((string) $code),
                'label' => trim((string) ($labels[$code] ?? $code)),
                'pct' => (int) round((float) $score),
                'state' => $this->scoreBand((float) $score, $locale),
            ];
        }
        usort($ranked, static fn (array $a, array $b): int => ((int) ($b['pct'] ?? 0)) <=> ((int) ($a['pct'] ?? 0)));

        $topLabels = array_values(array_filter(array_map(
            static fn (array $item): string => trim((string) ($item['label'] ?? '')),
            array_slice($ranked, 0, 3)
        )));

        return [
            'scale_code' => 'RIASEC',
            'locale' => $locale,
            'title' => $topCode !== ''
                ? ($isZh ? '霍兰德代码 '.$topCode : 'Holland Code '.$topCode)
                : ($isZh ? '霍兰德职业兴趣公开摘要' : 'Holland career interest public summary'),
            'subtitle' => $isZh ? 'RIASEC 职业兴趣画像' : 'RIASEC career interest profile',
            'summary' => $isZh
                ? '这是一份公开安全的霍兰德职业兴趣摘要，只展示核心兴趣代码和六维分数。'
                : 'This is a public-safe Holland interest summary with the core code and six dimension scores.',
            'type_code' => $topCode !== '' ? $topCode : 'RIASEC',
            'type_name' => $topCode !== ''
                ? ($isZh ? '霍兰德代码 '.$topCode : 'Holland Code '.$topCode)
                : ($isZh ? '霍兰德职业兴趣' : 'Holland career interests'),
            'tagline' => implode(' · ', $topLabels),
            'rarity' => null,
            'tags' => array_slice($topLabels, 0, 5),
            'dimensions' => $ranked,
            'primary_cta_label' => $this->resolvePrimaryCtaLabel($locale),
            'primary_cta_path' => $this->resolvePrimaryCtaPath('RIASEC', $locale, (int) ($attempt->org_id ?? 0)),
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function buildEnneagramShareSummary(Attempt $attempt, Result $result, string $locale, array $report): array
    {
        $surface = $this->resolveEnneagramSurfacePayload($attempt, $result, $report);
        $projection = $surface['projection_v2'];
        $reportV2 = $surface['report_v2'];
        $snapshotBinding = $surface['snapshot_binding_v1'];
        $formSummary = $this->enneagramPublicFormSummaryBuilder->summarizeForAttempt($attempt, $result, $locale);
        $classification = is_array($reportV2['classification'] ?? null) ? $reportV2['classification'] : [];
        $top3Module = $this->firstModule($reportV2, 'top3_cards');
        $topTypeCards = is_array(data_get($top3Module, 'content.cards')) ? data_get($top3Module, 'content.cards') : [];
        $all9Profile = is_array(data_get($projection, 'scores.all9_profile')) ? data_get($projection, 'scores.all9_profile') : [];
        $closeCallPair = is_array(data_get($projection, 'dynamics.close_call_pair')) ? data_get($projection, 'dynamics.close_call_pair') : [];
        $scope = (string) ($classification['interpretation_scope'] ?? data_get($projection, 'classification.interpretation_scope', 'clear'));
        $primary = trim((string) (data_get($projection, 'scores.primary_candidate') ?? data_get($topTypeCards, '0.type', '')));
        $second = trim((string) (data_get($projection, 'scores.second_candidate') ?? data_get($topTypeCards, '1.type', '')));
        $third = trim((string) (data_get($projection, 'scores.third_candidate') ?? data_get($topTypeCards, '2.type', '')));
        $shareText = $this->resolveEnneagramShareText($locale, $scope, $primary, $second);
        $confidenceLevel = trim((string) ($classification['confidence_level'] ?? data_get($projection, 'classification.confidence_level', '')));
        $confidenceLabel = trim((string) (data_get($projection, 'classification.confidence_label') ?? $confidenceLevel));
        $generatedAt = $surface['generated_at'];
        $formCode = (string) ($formSummary['form_code'] ?? '');
        $formKind = (string) ($formSummary['form_kind'] ?? '');
        $methodologyVariant = $this->stringOrNull(
            data_get($reportV2, 'form.methodology_variant')
            ?? data_get($projection, 'form.methodology_variant')
        );
        if ($methodologyVariant === null) {
            $methodologyVariant = match ($formCode) {
                'enneagram_likert_105' => 'e105',
                'enneagram_forced_choice_144' => 'fc144',
                default => null,
            };
        }

        $publicSummary = [
            'version' => 'enneagram.public_summary.v1',
            'public_surface_version' => 'enneagram.public_surface.v1',
            'scale_code' => 'ENNEAGRAM',
            'form_code' => $formCode !== '' ? $formCode : null,
            'form_label' => $formSummary['label'] ?? null,
            'form_kind' => $formKind !== '' ? $formKind : null,
            'methodology_variant' => $methodologyVariant,
            'primary_candidate' => $primary !== '' ? $primary : null,
            'second_candidate' => $second !== '' ? $second : null,
            'third_candidate' => $third !== '' ? $third : null,
            'top_types' => array_values(array_map(
                static fn (array $row): array => [
                    'type' => trim((string) ($row['type'] ?? '')),
                    'candidate_role' => trim((string) ($row['candidate_role'] ?? '')),
                    'display_score' => $row['display_score'] ?? null,
                ],
                array_filter($topTypeCards, static fn (mixed $row): bool => is_array($row))
            )),
            'all9_profile_mini' => array_values(array_map(
                static fn (array $row): array => [
                    'type' => trim((string) ($row['type'] ?? '')),
                    'rank' => isset($row['rank']) ? (int) $row['rank'] : null,
                    'display_score' => $row['display_score'] ?? null,
                ],
                array_filter($all9Profile, static fn (mixed $row): bool => is_array($row))
            )),
            'confidence_level' => $confidenceLevel !== '' ? $confidenceLevel : null,
            'confidence_label' => $confidenceLabel !== '' ? $confidenceLabel : null,
            'interpretation_scope' => $scope !== '' ? $scope : null,
            'interpretation_reason' => $classification['interpretation_reason'] ?? data_get($projection, 'classification.interpretation_reason'),
            'close_call_pair' => [
                'pair_key' => $this->stringOrNull($closeCallPair['pair_key'] ?? null),
                'type_a' => $this->stringOrNull($closeCallPair['type_a'] ?? null),
                'type_b' => $this->stringOrNull($closeCallPair['type_b'] ?? null),
            ],
            'dominance_gap_abs' => data_get($projection, 'classification.dominance_gap_abs'),
            'dominance_gap_pct' => data_get($projection, 'classification.dominance_gap_pct'),
            'compare_compatibility_group' => data_get($projection, 'methodology.compare_compatibility_group')
                ?? data_get($snapshotBinding, 'compare_compatibility_group'),
            'cross_form_comparable' => false,
            'interpretation_context_id' => data_get($reportV2, 'provenance.interpretation_context_id')
                ?? data_get($projection, 'content_binding.interpretation_context_id')
                ?? data_get($snapshotBinding, 'interpretation_context_id'),
            'registry_release_hash' => data_get($reportV2, 'registry.registry_release_hash')
                ?? data_get($reportV2, 'provenance.registry_release_hash'),
            'content_release_hash' => data_get($reportV2, 'provenance.content_release_hash')
                ?? data_get($projection, 'content_binding.content_release_hash')
                ?? data_get($snapshotBinding, 'content_release_hash'),
            'content_snapshot_status' => data_get($reportV2, 'provenance.content_snapshot_status')
                ?? data_get($projection, 'content_binding.content_snapshot_status')
                ?? data_get($snapshotBinding, 'content_snapshot_status'),
            'report_schema_version' => data_get($reportV2, 'schema_version')
                ?? data_get($snapshotBinding, 'report_schema_version'),
            'projection_version' => data_get($projection, 'algorithmic_meta.projection_version')
                ?? data_get($snapshotBinding, 'projection_version'),
            'generated_at' => $generatedAt,
            'summary_text' => $shareText,
        ];

        return [
            'scale_code' => 'ENNEAGRAM',
            'locale' => $locale,
            'title' => $this->resolveEnneagramShareTitle($locale, $scope, $primary, $second),
            'subtitle' => (string) ($formSummary['label'] ?? ($locale === 'zh-CN' ? '九型人格结果摘要' : 'Enneagram result summary')),
            'summary' => $shareText,
            'type_code' => $primary !== '' ? $primary : 'ENNEAGRAM',
            'type_name' => $primary !== ''
                ? ($locale === 'zh-CN' ? $primary.'号候选' : 'Type '.$primary.' candidate')
                : ($locale === 'zh-CN' ? '九型人格' : 'Enneagram'),
            'tagline' => $confidenceLabel !== '' ? $confidenceLabel : (string) ($formSummary['label'] ?? ''),
            'rarity' => null,
            'tags' => array_values(array_filter([$primary, $second, $third])),
            'dimensions' => $publicSummary['all9_profile_mini'],
            'primary_cta_label' => $this->resolvePrimaryCtaLabel($locale),
            'primary_cta_path' => $this->resolvePrimaryCtaPath('ENNEAGRAM', $locale, (int) ($attempt->org_id ?? 0)),
            'contract' => $publicSummary,
        ];
    }

    private function scoreBand(float $score, string $locale): string
    {
        $isZh = $locale === 'zh-CN';
        if ($score >= 67) {
            return $isZh ? '高' : 'high';
        }
        if ($score >= 34) {
            return $isZh ? '中' : 'medium';
        }

        return $isZh ? '低' : 'low';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPublicSafeReportSnapshot(Attempt $attempt, Result $result): array
    {
        if (strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'ENNEAGRAM') {
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

                return $report;
            }

            return [];
        }

        if (strtoupper(trim((string) ($attempt->scale_code ?? ''))) !== 'MBTI') {
            return [];
        }

        try {
            $payload = $this->reportComposer->composeVariant(
                $attempt,
                ReportAccess::VARIANT_FREE,
                [
                    'org_id' => (int) ($attempt->org_id ?? 0),
                    'persist' => false,
                    'modules_allowed' => ReportAccess::defaultModulesAllowedForLocked($attempt->scale_code),
                ],
                $result
            );
        } catch (\Throwable) {
            return [];
        }

        if (($payload['ok'] ?? false) !== true || ! is_array($payload['report'] ?? null)) {
            return [];
        }

        return $payload['report'];
    }

    private function resolvePublicProfile(
        string $typeCode,
        int $orgId,
        string $scaleCode,
        string $locale
    ): ?PersonalityProfile {
        $baseTypeCode = $this->baseTypeCode($typeCode);
        if ($baseTypeCode === '') {
            return null;
        }

        return $this->personalityProfileService->getPublicProfileByType(
            $baseTypeCode,
            $orgId,
            $scaleCode,
            $locale
        );
    }

    private function buildShareUrl(string $shareId): string
    {
        return rtrim((string) config('app.frontend_url', 'http://localhost'), '/').'/share/'.$shareId;
    }

    private function normalizeLocale(string $locale): string
    {
        return trim($locale) === 'zh-CN' ? 'zh-CN' : 'en';
    }

    private function baseTypeCode(string $typeCode): string
    {
        return strtoupper(trim((string) preg_replace('/-[A-Z]$/', '', $typeCode)));
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array{report_v2:array<string,mixed>,projection_v2:array<string,mixed>,snapshot_binding_v1:array<string,mixed>,generated_at:?string}
     */
    private function resolveEnneagramSurfacePayload(Attempt $attempt, Result $result, array $report): array
    {
        $snapshot = ReportSnapshot::query()
            ->where('org_id', (int) ($attempt->org_id ?? 0))
            ->where('attempt_id', (string) $attempt->id)
            ->where('status', 'ready')
            ->first();

        $snapshotReport = [];
        if ($snapshot instanceof ReportSnapshot) {
            $snapshotReport = is_array($snapshot->report_full_json) ? $snapshot->report_full_json : [];
            if ($snapshotReport === []) {
                $snapshotReport = is_array($snapshot->report_json) ? $snapshot->report_json : [];
            }
        }

        $resolvedReport = $snapshotReport !== [] ? $snapshotReport : $report;
        $resultJson = $this->normalizeArray($result->result_json ?? null);

        return [
            'report_v2' => $this->extractEnneagramReportV2($resolvedReport),
            'projection_v2' => $this->extractEnneagramProjectionV2($resolvedReport, $resultJson),
            'snapshot_binding_v1' => $this->extractEnneagramSnapshotBinding($resolvedReport),
            'generated_at' => $snapshot instanceof ReportSnapshot
                ? ($snapshot->updated_at?->toIso8601String() ?? $snapshot->created_at?->toIso8601String())
                : $this->stringOrNull(data_get($resultJson, 'computed_at')),
        ];
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
    private function extractEnneagramSnapshotBinding(array $report): array
    {
        $binding = data_get($report, '_meta.snapshot_binding_v1');

        return is_array($binding) ? $binding : [];
    }

    /**
     * @param  array<string,mixed>  $reportV2
     * @return array<string,mixed>
     */
    private function firstModule(array $reportV2, string $moduleKey): array
    {
        foreach ((array) ($reportV2['modules'] ?? []) as $module) {
            if (is_array($module) && (string) ($module['module_key'] ?? '') === $moduleKey) {
                return $module;
            }
        }

        foreach ((array) ($reportV2['pages'] ?? []) as $page) {
            foreach ((array) ($page['modules'] ?? []) as $module) {
                if (is_array($module) && (string) ($module['module_key'] ?? '') === $moduleKey) {
                    return $module;
                }
            }
        }

        return [];
    }

    private function resolveEnneagramShareTitle(string $locale, string $scope, string $primary, string $second): string
    {
        return match ($scope) {
            'close_call' => $locale === 'zh-CN'
                ? sprintf('九型结果摘要｜%s 与 %s 接近', $primary !== '' ? $primary.'号' : '候选', $second !== '' ? $second.'号' : '次候选')
                : sprintf('Enneagram summary | %s vs %s', $primary !== '' ? 'Type '.$primary : 'Top candidate', $second !== '' ? 'Type '.$second : 'Second candidate'),
            'diffuse' => $locale === 'zh-CN' ? '九型结果摘要｜分散结构' : 'Enneagram summary | diffuse profile',
            'low_quality' => $locale === 'zh-CN' ? '九型结果摘要｜解释边界较宽' : 'Enneagram summary | wider interpretation boundary',
            default => $locale === 'zh-CN'
                ? sprintf('九型结果摘要｜%s号候选', $primary !== '' ? $primary : '主')
                : sprintf('Enneagram summary | %s', $primary !== '' ? 'Type '.$primary.' candidate' : 'Top candidate'),
        };
    }

    private function resolveEnneagramShareText(string $locale, string $scope, string $primary, string $second): string
    {
        return match ($scope) {
            'close_call' => $locale === 'zh-CN'
                ? sprintf('我在 FermatMind 的九型结果显示：我可能在 %s 号与 %s 号之间摇摆。报告不会强行给出单一标签，而是保留两型辨析与后续观察线索。', $primary !== '' ? $primary : '主候选', $second !== '' ? $second : '次候选')
                : sprintf('My FermatMind Enneagram result suggests I may be oscillating between Type %s and Type %s. The report keeps the distinction cautious instead of forcing a single label.', $primary !== '' ? $primary : 'A', $second !== '' ? $second : 'B'),
            'diffuse' => $locale === 'zh-CN'
                ? '我在 FermatMind 的九型结果呈现分散结构。系统建议先观察 Top3 和整体分布，而不是急着固定成单一类型。'
                : 'My FermatMind Enneagram result shows a diffuse pattern. The system suggests watching the Top 3 and the overall profile before fixing on one type.',
            'low_quality' => $locale === 'zh-CN'
                ? '我在 FermatMind 的九型结果可以阅读，但系统提示解释边界较宽。它更适合作为初步观察线索，而不是最终定型。'
                : 'My FermatMind Enneagram result is readable, but the interpretation boundary is wider. It is better used as an observation cue than a final label.',
            default => $locale === 'zh-CN'
                ? sprintf('我在 FermatMind 的九型结果显示：我最可能是 %s 号。结果清晰度较高，但仍建议把它当成持续观察自己的框架。', $primary !== '' ? $primary : '主候选')
                : sprintf('My FermatMind Enneagram result suggests I am most likely Type %s. The signal is relatively clear, but it should still be used as a framework for continued self-observation.', $primary !== '' ? $primary : 'A'),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeArray(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (! is_string($payload) || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function scalarOrNull(mixed $value): string|int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = trim($value);

            return $normalized === '' ? null : $normalized;
        }

        return null;
    }

    private function firstNonEmpty(?string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $normalized = trim($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function extractTaglineFromTitle(?string $title): ?string
    {
        $normalized = trim((string) $title);
        if ($normalized === '') {
            return null;
        }

        $parts = preg_split('/\s[-–—]\s/u', $normalized);
        if (! is_array($parts) || count($parts) < 2) {
            return null;
        }

        $tagline = trim((string) end($parts));

        return $tagline === '' ? null : $tagline;
    }

    /**
     * @param  array<string, mixed>  $resultJson
     * @param  array<string, mixed>  $reportProfile
     * @param  array<string, mixed>  $identityCard
     * @param  list<array<string, mixed>>  $dimensions
     * @return list<string>
     */
    private function resolvePublicTags(
        array $resultJson,
        array $reportProfile,
        array $identityCard,
        array $dimensions
    ): array {
        $sources = [
            $this->sanitizeTags($resultJson['tags'] ?? null),
            $this->sanitizeTags($resultJson['keywords'] ?? null),
            $this->sanitizeTags($reportProfile['keywords'] ?? null),
            $this->sanitizeTags($identityCard['tags'] ?? null),
        ];

        foreach ($sources as $tags) {
            if ($tags !== []) {
                return $tags;
            }
        }

        $derived = [];
        foreach ($dimensions as $dimension) {
            $label = $this->stringOrNull($dimension['side_label'] ?? null);
            if ($label !== null) {
                $derived[] = $label;
            }
        }

        return array_values(array_slice(array_unique($derived), 0, 5));
    }

    /**
     * @return list<string>
     */
    private function sanitizeTags(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $tags = [];
        foreach ($value as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $tag = trim((string) $item);
            if ($tag === '' || str_contains($tag, ':')) {
                continue;
            }

            $tags[$tag] = true;
            if (count($tags) >= 5) {
                break;
            }
        }

        return array_keys($tags);
    }

    /**
     * @param  array<string, mixed>  $scoresPct
     * @param  array<string, mixed>  $axisStates
     * @return list<array<string, mixed>>
     */
    private function buildDimensions(array $scoresPct, array $axisStates, string $locale): array
    {
        $definitions = $this->axisDefinitions($locale);
        $dimensions = [];

        foreach ($definitions as $code => $definition) {
            if (! isset($scoresPct[$code]) || ! is_numeric($scoresPct[$code])) {
                continue;
            }

            $rawPct = max(0, min(100, (int) round((float) $scoresPct[$code])));
            $primarySide = $rawPct >= 50 ? $definition['first_code'] : $definition['second_code'];
            $primaryPct = $rawPct >= 50 ? $rawPct : (100 - $rawPct);

            $dimensions[] = [
                'code' => $code,
                'label' => $definition['axis_label'],
                'side' => $primarySide,
                'side_label' => $definition['side_labels'][$primarySide],
                'pct' => $primaryPct,
                'state' => $this->stringOrNull($axisStates[$code] ?? null) ?? 'moderate',
            ];
        }

        return $dimensions;
    }

    /**
     * @return array<string, array{
     *   axis_label:string,
     *   first_code:string,
     *   second_code:string,
     *   side_labels:array<string, string>
     * }>
     */
    private function axisDefinitions(string $locale): array
    {
        $zh = $locale === 'zh-CN';

        return [
            'EI' => [
                'axis_label' => $zh ? '能量方向' : 'Energy',
                'first_code' => 'E',
                'second_code' => 'I',
                'side_labels' => [
                    'E' => $zh ? '外倾' : 'Extraversion',
                    'I' => $zh ? '内倾' : 'Introversion',
                ],
            ],
            'SN' => [
                'axis_label' => $zh ? '信息偏好' : 'Information',
                'first_code' => 'S',
                'second_code' => 'N',
                'side_labels' => [
                    'S' => $zh ? '实感' : 'Sensing',
                    'N' => $zh ? '直觉' : 'Intuition',
                ],
            ],
            'TF' => [
                'axis_label' => $zh ? '决策偏好' : 'Decision',
                'first_code' => 'T',
                'second_code' => 'F',
                'side_labels' => [
                    'T' => $zh ? '思考' : 'Thinking',
                    'F' => $zh ? '情感' : 'Feeling',
                ],
            ],
            'JP' => [
                'axis_label' => $zh ? '生活方式' : 'Lifestyle',
                'first_code' => 'J',
                'second_code' => 'P',
                'side_labels' => [
                    'J' => $zh ? '判断' : 'Judging',
                    'P' => $zh ? '感知' : 'Perceiving',
                ],
            ],
            'AT' => [
                'axis_label' => $zh ? '稳定度' : 'Identity',
                'first_code' => 'A',
                'second_code' => 'T',
                'side_labels' => [
                    'A' => $zh ? '果断' : 'Assertive',
                    'T' => $zh ? '敏感' : 'Turbulent',
                ],
            ],
        ];
    }

    private function resolvePrimaryCtaLabel(string $locale): string
    {
        return $locale === 'zh-CN' ? '开始测试' : 'Take the test';
    }

    private function resolvePrimaryCtaPath(string $scaleCode, string $locale, int $orgId): string
    {
        $row = $this->scaleRegistry->getByCode($scaleCode, $orgId);
        if (! is_array($row) && $orgId > 0) {
            $row = $this->scaleRegistry->getByCode($scaleCode, 0);
        }

        $slug = trim((string) ($row['primary_slug'] ?? ''));
        if ($slug === '') {
            $slug = strtolower(trim($scaleCode)) ?: 'mbti-personality-test-16-personality-types';
        }

        $segment = $locale === 'zh-CN' ? 'zh' : 'en';

        return '/'.$segment.'/tests/'.rawurlencode($slug);
    }

    private function buildCanonicalPayload(
        ?Share $share,
        Attempt $attempt,
        Result $result,
        ?string $shareId,
        ?string $createdAt
    ): array {
        $publicSafeReport = $this->buildPublicSafeReportSnapshot($attempt, $result);
        $normalizedScaleCode = strtoupper(trim((string) ($attempt->scale_code ?? $result->scale_code ?? 'MBTI')));
        $normalizedLocale = $this->normalizeLocale((string) ($attempt->locale ?? config('content_packs.default_locale', 'zh-CN')));
        $big5Projection = $normalizedScaleCode === 'BIG5_OCEAN'
            ? $this->bigFivePublicProjectionService->buildFromResult($result, $normalizedLocale)
            : [];
        $riasecProjection = $normalizedScaleCode === 'RIASEC'
            ? $this->riasecPublicProjectionService->buildFromResult($result, $normalizedLocale)
            : [];
        $summary = $this->buildShareSummary($share, $attempt, $result, $publicSafeReport, $big5Projection, $riasecProjection);
        $resolvedShareId = trim((string) $shareId);
        $locale = (string) ($summary['locale'] ?? 'zh-CN');
        $scaleCode = strtoupper((string) ($summary['scale_code'] ?? 'MBTI'));
        $compareEnabled = $scaleCode === 'MBTI';
        $readContract = is_array(data_get($publicSafeReport, '_meta.personalization.read_contract_v1'))
            ? data_get($publicSafeReport, '_meta.personalization.read_contract_v1')
            : null;
        $privacyContract = $this->extractMbtiPrivacyContract($publicSafeReport, $attempt, $locale);
        $payload = [
            'share_id' => $resolvedShareId !== '' ? $resolvedShareId : null,
            'share_url' => $resolvedShareId !== '' ? $this->buildLocalizedShareUrl($resolvedShareId, $locale) : null,
            'attempt_id' => (string) $attempt->id,
            'created_at' => $createdAt,
            'org_id' => (int) ($attempt->org_id ?? 0),
            'content_package_version' => (string) ($attempt->content_package_version ?? $result->content_package_version ?? ''),
            'type_code' => $summary['type_code'],
            'type_name' => $summary['type_name'],
            'id' => $resolvedShareId !== '' ? $resolvedShareId : null,
            'scale_code' => $summary['scale_code'],
            'locale' => $locale,
            'title' => $summary['title'],
            'subtitle' => $summary['subtitle'],
            'summary' => $summary['summary'],
            'tagline' => $summary['tagline'],
            'rarity' => $summary['rarity'],
            'tags' => $summary['tags'],
            'dimensions' => $summary['dimensions'],
            'primary_cta_label' => $summary['primary_cta_label'],
            'primary_cta_path' => $summary['primary_cta_path'],
            'compare_enabled' => $compareEnabled,
            'compare_cta_label' => $compareEnabled
                ? $this->resolveCompareCtaLabel($locale)
                : null,
        ];

        if (is_array($readContract)) {
            $payload['mbti_read_contract_v1'] = $readContract;
        }
        if ($privacyContract !== []) {
            $payload['mbti_privacy_contract_v1'] = $privacyContract;
        }

        if ($compareEnabled) {
            $mbtiPersonalization = is_array(data_get($publicSafeReport, '_meta.personalization'))
                ? data_get($publicSafeReport, '_meta.personalization')
                : [];
            $payload['mbti_public_summary_v1'] = $this->mbtiPublicSummaryV1Builder->buildFromSharePayload(
                $payload,
                $publicSafeReport,
                $locale
            );
            $payload['mbti_public_projection_v1'] = $this->mbtiPublicProjectionService->buildForSharePayload(
                $payload,
                $locale,
                (int) ($attempt->org_id ?? 0),
                $publicSafeReport,
                $this->normalizeArray($result->result_json ?? null)
            );
            $payload['mbti_continuity_v1'] = $this->extractMbtiContinuity($publicSafeReport);
            foreach ([
                'controlled_narrative_v1',
                'cultural_calibration_v1',
                'comparative_v1',
                'working_life_v1',
                'cross_assessment_v1',
            ] as $contractKey) {
                if (is_array($mbtiPersonalization[$contractKey] ?? null)) {
                    $payload[$contractKey === 'cross_assessment_v1' ? 'mbti_cross_assessment_v1' : $contractKey] = $mbtiPersonalization[$contractKey];
                }
            }
            $payload = $this->applyMbtiProjectionAliases($payload);
        } elseif ($scaleCode === 'BIG5_OCEAN' && $big5Projection !== []) {
            $payload['big5_public_projection_v1'] = $big5Projection;
            foreach ([
                'controlled_narrative_v1',
                'cultural_calibration_v1',
                'comparative_v1',
            ] as $contractKey) {
                if (is_array($big5Projection[$contractKey] ?? null)) {
                    $payload[$contractKey] = $big5Projection[$contractKey];
                }
            }
        } elseif ($scaleCode === 'ENNEAGRAM') {
            $enneagramPublicSummary = is_array($summary['contract'] ?? null) ? $summary['contract'] : [];
            if ($enneagramPublicSummary !== []) {
                $payload['enneagram_public_summary_v1'] = $enneagramPublicSummary;
            }
        } elseif ($scaleCode === 'RIASEC' && $riasecProjection !== []) {
            $payload['riasec_public_projection_v1'] = $riasecProjection;
        }

        $payload['public_surface_v1'] = $this->buildPublicSurfaceContract(
            $payload,
            $locale,
            $scaleCode,
            $resolvedShareId,
            $big5Projection,
            $publicSafeReport
        );
        $payload['seo_surface_v1'] = $this->buildSeoSurfaceContract($payload, $locale, $scaleCode, $resolvedShareId);
        $payload['landing_surface_v1'] = $this->buildLandingSurfaceContract($payload, $locale, $scaleCode);
        $payload['answer_surface_v1'] = $this->buildAnswerSurfaceContract($payload, $locale, $scaleCode);
        $payload['insight_graph_v1'] = $this->insightGraphContractService->buildForShare(
            $payload,
            is_array($payload['public_surface_v1'] ?? null) ? $payload['public_surface_v1'] : []
        );
        $payload['embed_surface_v1'] = $this->insightGraphContractService->buildEmbedSurface(
            is_array($payload['insight_graph_v1'] ?? null) ? $payload['insight_graph_v1'] : [],
            is_array($payload['public_surface_v1'] ?? null) ? $payload['public_surface_v1'] : [],
            $payload
        );
        $payload['partner_read_v1'] = $this->partnerReadContractService->buildForPublicShare(
            is_array($payload['insight_graph_v1'] ?? null) ? $payload['insight_graph_v1'] : [],
            is_array($payload['public_surface_v1'] ?? null) ? $payload['public_surface_v1'] : []
        );
        $payload['widget_surface_v1'] = $this->widgetSurfaceContractService->buildForPublicShare(
            is_array($payload['embed_surface_v1'] ?? null) ? $payload['embed_surface_v1'] : [],
            is_array($payload['partner_read_v1'] ?? null) ? $payload['partner_read_v1'] : [],
            is_array($payload['public_surface_v1'] ?? null) ? $payload['public_surface_v1'] : []
        );

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $big5Projection
     * @param  array<string,mixed>  $publicSafeReport
     * @return array<string,mixed>
     */
    private function buildPublicSurfaceContract(
        array $payload,
        string $locale,
        string $scaleCode,
        string $shareId,
        array $big5Projection,
        array $publicSafeReport
    ): array {
        $discoverabilityKeys = ['public_safe_summary', 'share_landing', 'return_to_test'];
        $continueReadingKeys = [];

        if ($scaleCode === 'MBTI') {
            $continueReadingKeys = array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                (array) data_get($payload, 'mbti_continuity_v1.recommended_resume_keys', [])
            )));
            if ($continueReadingKeys === []) {
                $continueReadingKeys = ['traits.why_this_type', 'growth.next_actions'];
            }
            $discoverabilityKeys[] = 'mbti_public_summary';
            if ($continueReadingKeys !== []) {
                $discoverabilityKeys[] = 'continue_here';
            }
            if (is_array($payload['working_life_v1'] ?? null)) {
                $discoverabilityKeys[] = 'working_life';
            }
            if (is_array($payload['comparative_v1'] ?? null)) {
                $discoverabilityKeys[] = 'comparative';
            }
            if (is_array($payload['controlled_narrative_v1'] ?? null)) {
                $discoverabilityKeys[] = 'controlled_narrative';
            }
            if (($payload['compare_enabled'] ?? false) === true) {
                $discoverabilityKeys[] = 'compare_invite';
            }
        } elseif ($scaleCode === 'BIG5_OCEAN') {
            $continueReadingKeys = array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                (array) ($big5Projection['ordered_section_keys'] ?? [])
            )));
            $continueReadingKeys = array_slice($continueReadingKeys, 0, 3);
            if ($continueReadingKeys === []) {
                $continueReadingKeys = ['traits.overview', 'growth.next_actions'];
            }
            $discoverabilityKeys[] = 'big5_foundation_summary';
            if (is_array($payload['comparative_v1'] ?? null)) {
                $discoverabilityKeys[] = 'comparative';
            }
            if (is_array($payload['controlled_narrative_v1'] ?? null)) {
                $discoverabilityKeys[] = 'controlled_narrative';
            }
        } elseif ($scaleCode === 'RIASEC') {
            $continueReadingKeys = ['riasec.summary', 'riasec.scores'];
            $discoverabilityKeys[] = 'riasec_interest_summary';
        }

        return $this->publicSurfaceContractService->build([
            'entry_surface' => match ($scaleCode) {
                'BIG5_OCEAN' => 'big5_share_landing',
                'RIASEC' => 'riasec_share_landing',
                default => 'mbti_share_landing',
            },
            'discoverability_keys' => $discoverabilityKeys,
            'continue_reading_keys' => $continueReadingKeys,
            'canonical_url' => $shareId !== '' ? $this->buildLocalizedShareUrl($shareId, $locale) : null,
            'robots_policy' => 'noindex,follow',
            'attribution_scope' => 'share_public_surface',
            'scale_code' => $scaleCode,
            'locale' => $locale,
            'fingerprint_seed' => [
                'title' => $payload['title'] ?? null,
                'summary' => $payload['summary'] ?? null,
                'type_code' => $payload['type_code'] ?? null,
                'content_package_version' => $payload['content_package_version'] ?? null,
                'read_contract_version' => data_get($publicSafeReport, '_meta.personalization.read_contract_v1.version'),
                'narrative_fingerprint' => data_get($payload, 'controlled_narrative_v1.narrative_fingerprint'),
                'comparative_fingerprint' => data_get($payload, 'comparative_v1.comparative_fingerprint'),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function buildLandingSurfaceContract(array $payload, string $locale, string $scaleCode): array
    {
        $segment = $this->frontendLocaleSegment($locale);
        $primaryCtaPath = $this->stringOrNull($payload['primary_cta_path'] ?? null);
        $discoverabilityKeys = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) data_get($payload, 'public_surface_v1.discoverability_keys', [])
        )));
        $continueReadingKeys = array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            (array) data_get($payload, 'public_surface_v1.continue_reading_keys', [])
        )));
        $contentContinueTarget = match ($scaleCode) {
            'MBTI' => '/'.$segment.'/topics/mbti',
            'BIG5_OCEAN' => '/'.$segment.'/articles',
            default => '/'.$segment.'/tests',
        };

        return $this->landingSurfaceContractService->build([
            'landing_scope' => 'public_share_safe',
            'entry_surface' => match ($scaleCode) {
                'BIG5_OCEAN' => 'big5_share_entry',
                'RIASEC' => 'riasec_share_entry',
                default => 'mbti_share_entry',
            },
            'entry_type' => match ($scaleCode) {
                'BIG5_OCEAN' => 'big5_share_summary',
                'RIASEC' => 'riasec_share_summary',
                default => 'mbti_share_summary',
            },
            'summary_blocks' => [
                [
                    'key' => 'share_summary',
                    'title' => $this->stringOrNull($payload['title'] ?? null),
                    'body' => $this->stringOrNull($payload['summary'] ?? null),
                    'kind' => 'answer_first',
                ],
            ],
            'discoverability_keys' => $discoverabilityKeys,
            'continue_reading_keys' => $continueReadingKeys,
            'start_test_target' => $primaryCtaPath,
            'result_resume_target' => null,
            'content_continue_target' => $contentContinueTarget,
            'cta_bundle' => [
                [
                    'key' => 'start_test',
                    'label' => $this->stringOrNull($payload['primary_cta_label'] ?? null) ?? $this->resolvePrimaryCtaLabel($locale),
                    'href' => $primaryCtaPath,
                    'kind' => 'start_test',
                ],
                [
                    'key' => 'continue_public_content',
                    'label' => $locale === 'zh-CN' ? '继续阅读' : 'Continue reading',
                    'href' => $contentContinueTarget,
                    'kind' => 'content_continue',
                ],
            ],
            'indexability_state' => 'noindex',
            'attribution_scope' => 'share_public_surface',
            'seo_surface_ref' => $this->stringOrNull(data_get($payload, 'seo_surface_v1.metadata_fingerprint')),
            'public_surface_ref' => $this->stringOrNull(data_get($payload, 'public_surface_v1.public_summary_fingerprint')),
            'surface_family' => 'share_public_safe',
            'primary_content_ref' => $this->stringOrNull($payload['type_code'] ?? null),
            'related_surface_keys' => $discoverabilityKeys,
            'share_safety_state' => 'public_share_safe',
            'runtime_artifact_ref' => $this->stringOrNull(data_get($payload, 'seo_surface_v1.runtime_artifact_ref')),
            'fingerprint_seed' => [
                'scale_code' => $scaleCode,
                'locale' => $locale,
                'share_id' => $this->stringOrNull($payload['share_id'] ?? null),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function buildAnswerSurfaceContract(array $payload, string $locale, string $scaleCode): array
    {
        $summary = $this->stringOrNull($payload['summary'] ?? null);
        $title = $this->stringOrNull($payload['title'] ?? null);
        $compareBlocks = [];
        $nextStepBlocks = [];
        $evidenceRefs = array_values(array_filter([
            $this->stringOrNull(data_get($payload, 'seo_surface_v1.metadata_fingerprint')),
            $this->stringOrNull(data_get($payload, 'landing_surface_v1.landing_fingerprint')),
            $this->stringOrNull(data_get($payload, 'public_surface_v1.public_summary_fingerprint')),
        ]));

        if ($scaleCode === 'MBTI') {
            $comparativeLabel = $this->stringOrNull(data_get($payload, 'comparative_v1.cohort_relative_position.label'));
            $comparativeSummary = $this->stringOrNull(data_get($payload, 'comparative_v1.cohort_relative_position.summary'));
            if ($comparativeLabel !== null || $comparativeSummary !== null) {
                $compareBlocks[] = [
                    'key' => 'comparative_position',
                    'title' => $comparativeLabel ?? ($locale === 'zh-CN' ? '相对位置' : 'Relative position'),
                    'body' => $comparativeSummary,
                    'kind' => 'comparative',
                ];
                $evidenceRefs[] = 'comparative_v1';
            }

            $workingLifeFocus = $this->stringOrNull(data_get($payload, 'working_life_v1.career_focus_key'));
            if ($workingLifeFocus !== null) {
                $nextStepBlocks[] = [
                    'key' => 'working_life_focus',
                    'title' => $locale === 'zh-CN' ? '当前重点' : 'Current focus',
                    'body' => $workingLifeFocus,
                    'href' => null,
                    'kind' => 'next_focus',
                ];
                $evidenceRefs[] = 'working_life_v1';
            }
        } elseif ($scaleCode === 'BIG5_OCEAN') {
            $headline = $this->stringOrNull(data_get($payload, 'controlled_narrative_v1.narrative_summary'));
            if ($headline !== null) {
                $compareBlocks[] = [
                    'key' => 'narrative_summary',
                    'title' => $locale === 'zh-CN' ? '大五摘要' : 'Big Five summary',
                    'body' => $headline,
                    'kind' => 'narrative',
                ];
                $evidenceRefs[] = 'controlled_narrative_v1';
            }
        }

        $nextStepBlocks = array_merge(
            $nextStepBlocks,
            $this->answerSurfaceContractService->buildNextStepBlocksFromCtas(
                is_array(data_get($payload, 'landing_surface_v1.cta_bundle')) ? data_get($payload, 'landing_surface_v1.cta_bundle') : [],
                2
            )
        );

        return $this->answerSurfaceContractService->build([
            'answer_scope' => 'public_share_safe',
            'surface_type' => match ($scaleCode) {
                'BIG5_OCEAN' => 'big5_share_public_safe',
                'RIASEC' => 'riasec_share_public_safe',
                default => 'mbti_share_public_safe',
            },
            'summary_blocks' => [
                [
                    'key' => 'share_summary',
                    'title' => $title,
                    'body' => $summary,
                    'kind' => 'public_safe_summary',
                ],
            ],
            'faq_blocks' => [],
            'compare_blocks' => $compareBlocks,
            'next_step_blocks' => $nextStepBlocks,
            'evidence_refs' => $evidenceRefs,
            'public_safety_state' => 'public_share_safe',
            'indexability_state' => 'noindex',
            'attribution_scope' => 'share_public_surface',
            'seo_surface_ref' => $this->stringOrNull(data_get($payload, 'seo_surface_v1.metadata_fingerprint')),
            'landing_surface_ref' => $this->stringOrNull(data_get($payload, 'landing_surface_v1.landing_fingerprint')),
            'public_surface_ref' => $this->stringOrNull(data_get($payload, 'public_surface_v1.public_summary_fingerprint')),
            'primary_content_ref' => $this->stringOrNull($payload['type_code'] ?? null),
            'related_surface_keys' => (array) data_get($payload, 'public_surface_v1.discoverability_keys', []),
            'fingerprint_seed' => [
                'scale_code' => $scaleCode,
                'locale' => $locale,
                'share_id' => $this->stringOrNull($payload['share_id'] ?? null),
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function buildSeoSurfaceContract(array $payload, string $locale, string $scaleCode, string $shareId): array
    {
        $canonicalUrl = trim((string) data_get($payload, 'public_surface_v1.canonical_url', ''));
        $canonicalUrl = $canonicalUrl !== '' ? $canonicalUrl : ($shareId !== '' ? $this->buildLocalizedShareUrl($shareId, $locale) : null);

        $title = trim((string) ($payload['title'] ?? ''));
        $summary = trim((string) ($payload['summary'] ?? ''));
        $typeCode = trim((string) ($payload['type_code'] ?? ''));
        $surfaceType = match ($scaleCode) {
            'BIG5_OCEAN' => 'big5_share_public_safe',
            'RIASEC' => 'riasec_share_public_safe',
            default => 'mbti_share_public_safe',
        };

        return $this->seoSurfaceContractService->build([
            'metadata_scope' => 'public_share_safe',
            'surface_type' => $surfaceType,
            'canonical_url' => $canonicalUrl,
            'robots_policy' => data_get($payload, 'public_surface_v1.robots_policy', 'noindex,follow'),
            'title' => $title !== '' ? $title : $typeCode,
            'description' => $summary !== '' ? $summary : trim((string) ($payload['subtitle'] ?? '')),
            'og_payload' => [
                'title' => $title !== '' ? $title : $typeCode,
                'description' => $summary !== '' ? $summary : trim((string) ($payload['subtitle'] ?? '')),
                'image' => $shareId !== '' ? $this->buildLocalizedShareOgUrl($shareId) : null,
                'type' => 'website',
                'url' => $canonicalUrl,
            ],
            'twitter_payload' => [
                'card' => 'summary_large_image',
                'title' => $title !== '' ? $title : $typeCode,
                'description' => $summary !== '' ? $summary : trim((string) ($payload['subtitle'] ?? '')),
                'image' => $shareId !== '' ? $this->buildLocalizedShareOgUrl($shareId) : null,
            ],
            'structured_data' => [],
            'indexability_state' => 'noindex',
            'sitemap_state' => 'excluded',
            'llms_exposure_state' => 'withhold',
            'share_safety_state' => 'public_share_safe',
            'public_summary_fingerprint' => data_get($payload, 'public_surface_v1.public_summary_fingerprint'),
            'fingerprint_seed' => [
                'scale_code' => $scaleCode,
                'locale' => $locale,
                'type_code' => $typeCode,
                'entry_surface' => data_get($payload, 'public_surface_v1.entry_surface'),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyMbtiProjectionAliases(array $payload): array
    {
        $projection = is_array($payload['mbti_public_projection_v1'] ?? null)
            ? $payload['mbti_public_projection_v1']
            : [];

        if ($projection === []) {
            return $payload;
        }

        $publicTags = is_array(data_get($projection, 'summary_card.public_tags'))
            ? array_values(data_get($projection, 'summary_card.public_tags'))
            : [];
        $profileKeywords = is_array(data_get($projection, 'profile.keywords'))
            ? array_values(data_get($projection, 'profile.keywords'))
            : [];

        $payload['type_code'] = (string) (data_get($projection, 'display_type') ?? $payload['type_code'] ?? '');
        $payload['type_name'] = data_get($projection, 'profile.type_name') ?? $payload['type_name'] ?? null;
        $payload['title'] = data_get($projection, 'summary_card.title') ?? $payload['title'] ?? null;
        $payload['subtitle'] = data_get($projection, 'summary_card.subtitle') ?? $payload['subtitle'] ?? null;
        $payload['summary'] = data_get($projection, 'summary_card.summary') ?? $payload['summary'] ?? null;
        $payload['tagline'] = data_get($projection, 'summary_card.tagline') ?? $payload['tagline'] ?? null;
        $payload['rarity'] = data_get($projection, 'profile.rarity') ?? $payload['rarity'] ?? null;
        $payload['tags'] = $publicTags !== [] ? $publicTags : $profileKeywords;
        $payload['dimensions'] = is_array($projection['dimensions'] ?? null)
            ? array_values($projection['dimensions'])
            : [];

        return $payload;
    }

    private function buildLocalizedShareUrl(string $shareId, string $locale): string
    {
        $segment = $locale === 'zh-CN' ? 'zh' : 'en';

        return rtrim((string) config('app.frontend_url', 'http://localhost'), '/').'/'.$segment.'/share/'.$shareId;
    }

    private function buildLocalizedShareOgUrl(string $shareId): string
    {
        return rtrim((string) config('app.frontend_url', 'http://localhost'), '/').'/og/share/'.$shareId;
    }

    private function frontendLocaleSegment(string $locale): string
    {
        return $locale === 'zh-CN' ? 'zh' : 'en';
    }

    private function resolveCompareCtaLabel(string $locale): string
    {
        return $locale === 'zh-CN' ? '邀请朋友来测并对比' : 'Invite a friend to compare';
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function extractMbtiContinuity(array $report): array
    {
        $personalization = is_array(data_get($report, '_meta.personalization'))
            ? data_get($report, '_meta.personalization')
            : [];
        $continuity = is_array($personalization['continuity'] ?? null) ? $personalization['continuity'] : [];

        if ($continuity === []) {
            return [];
        }

        $payload = [
            'carryover_focus_key' => trim((string) ($continuity['carryover_focus_key'] ?? '')),
            'carryover_reason' => trim((string) ($continuity['carryover_reason'] ?? '')),
            'recommended_resume_keys' => array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                (array) ($continuity['recommended_resume_keys'] ?? [])
            ))),
            'carryover_scene_keys' => array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                (array) ($continuity['carryover_scene_keys'] ?? [])
            ))),
            'carryover_action_keys' => array_values(array_filter(array_map(
                static fn (mixed $value): string => trim((string) $value),
                (array) ($continuity['carryover_action_keys'] ?? [])
            ))),
        ];

        return array_filter($payload, static function (mixed $value): bool {
            if (is_string($value)) {
                return $value !== '';
            }

            if (is_array($value)) {
                return $value !== [];
            }

            return $value !== null;
        });
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function extractMbtiPrivacyContract(array $report, Attempt $attempt, string $locale): array
    {
        $personalization = is_array(data_get($report, '_meta.personalization'))
            ? data_get($report, '_meta.personalization')
            : [];
        if ($personalization === []) {
            return [];
        }

        return $this->mbtiPrivacyConsentContractService->buildContract($personalization, [
            'region' => (string) ($attempt->region ?? config('regions.default_region', 'CN_MAINLAND')),
            'locale' => $locale,
            'public_safe' => true,
        ]);
    }
}
