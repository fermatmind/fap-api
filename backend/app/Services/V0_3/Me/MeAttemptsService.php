<?php

namespace App\Services\V0_3\Me;

use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Models\Result;
use App\Models\UnifiedAccessProjection;
use App\Services\BigFive\BigFivePublicFormSummaryBuilder;
use App\Services\Enneagram\EnneagramPublicFormSummaryBuilder;
use App\Services\Mbti\MbtiPublicFormSummaryBuilder;
use App\Services\Report\InviteUnlockSummaryBuilder;
use App\Services\Report\ReportAccess;
use App\Services\Report\Resolvers\OfferResolver;
use App\Services\Riasec\RiasecPublicFormSummaryBuilder;
use App\Services\Scale\ScaleRegistry;
use App\Services\Scale\ScaleRolloutGate;
use App\Support\ApiPagination;
use Illuminate\Support\Facades\DB;

class MeAttemptsService
{
    /**
     * @var array<string,array<string,mixed>|null>
     */
    private array $bigFivePrimaryOfferCache = [];

    /**
     * @var array<string,array{title:string,domain:string}>
     */
    private const BIG_FIVE_FACET_META = [
        'N1' => ['title' => 'Anxiety', 'domain' => 'N'],
        'N2' => ['title' => 'Anger', 'domain' => 'N'],
        'N3' => ['title' => 'Depression', 'domain' => 'N'],
        'N4' => ['title' => 'Self Consciousness', 'domain' => 'N'],
        'N5' => ['title' => 'Immoderation', 'domain' => 'N'],
        'N6' => ['title' => 'Vulnerability', 'domain' => 'N'],
        'E1' => ['title' => 'Friendliness', 'domain' => 'E'],
        'E2' => ['title' => 'Gregariousness', 'domain' => 'E'],
        'E3' => ['title' => 'Assertiveness', 'domain' => 'E'],
        'E4' => ['title' => 'Activity Level', 'domain' => 'E'],
        'E5' => ['title' => 'Excitement Seeking', 'domain' => 'E'],
        'E6' => ['title' => 'Cheerfulness', 'domain' => 'E'],
        'O1' => ['title' => 'Imagination', 'domain' => 'O'],
        'O2' => ['title' => 'Artistic Interests', 'domain' => 'O'],
        'O3' => ['title' => 'Emotionality', 'domain' => 'O'],
        'O4' => ['title' => 'Adventurousness', 'domain' => 'O'],
        'O5' => ['title' => 'Intellect', 'domain' => 'O'],
        'O6' => ['title' => 'Liberalism', 'domain' => 'O'],
        'A1' => ['title' => 'Trust', 'domain' => 'A'],
        'A2' => ['title' => 'Morality', 'domain' => 'A'],
        'A3' => ['title' => 'Altruism', 'domain' => 'A'],
        'A4' => ['title' => 'Cooperation', 'domain' => 'A'],
        'A5' => ['title' => 'Modesty', 'domain' => 'A'],
        'A6' => ['title' => 'Sympathy', 'domain' => 'A'],
        'C1' => ['title' => 'Self Efficacy', 'domain' => 'C'],
        'C2' => ['title' => 'Orderliness', 'domain' => 'C'],
        'C3' => ['title' => 'Dutifulness', 'domain' => 'C'],
        'C4' => ['title' => 'Achievement Striving', 'domain' => 'C'],
        'C5' => ['title' => 'Self Discipline', 'domain' => 'C'],
        'C6' => ['title' => 'Cautiousness', 'domain' => 'C'],
    ];

    public function __construct(
        private readonly ScaleRegistry $scaleRegistry,
        private readonly OfferResolver $offerResolver,
        private readonly MbtiPublicFormSummaryBuilder $mbtiPublicFormSummaryBuilder,
        private readonly BigFivePublicFormSummaryBuilder $bigFivePublicFormSummaryBuilder,
        private readonly EnneagramPublicFormSummaryBuilder $enneagramPublicFormSummaryBuilder,
        private readonly RiasecPublicFormSummaryBuilder $riasecPublicFormSummaryBuilder,
        private readonly InviteUnlockSummaryBuilder $inviteUnlockSummaryBuilder,
    ) {}

    public function list(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        int $pageSize,
        int $page,
        ?string $scaleCode = null,
        ?string $locale = null
    ): array {
        if ($userId === null && $anonId === null) {
            throw new ApiProblemException(401, 'UNAUTHORIZED', 'Missing or invalid fm_token.');
        }

        $query = Attempt::query()->where('org_id', $orgId);
        $normalizedScaleCode = strtoupper(trim((string) $scaleCode));
        if ($normalizedScaleCode !== '') {
            $query->where('scale_code', $normalizedScaleCode);
        }

        if ($userId !== null) {
            $query->where('user_id', $userId);
        } else {
            $query->where('anon_id', (string) $anonId);
        }

        $query->orderByDesc('submitted_at')->orderByDesc('id');

        $paginator = $query->paginate($pageSize, ['*'], 'page', $page);

        $attemptModels = [];
        $attemptIds = [];
        foreach ($paginator->items() as $attempt) {
            if (! $attempt instanceof Attempt) {
                continue;
            }
            $attemptModels[] = $attempt;
            $attemptIds[] = (string) ($attempt->id ?? '');
        }

        $resultByAttemptId = [];
        if ($attemptIds !== []) {
            $resultRows = Result::query()
                ->where('org_id', $orgId)
                ->whereIn('attempt_id', $attemptIds)
                ->select(['attempt_id', 'type_code', 'result_json'])
                ->get();
            foreach ($resultRows as $result) {
                $attemptId = (string) ($result->attempt_id ?? '');
                if ($attemptId === '') {
                    continue;
                }
                $resultByAttemptId[$attemptId] = $result;
            }
        }

        $projectionByAttemptId = [];
        $inviteByAttemptId = [];
        $needsAccessSummary = false;
        foreach ($attemptModels as $attempt) {
            if ($attempt instanceof Attempt && $this->shouldIncludeAccessSummary($attempt)) {
                $needsAccessSummary = true;
                break;
            }
        }

        if ($needsAccessSummary && $attemptIds !== []) {
            $projectionRows = UnifiedAccessProjection::query()
                ->whereIn('attempt_id', $attemptIds)
                ->get();
            foreach ($projectionRows as $projection) {
                if (! $projection instanceof UnifiedAccessProjection) {
                    continue;
                }
                $attemptId = (string) ($projection->attempt_id ?? '');
                if ($attemptId === '') {
                    continue;
                }
                $projectionByAttemptId[$attemptId] = $projection;
            }

            if (DB::getSchemaBuilder()->hasTable('attempt_invite_unlocks')) {
                $inviteRows = DB::table('attempt_invite_unlocks')
                    ->where('target_org_id', $orgId)
                    ->whereIn('target_attempt_id', $attemptIds)
                    ->select(['target_attempt_id', 'required_invitees', 'completed_invitees'])
                    ->get();
                foreach ($inviteRows as $invite) {
                    $attemptId = (string) ($invite->target_attempt_id ?? '');
                    if ($attemptId === '') {
                        continue;
                    }

                    $inviteByAttemptId[$attemptId] = [
                        'required_invitees' => max(1, (int) ($invite->required_invitees ?? 2)),
                        'completed_invitees' => max(0, (int) ($invite->completed_invitees ?? 0)),
                    ];
                }
            }
        }

        $items = [];
        foreach ($attemptModels as $attempt) {
            $attemptId = (string) ($attempt->id ?? '');
            $result = $resultByAttemptId[$attemptId] ?? null;
            $presented = $this->presentAttempt(
                $attempt,
                $result,
                $projectionByAttemptId[$attemptId] ?? null,
                $inviteByAttemptId[$attemptId] ?? null
            );
            if (strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'MBTI') {
                $presented['mbti_form_v1'] = $this->mbtiPublicFormSummaryBuilder->summarizeForAttempt($attempt, $result, $locale);
            } elseif (strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'BIG5_OCEAN') {
                $presented['big5_form_v1'] = $this->bigFivePublicFormSummaryBuilder->summarizeForAttempt($attempt, $result, $locale);
            } elseif (strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'ENNEAGRAM') {
                $presented['enneagram_form_v1'] = $this->enneagramPublicFormSummaryBuilder->summarizeForAttempt($attempt, $result, $locale);
            } elseif (strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'RIASEC') {
                $presented['riasec_form_v1'] = $this->riasecPublicFormSummaryBuilder->build($attempt, $result);
            }
            $items[] = $presented;
        }

        $paginator->setCollection(collect($items));
        $pagination = ApiPagination::fromPaginator($paginator);

        $historyCompare = null;
        if ($normalizedScaleCode === 'BIG5_OCEAN') {
            $historyCompare = $this->buildBigFiveHistoryCompare($attemptModels, $resultByAttemptId);
        } elseif ($normalizedScaleCode === 'ENNEAGRAM') {
            $historyCompare = $this->buildEnneagramHistorySummary($attemptModels, $resultByAttemptId);
        }

        return [
            'user_id' => $userId ?? '',
            'anon_id' => $anonId ?? '',
            'scale_code' => $normalizedScaleCode !== '' ? $normalizedScaleCode : null,
            'items' => $pagination['items'],
            'meta' => $pagination['meta'],
            'links' => $pagination['links'],
            'history_compare' => $historyCompare,
        ];
    }

    private function presentAttempt(
        Attempt $attempt,
        ?Result $result = null,
        ?UnifiedAccessProjection $projection = null,
        ?array $inviteSnapshot = null
    ): array {
        $attemptId = (string) ($attempt->id ?? '');
        $domainsMean = $this->extractDomainsMean($result?->result_json);
        $accessSummary = null;

        $output = [
            'attempt_id' => $attemptId,
            'scale_code' => (string) ($attempt->scale_code ?? 'MBTI'),
            'scale_version' => (string) ($attempt->scale_version ?? 'v0.2'),
            'type_code' => (string) ($result?->type_code ?? $attempt->type_code ?? ''),
            'region' => (string) ($attempt->region ?? 'CN_MAINLAND'),
            'locale' => (string) ($attempt->locale ?? 'zh-CN'),
            'result_summary' => [
                'domains_mean' => $domainsMean,
            ],
        ];

        if (isset($attempt->ticket_code)) {
            $output['ticket_code'] = (string) $attempt->ticket_code;
        }

        if (! empty($attempt->submitted_at)) {
            $output['submitted_at'] = (string) $attempt->submitted_at;
        } elseif (! empty($attempt->created_at)) {
            $output['submitted_at'] = (string) $attempt->created_at;
        } else {
            $output['submitted_at'] = null;
        }

        if ((string) ($attempt->ticket_code ?? '') !== '') {
            $output['lookup_key'] = (string) $attempt->ticket_code;
        } else {
            $output['lookup_key'] = (string) ($attempt->id ?? '');
        }

        if ($this->shouldIncludeAccessSummary($attempt)) {
            $accessSummary = $this->buildAccessSummary(
                $attempt,
                $projection,
                $result instanceof Result,
                $inviteSnapshot
            );
            $output['access_summary'] = $accessSummary;
            $output = array_merge($output, $this->buildBigFiveRowSummary($attempt, $result, $accessSummary));
            $output = array_merge($output, $this->buildEnneagramRowSummary($attempt, $result, $accessSummary));
        }

        return $output;
    }

    /**
     * @return array{
     *   access_state:string,
     *   report_state:string,
     *   pdf_state:string,
     *   reason_code:?string,
     *   unlock_stage:string,
     *   unlock_source:string,
     *   access_level:?string,
     *   variant:?string,
     *   modules_allowed:list<string>,
     *   modules_preview:list<string>,
     *   invite_unlock_v1:array<string,mixed>,
     *   actions:array{
     *     page_href:?string,
     *     pdf_href:?string,
     *     wait_href:?string,
     *     history_href:?string,
     *     lookup_href:?string
     *   }
     * }
     */
    private function buildAccessSummary(
        Attempt $attempt,
        ?UnifiedAccessProjection $projection,
        bool $resultExists,
        ?array $inviteSnapshot = null
    ): array {
        $payload = is_array($projection?->payload_json) ? $projection->payload_json : [];
        $accessState = $this->normalizeProjectionState(
            (string) ($projection?->access_state ?? ($resultExists ? 'locked' : 'pending')),
            'access'
        );
        $reportState = $this->normalizeProjectionState(
            (string) ($projection?->report_state ?? ($resultExists ? 'ready' : 'pending')),
            'report'
        );
        $pdfState = $this->normalizeProjectionState(
            (string) ($projection?->pdf_state ?? 'missing'),
            'pdf'
        );
        $reasonCode = $this->nullableText(
            $projection?->reason_code ?? ($resultExists ? 'projection_missing_result_ready' : 'projection_missing_result_pending')
        );
        $unlockStage = ReportAccess::normalizeUnlockStage((string) ($payload['unlock_stage'] ?? (
            $accessState === 'ready'
                ? ReportAccess::UNLOCK_STAGE_FULL
                : ReportAccess::UNLOCK_STAGE_LOCKED
        )));
        $unlockSource = ReportAccess::normalizeUnlockSource((string) ($payload['unlock_source'] ?? ReportAccess::UNLOCK_SOURCE_NONE));
        $requiredInvitees = max(1, (int) ($inviteSnapshot['required_invitees'] ?? 2));
        $completedInvitees = max(0, min($requiredInvitees, (int) ($inviteSnapshot['completed_invitees'] ?? 0)));
        $isBigFive = strtoupper(trim((string) ($attempt->scale_code ?? ''))) === ReportAccess::SCALE_BIG5_OCEAN;
        $isEnneagram = strtoupper(trim((string) ($attempt->scale_code ?? ''))) === ReportAccess::SCALE_ENNEAGRAM;
        if (($isBigFive || $isEnneagram) && $resultExists) {
            $accessState = 'ready';
            $reportState = 'ready';
            $pdfState = 'ready';
            $unlockStage = ReportAccess::UNLOCK_STAGE_FULL;
            $unlockSource = ReportAccess::UNLOCK_SOURCE_NONE;
            $payload['access_level'] = ReportAccess::REPORT_ACCESS_FULL;
            $payload['variant'] = ReportAccess::VARIANT_FULL;
        }

        return [
            'access_state' => $accessState,
            'report_state' => $reportState,
            'pdf_state' => $pdfState,
            'reason_code' => $reasonCode,
            'unlock_stage' => $unlockStage,
            'unlock_source' => $unlockSource,
            'access_level' => $this->nullableText($payload['access_level'] ?? null),
            'variant' => $this->nullableText($payload['variant'] ?? null),
            'modules_allowed' => $this->normalizeStringArray($payload['modules_allowed'] ?? null),
            'modules_preview' => $this->normalizeStringArray($payload['modules_preview'] ?? null),
            'invite_unlock_v1' => $this->inviteUnlockSummaryBuilder->build(
                (string) ($attempt->scale_code ?? ''),
                $unlockStage,
                $unlockSource,
                $completedInvitees,
                $requiredInvitees
            ),
            'actions' => $this->buildAccessSummaryActions($attempt, $accessState, $reportState, $pdfState),
        ];
    }

    /**
     * @return array{
     *   page_href:?string,
     *   pdf_href:?string,
     *   wait_href:?string,
     *   history_href:?string,
     *   lookup_href:?string
     * }
     */
    private function buildAccessSummaryActions(
        Attempt $attempt,
        string $accessState,
        string $reportState,
        string $pdfState
    ): array {
        $pageHref = $this->supportsPageEntry($accessState, $reportState)
            ? $this->resultPagePathForAttempt($attempt)
            : null;
        $historyHref = strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'MBTI'
            ? '/history/mbti'
            : null;

        return [
            'page_href' => $pageHref,
            'pdf_href' => $this->supportsPdfDownload($accessState, $pdfState)
                ? "/api/v0.3/attempts/{$attempt->id}/report.pdf"
                : null,
            'wait_href' => $this->isWaitingState($reportState) ? $pageHref : null,
            'history_href' => $historyHref,
            'lookup_href' => '/orders/lookup',
        ];
    }

    /**
     * @param  list<Attempt>  $attemptModels
     * @param  array<string,Result>  $resultByAttemptId
     * @return array<string,mixed>|null
     */
    private function buildBigFiveHistoryCompare(array $attemptModels, array $resultByAttemptId): ?array
    {
        if (count($attemptModels) < 2) {
            return null;
        }

        $current = $attemptModels[0];
        $previous = $attemptModels[1];

        $currentId = (string) ($current->id ?? '');
        $previousId = (string) ($previous->id ?? '');
        if ($currentId === '' || $previousId === '') {
            return null;
        }

        $currentDomains = $this->extractDomainsMean($resultByAttemptId[$currentId]->result_json ?? null);
        $previousDomains = $this->extractDomainsMean($resultByAttemptId[$previousId]->result_json ?? null);
        if ($currentDomains === [] || $previousDomains === []) {
            return null;
        }

        $delta = [];
        foreach (['O', 'C', 'E', 'A', 'N'] as $domain) {
            $curr = (float) ($currentDomains[$domain] ?? 0.0);
            $prev = (float) ($previousDomains[$domain] ?? 0.0);
            $diff = round($curr - $prev, 2);
            $delta[$domain] = [
                'delta' => $diff,
                'direction' => $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat'),
            ];
        }

        return [
            'scale_code' => 'BIG5_OCEAN',
            'current_attempt_id' => $currentId,
            'previous_attempt_id' => $previousId,
            'current_domains_mean' => $currentDomains,
            'previous_domains_mean' => $previousDomains,
            'domains_delta' => $delta,
        ];
    }

    /**
     * @param  list<Attempt>  $attemptModels
     * @param  array<string,Result>  $resultByAttemptId
     * @return array<string,mixed>|null
     */
    private function buildEnneagramHistorySummary(array $attemptModels, array $resultByAttemptId): ?array
    {
        if ($attemptModels === []) {
            return null;
        }

        $latest = $attemptModels[0];
        $latestId = (string) ($latest->id ?? '');
        if ($latestId === '') {
            return null;
        }

        $latestScore = $this->extractEnneagramScoreResult($resultByAttemptId[$latestId] ?? null);
        if ($latestScore === []) {
            return null;
        }

        $payload = [
            'scale_code' => 'ENNEAGRAM',
            'current_attempt_id' => $latestId,
            'current_primary_type' => (string) ($latestScore['primary_type'] ?? ''),
            'current_top_types' => is_array($latestScore['top_types'] ?? null) ? array_values($latestScore['top_types']) : [],
        ];

        $previous = $attemptModels[1] ?? null;
        if ($previous instanceof Attempt) {
            $previousId = (string) ($previous->id ?? '');
            $previousScore = $this->extractEnneagramScoreResult($resultByAttemptId[$previousId] ?? null);
            if ($previousId !== '' && $previousScore !== []) {
                $payload['previous_attempt_id'] = $previousId;
                $payload['previous_primary_type'] = (string) ($previousScore['primary_type'] ?? '');
                $payload['primary_type_changed'] = $payload['previous_primary_type'] !== $payload['current_primary_type'];
            }
        }

        return $payload;
    }

    /**
     * @return array<string,float>
     */
    private function extractDomainsMean(mixed $resultJson): array
    {
        $payload = $this->decodeResultJson($resultJson);
        $candidates = [
            data_get($payload, 'raw_scores.domains_mean'),
            data_get($payload, 'normed_json.raw_scores.domains_mean'),
            data_get($payload, 'breakdown_json.score_result.raw_scores.domains_mean'),
            data_get($payload, 'axis_scores_json.score_result.raw_scores.domains_mean'),
        ];

        foreach ($candidates as $node) {
            if (! is_array($node)) {
                continue;
            }
            $out = [];
            foreach (['O', 'C', 'E', 'A', 'N'] as $domain) {
                if (! array_key_exists($domain, $node)) {
                    continue 2;
                }
                $out[$domain] = round((float) $node[$domain], 2);
            }

            return $out;
        }

        return [];
    }

    /**
     * @param  array<string,mixed>|null  $accessSummary
     * @return array<string,mixed>
     */
    private function buildBigFiveRowSummary(Attempt $attempt, ?Result $result, ?array $accessSummary): array
    {
        if (strtoupper(trim((string) ($attempt->scale_code ?? ''))) !== ReportAccess::SCALE_BIG5_OCEAN) {
            return [];
        }

        $scoreResult = $this->extractBigFiveScoreResult($result);

        return [
            'top_facets_summary_v1' => $this->buildTopFacetsSummary($scoreResult),
            'quality_summary' => $this->buildQualitySummary($scoreResult),
            'norms_summary' => $this->buildNormsSummary($scoreResult),
            'offer_summary' => $this->buildOfferSummary($attempt, $accessSummary),
            'share_summary' => $this->buildShareSummary($result, $accessSummary),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $accessSummary
     * @return array<string,mixed>
     */
    private function buildEnneagramRowSummary(Attempt $attempt, ?Result $result, ?array $accessSummary): array
    {
        if (strtoupper(trim((string) ($attempt->scale_code ?? ''))) !== ReportAccess::SCALE_ENNEAGRAM) {
            return [];
        }

        $scoreResult = $this->extractEnneagramScoreResult($result);

        return [
            'enneagram_summary_v1' => [
                'primary_type' => (string) ($scoreResult['primary_type'] ?? ''),
                'top_types' => is_array($scoreResult['top_types'] ?? null) ? array_values($scoreResult['top_types']) : [],
                'confidence' => is_array($scoreResult['confidence'] ?? null) ? $scoreResult['confidence'] : [],
            ],
            'quality_summary' => $this->buildQualitySummary($scoreResult),
            'share_summary' => [
                'enabled' => $result instanceof Result && strtolower(trim((string) ($accessSummary['report_state'] ?? ''))) === 'ready',
                'share_kind' => 'enneagram_result',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function extractEnneagramScoreResult(?Result $result): array
    {
        if (! $result instanceof Result) {
            return [];
        }

        $resultJson = $this->decodeResultJson($result->result_json);
        $candidates = [
            $resultJson['normed_json'] ?? null,
            $resultJson,
            data_get($resultJson, 'breakdown_json.score_result'),
            data_get($resultJson, 'axis_scores_json.score_result'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && strtoupper(trim((string) ($candidate['scale_code'] ?? ''))) === 'ENNEAGRAM') {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function extractBigFiveScoreResult(?Result $result): array
    {
        if (! $result instanceof Result) {
            return [];
        }

        $resultJson = $this->decodeResultJson($result->result_json);
        $candidates = [
            $result->normed_json ?? null,
            $resultJson['normed_json'] ?? null,
            data_get($resultJson, 'breakdown_json.score_result'),
            data_get($resultJson, 'axis_scores_json.score_result'),
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array{items:list<array{key:string,label:string,domain:string,percentile:?int,bucket:?string,kind:?string}>}
     */
    private function buildTopFacetsSummary(array $scoreResult): array
    {
        $facetPercentiles = is_array(data_get($scoreResult, 'scores_0_100.facets_percentile'))
            ? data_get($scoreResult, 'scores_0_100.facets_percentile')
            : [];
        $facetBuckets = is_array(data_get($scoreResult, 'facts.facet_buckets'))
            ? data_get($scoreResult, 'facts.facet_buckets')
            : [];
        $topStrength = $this->normalizeFacetCodeList(data_get($scoreResult, 'facts.top_strength_facets'));
        $topGrowth = $this->normalizeFacetCodeList(data_get($scoreResult, 'facts.top_growth_facets'));

        $items = [];

        foreach (['strength' => $topStrength, 'growth' => $topGrowth] as $kind => $codes) {
            foreach ($codes as $code) {
                $items[$code] = $this->presentFacetSummaryItem($code, $facetPercentiles, $facetBuckets, $kind);
            }
        }

        if ($items === [] && is_array($facetPercentiles) && $facetPercentiles !== []) {
            $ranked = [];
            foreach ($facetPercentiles as $key => $value) {
                $code = $this->normalizeFacetCode($key);
                if ($code === '') {
                    continue;
                }

                $percentile = (int) round((float) $value);
                $ranked[$code] = abs($percentile - 50);
            }

            arsort($ranked);
            foreach (array_slice(array_keys($ranked), 0, 4) as $code) {
                $items[$code] = $this->presentFacetSummaryItem($code, $facetPercentiles, $facetBuckets, null);
            }
        }

        return [
            'items' => array_values($items),
        ];
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array{level:string,grade:?string}
     */
    private function buildQualitySummary(array $scoreResult): array
    {
        $quality = is_array($scoreResult['quality'] ?? null) ? $scoreResult['quality'] : [];
        $level = strtoupper(trim((string) ($quality['level'] ?? 'UNKNOWN')));
        if ($level === '') {
            $level = 'UNKNOWN';
        }

        $grade = strtoupper(trim((string) ($quality['grade'] ?? '')));
        if ($grade === '' && preg_match('/^[A-D]$/', $level) === 1) {
            $grade = $level;
        }

        return [
            'level' => $level,
            'grade' => $grade !== '' ? $grade : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $scoreResult
     * @return array{status:string,norms_version:?string}
     */
    private function buildNormsSummary(array $scoreResult): array
    {
        $norms = is_array($scoreResult['norms'] ?? null) ? $scoreResult['norms'] : [];
        $status = strtoupper(trim((string) ($norms['status'] ?? 'MISSING')));
        if ($status === '') {
            $status = 'MISSING';
        }

        return [
            'status' => $status,
            'norms_version' => $this->nullableText($norms['norms_version'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $accessSummary
     * @return array{primary_offer:array<string,mixed>|null}
     */
    private function buildOfferSummary(Attempt $attempt, ?array $accessSummary): array
    {
        if (strtoupper(trim((string) ($attempt->scale_code ?? ''))) === ReportAccess::SCALE_BIG5_OCEAN) {
            return ['primary_offer' => null];
        }

        $accessState = strtolower(trim((string) ($accessSummary['access_state'] ?? '')));
        $reportState = strtolower(trim((string) ($accessSummary['report_state'] ?? '')));

        if ($accessState !== 'locked' || $reportState !== 'ready') {
            return ['primary_offer' => null];
        }

        return [
            'primary_offer' => $this->resolveBigFivePrimaryOffer($attempt),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $accessSummary
     * @return array{enabled:bool,share_kind:string}
     */
    private function buildShareSummary(?Result $result, ?array $accessSummary): array
    {
        $reportState = strtolower(trim((string) ($accessSummary['report_state'] ?? '')));

        return [
            'enabled' => $result instanceof Result && $reportState === 'ready',
            'share_kind' => 'big5_result',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveBigFivePrimaryOffer(Attempt $attempt): ?array
    {
        $scaleCode = strtoupper(trim((string) ($attempt->scale_code ?? '')));
        $orgId = (int) ($attempt->org_id ?? 0);
        $cacheKey = $orgId.'|'.$scaleCode;

        if (array_key_exists($cacheKey, $this->bigFivePrimaryOfferCache)) {
            return $this->bigFivePrimaryOfferCache[$cacheKey];
        }

        $registry = $this->scaleRegistry->getByCode($scaleCode, $orgId);
        if (! is_array($registry)) {
            return $this->bigFivePrimaryOfferCache[$cacheKey] = null;
        }

        $paywallMode = ScaleRolloutGate::paywallMode($registry);
        if ($paywallMode !== ScaleRolloutGate::PAYWALL_FULL) {
            return $this->bigFivePrimaryOfferCache[$cacheKey] = null;
        }

        $viewPolicy = $this->offerResolver->normalizeViewPolicy($registry['view_policy_json'] ?? null);
        $commercial = $this->offerResolver->normalizeCommercial($registry['commercial_json'] ?? null);
        $paywall = $this->offerResolver->buildPaywall($viewPolicy, $commercial, [], $scaleCode, $orgId);
        $offers = array_values(array_filter(
            is_array($paywall['offers'] ?? null) ? $paywall['offers'] : [],
            static fn ($offer): bool => is_array($offer)
        ));

        $effectiveSku = strtoupper(trim((string) ($paywall['upgrade_sku_effective'] ?? '')));
        $anchorSku = strtoupper(trim((string) ($paywall['upgrade_sku'] ?? '')));

        $primaryOffer = null;
        if ($effectiveSku !== '') {
            foreach ($offers as $offer) {
                if (strtoupper(trim((string) ($offer['sku'] ?? ''))) === $effectiveSku) {
                    $primaryOffer = $offer;
                    break;
                }
            }
        }

        if ($primaryOffer === null && $anchorSku !== '') {
            foreach ($offers as $offer) {
                if (strtoupper(trim((string) ($offer['sku'] ?? ''))) === $anchorSku) {
                    $primaryOffer = $offer;
                    break;
                }
            }
        }

        if ($primaryOffer === null) {
            $primaryOffer = $offers[0] ?? null;
        }

        if (! is_array($primaryOffer)) {
            return $this->bigFivePrimaryOfferCache[$cacheKey] = null;
        }

        return $this->bigFivePrimaryOfferCache[$cacheKey] = [
            'sku' => $this->nullableText($primaryOffer['sku'] ?? null),
            'label' => $this->nullableText($primaryOffer['label'] ?? $primaryOffer['title'] ?? null),
            'title' => $this->nullableText($primaryOffer['title'] ?? $primaryOffer['label'] ?? null),
            'formatted_price' => $this->formatMoney(
                isset($primaryOffer['price_cents']) ? (int) $primaryOffer['price_cents'] : null,
                $this->nullableText($primaryOffer['currency'] ?? null)
            ),
            'price_cents' => isset($primaryOffer['price_cents']) ? (int) $primaryOffer['price_cents'] : null,
            'currency' => $this->nullableText($primaryOffer['currency'] ?? null),
            'benefit_code' => $this->nullableText($primaryOffer['benefit_code'] ?? null),
            'modules_included' => $this->normalizeStringArray($primaryOffer['modules_included'] ?? null),
        ];
    }

    private function shouldIncludeAccessSummary(Attempt $attempt): bool
    {
        return in_array(strtoupper(trim((string) ($attempt->scale_code ?? ''))), ['BIG5_OCEAN', 'MBTI', 'ENNEAGRAM'], true);
    }

    private function supportsPageEntry(string $accessState, string $reportState): bool
    {
        return ! in_array($this->normalizeProjectionState($accessState, 'access'), ['deleted', 'expired'], true)
            && ! in_array($this->normalizeProjectionState($reportState, 'report'), ['deleted', 'expired', 'unavailable'], true);
    }

    private function supportsPdfDownload(string $accessState, string $pdfState): bool
    {
        return $this->normalizeProjectionState($accessState, 'access') === 'ready'
            && $this->normalizeProjectionState($pdfState, 'pdf') === 'ready';
    }

    private function isWaitingState(string $state): bool
    {
        return in_array($this->normalizeProjectionState($state, 'report'), ['pending', 'restoring'], true);
    }

    private function resultPagePathForAttempt(Attempt $attempt): string
    {
        $scaleCode = strtoupper(trim((string) ($attempt->scale_code ?? '')));

        return in_array($scaleCode, ['SDS_20', 'CLINICAL_COMBO_68'], true)
            ? "/attempts/{$attempt->id}/report"
            : "/result/{$attempt->id}";
    }

    private function normalizeProjectionState(string $state, string $kind): string
    {
        $normalized = strtolower(trim($state));

        return match (true) {
            $normalized === 'ready' => 'ready',
            in_array($normalized, ['pending', 'generating', 'queued', 'running', 'submitted'], true) => 'pending',
            in_array($normalized, ['restoring', 'rehydrating'], true) => 'restoring',
            in_array($normalized, ['deleted', 'purged', 'anonymized'], true) => 'deleted',
            $normalized === 'expired' => 'expired',
            $kind === 'access' && in_array($normalized, ['locked', 'recovery_available'], true) => 'locked',
            in_array($normalized, ['missing', 'unavailable', 'archived', 'shrunk', 'failed', 'blocked'], true) => 'unavailable',
            default => $kind === 'access' ? 'locked' : 'unavailable',
        };
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            $normalized = trim($item);
            if ($normalized === '') {
                continue;
            }
            $out[$normalized] = $normalized;
        }

        return array_values($out);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeResultJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $facetPercentiles
     * @param  array<string,mixed>  $facetBuckets
     * @return array{key:string,label:string,domain:string,percentile:?int,bucket:?string,kind:?string}
     */
    private function presentFacetSummaryItem(
        string $code,
        array $facetPercentiles,
        array $facetBuckets,
        ?string $kind
    ): array {
        $meta = self::BIG_FIVE_FACET_META[$code] ?? [
            'title' => $code,
            'domain' => substr($code, 0, 1),
        ];

        $percentile = array_key_exists($code, $facetPercentiles)
            ? (int) round((float) $facetPercentiles[$code])
            : null;
        $bucket = $this->normalizeFacetBucket($facetBuckets[$code] ?? null);

        return [
            'key' => $code,
            'label' => sprintf('%s %s', $code, (string) ($meta['title'] ?? $code)),
            'domain' => (string) ($meta['domain'] ?? substr($code, 0, 1)),
            'percentile' => $percentile,
            'bucket' => $bucket,
            'kind' => $kind,
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeFacetCodeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $codes = [];
        foreach ($value as $item) {
            $code = $this->normalizeFacetCode($item);
            if ($code === '') {
                continue;
            }
            $codes[$code] = $code;
        }

        return array_values($codes);
    }

    private function normalizeFacetCode(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $code = strtoupper(trim($value));

        return array_key_exists($code, self::BIG_FIVE_FACET_META) ? $code : '';
    }

    private function normalizeFacetBucket(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $bucket = strtolower(trim($value));

        return in_array($bucket, ['low', 'mid', 'high', 'extreme_low', 'extreme_high'], true)
            ? $bucket
            : null;
    }

    private function formatMoney(?int $priceCents, ?string $currency): ?string
    {
        if ($priceCents === null || $priceCents < 0) {
            return null;
        }

        $currency = strtoupper(trim((string) $currency));
        $amount = number_format($priceCents / 100, 2, '.', '');

        return match ($currency) {
            'CNY' => '¥'.$amount,
            'USD' => '$'.$amount,
            'EUR' => '€'.$amount,
            default => trim($amount.' '.$currency),
        };
    }
}
