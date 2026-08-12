<?php

declare(strict_types=1);

namespace App\Support\Career;

use Illuminate\Http\Request;

final class CareerVerifyOnlyRequestAuthorizer
{
    public const MARKER_HEADER = 'X-Fermat-Career-Verify-Only';

    public const TIMESTAMP_HEADER = 'X-Fermat-Career-Verify-Timestamp';

    public const SIGNATURE_HEADER = 'X-Fermat-Career-Verify-Signature';

    private const AUTHORIZED_ATTRIBUTE = 'fermat_career_verify_only_authorized';

    private const MAX_CLOCK_SKEW_SECONDS = 120;

    public function isAuthorized(Request $request): bool
    {
        if ($request->attributes->getBoolean(self::AUTHORIZED_ATTRIBUTE)) {
            return true;
        }
        if (! $request->isMethod('GET')
            || ! $this->isExactCareerVerificationPath($request->path())
            || $request->header(self::MARKER_HEADER) !== '1') {
            return false;
        }

        $timestamp = (string) $request->header(self::TIMESTAMP_HEADER, '');
        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');
        $key = (string) config('app.key', '');
        if (preg_match('/^[1-9][0-9]{9}$/', $timestamp) !== 1
            || preg_match('/^[0-9a-f]{64}$/', $signature) !== 1
            || $key === ''
            || abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return false;
        }

        $expected = hash_hmac('sha256', self::signaturePayload($request->getRequestUri(), $timestamp), $key);
        if (! hash_equals($expected, $signature)) {
            return false;
        }

        $request->attributes->set(self::AUTHORIZED_ATTRIBUTE, true);

        return true;
    }

    public static function signaturePayload(string $requestUri, string $timestamp): string
    {
        return "GET\n{$requestUri}\n{$timestamp}";
    }

    private function isExactCareerVerificationPath(string $path): bool
    {
        return $path === 'api/v0.5/career/directory'
            || preg_match('#^api/v0\.5/career/jobs/[a-z0-9]+(?:-[a-z0-9]+)*$#', $path) === 1;
    }
}
