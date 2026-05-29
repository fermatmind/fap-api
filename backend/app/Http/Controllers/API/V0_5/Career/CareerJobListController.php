<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerJobListController extends Controller
{
    public function __construct(
        private readonly PublicCareerAuthorityResponseCache $responseCache,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $publicLocale = is_string($request->query('locale')) ? (string) $request->query('locale') : 'zh-CN';

        return response()->json($this->responseCache->jobIndexPayload($publicLocale));
    }
}
