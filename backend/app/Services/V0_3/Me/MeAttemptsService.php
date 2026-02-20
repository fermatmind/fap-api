<?php

namespace App\Services\V0_3\Me;

use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Support\ApiPagination;

class MeAttemptsService
{
    public function list(int $orgId, ?string $userId, ?string $anonId, int $pageSize, int $page): array
    {
        if ($userId === null && $anonId === null) {
            throw new ApiProblemException(401, 'UNAUTHORIZED', 'Missing or invalid fm_token.');
        }

        $query = Attempt::query()->where('org_id', $orgId);

        if ($userId !== null) {
            $query->where('user_id', $userId);
        } else {
            $query->where('anon_id', (string) $anonId);
        }

        $query->orderByDesc('submitted_at')->orderByDesc('id');

        $paginator = $query->paginate($pageSize, ['*'], 'page', $page);

        $items = [];
        foreach ($paginator->items() as $attempt) {
            if (!$attempt instanceof Attempt) {
                continue;
            }
            $items[] = $this->presentAttempt($attempt);
        }

        $paginator->setCollection(collect($items));
        $pagination = ApiPagination::fromPaginator($paginator);

        return [
            'user_id' => $userId ?? '',
            'anon_id' => $anonId ?? '',
            'items' => $pagination['items'],
            'meta' => $pagination['meta'],
            'links' => $pagination['links'],
        ];
    }

    private function presentAttempt(Attempt $attempt): array
    {
        $output = [
            'attempt_id' => (string) ($attempt->id ?? ''),
            'scale_code' => (string) ($attempt->scale_code ?? 'MBTI'),
            'scale_version' => (string) ($attempt->scale_version ?? 'v0.2'),
            'type_code' => (string) ($attempt->type_code ?? ''),
            'region' => (string) ($attempt->region ?? 'CN_MAINLAND'),
            'locale' => (string) ($attempt->locale ?? 'zh-CN'),
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
}
