<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Services\Career\Bundles\CareerCnProxyPublicOwnerSurfaceBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerCnProxyPublicOwnerController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerCnProxyPublicOwnerSurfaceBuilder $surfaceBuilder,
    ) {}

    public function show(Request $request, string $slug): JsonResponse
    {
        $publicLocale = is_string($request->query('locale')) ? (string) $request->query('locale') : 'zh-CN';
        $surface = $this->surfaceBuilder->buildBySlug($slug, $publicLocale);

        if ($surface === null) {
            return $this->notFoundResponse('career CN proxy public-owner surface unavailable.');
        }

        return response()->json($surface);
    }
}
