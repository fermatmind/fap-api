<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

trait RespondsWithNotFound
{
    protected function notFoundResponse(string $message = 'not found.'): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'NOT_FOUND',
            'message' => $message,
        ], 404);
    }
}
