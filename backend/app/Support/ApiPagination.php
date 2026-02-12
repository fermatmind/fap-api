<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;

final class ApiPagination
{
    /**
     * @return array{
     *     items: array<int, mixed>,
     *     meta: array{current_page:int, per_page:int, total:int, last_page:int},
     *     links: array{first:?string, last:?string, prev:?string, next:?string}
     * }
     */
    public static function fromPaginator(LengthAwarePaginator $paginator): array
    {
        $lastPage = max(1, (int) $paginator->lastPage());

        return [
            'items' => $paginator->items(),
            'meta' => [
                'current_page' => (int) $paginator->currentPage(),
                'per_page' => (int) $paginator->perPage(),
                'total' => (int) $paginator->total(),
                'last_page' => $lastPage,
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($lastPage),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }

    public static function fromLengthAwarePaginator(LengthAwarePaginator $paginator): array
    {
        return self::fromPaginator($paginator);
    }
}
