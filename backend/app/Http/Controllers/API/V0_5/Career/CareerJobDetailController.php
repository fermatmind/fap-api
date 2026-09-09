<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Display\CareerCurrentIdentity;
use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerJobDetailController extends Controller
{
    use RespondsWithNotFound;

    private const PUBLIC_READ_CACHE_HEADER = 'X-Fermat-Public-Read-Cache';

    public function show(Request $request, string $slug): JsonResponse
    {
        $slug = app(CareerCurrentIdentity::class)->canonicalSlug($slug);
        $publicLocale = is_string($request->query('locale')) ? (string) $request->query('locale') : 'zh-CN';
        try {
            $payload = app(\App\Services\Career\CareerFilePageReader::class)->read($slug, $publicLocale, ! app(\App\Support\Career\CareerVerifyOnlyRequestAuthorizer::class)->isAuthorized($request));
        } catch (\App\Domain\Career\Display\CareerCurrentAuthorityPackageFailure $error) {
            return response()->json(['error' => 'CAREER_PAGE_UNAVAILABLE', 'code' => $error->safeCode], 503);
        }
        if ($payload === null) {
            return $this->notFoundResponse('career job detail bundle unavailable.');
        }

        return response()->json($payload)->header(self::PUBLIC_READ_CACHE_HEADER, 'fresh');
    }
}
