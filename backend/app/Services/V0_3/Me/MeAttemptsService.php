<?php

namespace App\Services\V0_3\Me;

use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Models\Result;
use App\Models\UnifiedAccessProjection;
use App\Support\ApiPagination;

class MeAttemptsService
{
    public function list(
        int $orgId,
        ?string $userId,
        ?string $anonId,
        int $pageSize,
        int $page,
        ?string $scaleCode = null
    ): array
    {
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
            if (!$attempt instanceof Attempt) {
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
        }

        $items = [];
        foreach ($attemptModels as $attempt) {
            $attemptId = (string) ($attempt->id ?? '');
            $result = $resultByAttemptId[$attemptId] ?? null;
            $items[] = $this->presentAttempt($attempt, $result, $projectionByAttemptId[$attemptId] ?? null);
        }

        $paginator->setCollection(collect($items));
        $pagination = ApiPagination::fromPaginator($paginator);

        $historyCompare = null;
        if ($normalizedScaleCode === 'BIG5_OCEAN') {
            $historyCompare = $this->buildBigFiveHistoryCompare($attemptModels, $resultByAttemptId);
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
        ?UnifiedAccessProjection $projection = null
    ): array
    {
        $attemptId = (string) ($attempt->id ?? '');
        $domainsMean = $this->extractDomainsMean($result?->result_json);

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

        if (!empty($attempt->submitted_at)) {
            $output['submitted_at'] = (string) $attempt->submitted_at;
        } elseif (!empty($attempt->created_at)) {
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
            $output['access_summary'] = $this->buildAccessSummary(
                $attempt,
                $projection,
                $result instanceof Result
            );
        }

        return $output;
    }

    /**
     * @return array{
     *   access_state:string,
     *   report_state:string,
     *   pdf_state:string,
     *   reason_code:?string,
     *   access_level:?string,
     *   variant:?string,
     *   modules_allowed:list<string>,
     *   modules_preview:list<string>,
     *   actions:array{page_href:?string,pdf_href:?string}
     * }
     */
    private function buildAccessSummary(
        Attempt $attempt,
        ?UnifiedAccessProjection $projection,
        bool $resultExists
    ): array
    {
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

        return [
            'access_state' => $accessState,
            'report_state' => $reportState,
            'pdf_state' => $pdfState,
            'reason_code' => $reasonCode,
            'access_level' => $this->nullableText($payload['access_level'] ?? null),
            'variant' => $this->nullableText($payload['variant'] ?? null),
            'modules_allowed' => $this->normalizeStringArray($payload['modules_allowed'] ?? null),
            'modules_preview' => $this->normalizeStringArray($payload['modules_preview'] ?? null),
            'actions' => [
                'page_href' => $this->supportsPageEntry($accessState, $reportState)
                    ? $this->resultPagePathForAttempt($attempt)
                    : null,
                'pdf_href' => $this->supportsPdfDownload($accessState, $pdfState)
                    ? "/api/v0.3/attempts/{$attempt->id}/report.pdf"
                    : null,
            ],
        ];
    }

    /**
     * @param list<Attempt> $attemptModels
     * @param array<string,Result> $resultByAttemptId
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
            if (!is_array($node)) {
                continue;
            }
            $out = [];
            foreach (['O', 'C', 'E', 'A', 'N'] as $domain) {
                if (!array_key_exists($domain, $node)) {
                    continue 2;
                }
                $out[$domain] = round((float) $node[$domain], 2);
            }

            return $out;
        }

        return [];
    }

    private function shouldIncludeAccessSummary(Attempt $attempt): bool
    {
        return strtoupper(trim((string) ($attempt->scale_code ?? ''))) === 'BIG5_OCEAN';
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
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
