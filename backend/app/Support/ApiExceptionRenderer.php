<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\InvalidSkuException;
use App\Models\Attempt;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiExceptionRenderer
{
    public static function render(Request $request, Throwable $e): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        $requestId = self::resolveRequestId($request);

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
            return self::errorResponse(
                404,
                self::resolveModelNotFoundErrorCode($request, $e),
                'Not found.',
                [],
                $requestId
            );
        }

        if ($e instanceof \RuntimeException && trim($e->getMessage()) === 'CONTENT_PACK_ERROR') {
            return self::errorResponse(
                500,
                'CONTENT_PACK_ERROR',
                'content pack resolve failed.',
                [],
                $requestId
            );
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $errorCode = self::resolveErrorCode($request, $e, $status);

            $message = trim($e->getMessage());
            if ($status === 404 && self::isFrameworkRouteMissingMessage($message)) {
                $message = self::defaultMessageForStatus($status);
            }
            if ($message === '') {
                $message = self::defaultMessageForStatus($status);
            }

            return self::errorResponse($status, $errorCode, $message, self::resolveDetails($e), $requestId);
        }

        return self::errorResponse(500, 'INTERNAL_ERROR', 'Internal error.', [], $requestId);
    }

    private static function mapHttpExceptionErrorCode(int $status): string
    {
        return match ($status) {
            401 => 'UNAUTHORIZED',
            402 => 'PAYMENT_REQUIRED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            429 => 'RATE_LIMITED',
            500 => 'INTERNAL_ERROR',
            default => 'GENERIC_ERROR',
        };
    }

    private static function defaultMessageForStatus(int $status): string
    {
        return SymfonyResponse::$statusTexts[$status] ?? 'Request failed.';
    }

    private static function resolveErrorCode(Request $request, Throwable $e, int $status): string
    {
        if ($status === 404) {
            $modelNotFound = self::extractModelNotFoundException($e);
            if ($modelNotFound !== null) {
                return self::resolveModelNotFoundErrorCode($request, $modelNotFound);
            }
        }

        if (method_exists($e, 'errorCode')) {
            $value = trim((string) $e->errorCode());
            if ($value !== '') {
                return $value;
            }
        }

        return self::mapHttpExceptionErrorCode($status);
    }

    private static function resolveDetails(Throwable $e): array
    {
        if (! method_exists($e, 'details')) {
            return [];
        }

        $details = $e->details();

        return is_array($details) ? $details : [];
    }

    private static function resolveModelNotFoundErrorCode(Request $request, ModelNotFoundException $e): string
    {
        $model = ltrim((string) $e->getModel(), '\\');
        if ($model === Attempt::class && $request->is('api/v0.3/attempts/*')) {
            return 'RESOURCE_NOT_FOUND';
        }

        return 'NOT_FOUND';
    }

    private static function extractModelNotFoundException(Throwable $e): ?ModelNotFoundException
    {
        if ($e instanceof ModelNotFoundException) {
            return $e;
        }

        $previous = $e->getPrevious();
        if ($previous instanceof ModelNotFoundException) {
            return $previous;
        }

        return null;
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

        return new JsonResponse(self::withRequestId($payload, $requestId), $status);
    }

    private static function normalizeDetails(array $details): array|object
    {
        return $details === [] ? (object) [] : $details;
    }

    private static function withRequestId(array $payload, string $requestId): array
    {
        $payload['request_id'] = $requestId;

        return $payload;
    }

    private static function isFrameworkRouteMissingMessage(string $message): bool
    {
        $normalized = strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'route')
            && str_contains($normalized, 'could not be found');
    }

    private static function resolveRequestId(Request $request): string
    {
        $requestId = trim((string) ($request->attributes->get('request_id') ?? ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $requestId = trim((string) $request->header('X-Request-Id', ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $requestId = trim((string) $request->header('X-Request-ID', ''));
        if ($requestId !== '') {
            return $requestId;
        }

        return (string) Str::uuid();
    }
}
