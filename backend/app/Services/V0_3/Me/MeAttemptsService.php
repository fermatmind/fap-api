<?php

namespace App\Services\V0_3\Me;

use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Models\Result;
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

        $items = [];
        foreach ($attemptModels as $attempt) {
            $attemptId = (string) ($attempt->id ?? '');
            $result = $resultByAttemptId[$attemptId] ?? null;
            $items[] = $this->presentAttempt($attempt, $result);
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

    private function presentAttempt(Attempt $attempt, ?Result $result = null): array
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

        return $output;
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
