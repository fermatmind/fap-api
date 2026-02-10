<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\InvalidSkuException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiExceptionRenderer
{
    public static function render(Request $request, Throwable $e): ?JsonResponse
    {
        if (!$request->is('api/*')) {
            return null;
        }

        $requestId = (string) $request->attributes->get('request_id', '');

        if ($e instanceof ValidationException) {
            return response()->json(
                self::withRequestId([
                    'ok' => false,
                    'error' => 'VALIDATION_FAILED',
                    'error_code' => 'VALIDATION_FAILED',
                    'message' => 'The given data was invalid.',
                    'details' => $e->errors(),
                ], $requestId),
                422
            );
        }

        if ($e instanceof HttpResponseException) {
            return null;
        }

        if ($e instanceof InvalidSkuException) {
            return response()->json(
                self::withRequestId([
                    'ok' => false,
                    'error' => 'INVALID_SKU',
                    'error_code' => 'INVALID_SKU',
                    'message' => 'invalid sku.',
                ], $requestId),
                422
            );
        }

        if ($e instanceof ModelNotFoundException) {
            return response()->json(
                self::withRequestId([
                    'ok' => false,
                    'error' => 'NOT_FOUND',
                    'error_code' => 'NOT_FOUND',
                    'message' => 'Not Found',
                ], $requestId),
                404
            );
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $errorCode = self::mapHttpExceptionErrorCode($status);

            $message = trim($e->getMessage());
            if ($message === '') {
                $message = 'Request failed.';
            }

            return response()->json(
                self::withRequestId([
                    'ok' => false,
                    'error' => $errorCode,
                    'error_code' => $errorCode,
                    'message' => $message,
                ], $requestId),
                $status
            );
        }

        return response()->json(
            self::withRequestId([
                'ok' => false,
                'error' => 'INTERNAL_ERROR',
                'error_code' => 'INTERNAL_ERROR',
                'message' => 'Internal Server Error',
            ], $requestId),
            500
        );
    }

    private static function mapHttpExceptionErrorCode(int $status): string
    {
        return match ($status) {
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            429 => 'TOO_MANY_REQUESTS',
            default => 'HTTP_ERROR',
        };
    }

    private static function withRequestId(array $payload, string $requestId): array
    {
        if ($requestId !== '') {
            $payload['request_id'] = $requestId;
        }

        return $payload;
    }
}
