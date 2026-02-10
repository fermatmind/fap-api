<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\InvalidSkuException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ApiExceptionRenderer
{
    public static function render(Request $request, Throwable $e): ?JsonResponse
    {
        if (!$request->is('api/*')) {
            return null;
        }

        if ($e instanceof ValidationException || $e instanceof HttpResponseException) {
            return null;
        }

        $status = 500;
        $payload = [
            'ok' => false,
            'error' => 'INTERNAL_ERROR',
            'error_code' => 'INTERNAL_ERROR',
            'message' => 'Internal Server Error',
        ];

        if ($e instanceof InvalidSkuException) {
            $status = 422;
            $payload = [
                'ok' => false,
                'error' => 'INVALID_SKU',
                'error_code' => 'INVALID_SKU',
                'message' => 'invalid sku.',
            ];
        }

        if (
            $e instanceof ModelNotFoundException
            || $e instanceof NotFoundHttpException
            || $e instanceof MethodNotAllowedHttpException
        ) {
            $status = 404;
            $payload = [
                'ok' => false,
                'error' => 'NOT_FOUND',
                'error_code' => 'NOT_FOUND',
                'message' => 'Not Found',
            ];
        }

        $requestId = (string) $request->attributes->get('request_id', '');
        if ($requestId !== '') {
            $payload['request_id'] = $requestId;
        }

        return response()->json($payload, $status);
    }
}
