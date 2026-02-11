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
            return self::errorResponse(
                422,
                'VALIDATION_FAILED',
                'The given data was invalid.',
                $e->errors(),
                $requestId
            );
        }

        if ($e instanceof HttpResponseException) {
            return null;
        }

        if ($e instanceof InvalidSkuException) {
            return self::errorResponse(422, 'INVALID_SKU', 'invalid sku.', [], $requestId);
        }

        if ($e instanceof ModelNotFoundException) {
            return self::errorResponse(404, 'NOT_FOUND', 'not found.', [], $requestId);
        }

        if ($e instanceof \RuntimeException && trim($e->getMessage()) === 'CONTENT_PACK_ERROR') {
            $reason = trim((string) ($e->getPrevious()?->getMessage() ?? ''));
            $payload = [];
            if ($reason !== '') {
                $payload['details'] = ['reason' => $reason];
            }

            return self::errorResponse(
                500,
                'CONTENT_PACK_ERROR',
                'content pack resolve failed.',
                (array) ($payload['details'] ?? []),
                $requestId
            );
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $errorCode = self::mapHttpExceptionErrorCode($status);

            $message = trim($e->getMessage());
            if ($message === '') {
                $message = self::defaultMessageForStatus($status);
            }

            return self::errorResponse($status, $errorCode, $message, [], $requestId);
        }

        return self::errorResponse(500, 'SERVER_ERROR', 'server error.', [], $requestId);
    }

    private static function mapHttpExceptionErrorCode(int $status): string
    {
        return match ($status) {
            401 => 'UNAUTHORIZED',
            402 => 'PAYMENT_REQUIRED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            429 => 'RATE_LIMITED',
            500 => 'SERVER_ERROR',
            default => 'GENERIC_ERROR',
        };
    }

    private static function defaultMessageForStatus(int $status): string
    {
        return match ($status) {
            401 => 'unauthorized.',
            403 => 'forbidden.',
            404 => 'not found.',
            429 => 'rate limited.',
            500 => 'server error.',
            default => 'request failed.',
        };
    }

    private static function errorResponse(
        int $status,
        string $errorCode,
        string $message,
        array $details,
        string $requestId
    ): JsonResponse {
        $payload = [
            'ok' => false,
            'error_code' => $errorCode,
            'message' => $message,
            'details' => self::normalizeDetails($details),
        ];

        return response()->json(self::withRequestId($payload, $requestId), $status);
    }

    private static function normalizeDetails(array $details): array|object
    {
        return $details === [] ? (object) [] : $details;
    }

    private static function withRequestId(array $payload, string $requestId): array
    {
        if ($requestId !== '') {
            $payload['request_id'] = $requestId;
        }

        return $payload;
    }
}
