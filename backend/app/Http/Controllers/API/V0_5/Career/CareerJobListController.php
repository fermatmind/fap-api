<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\Career\Review\CareerPilotReviewEvidenceBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerJobListController extends Controller
{
    public function __construct(
        private readonly PublicCareerAuthorityResponseCache $responseCache,
        private readonly CareerPilotReviewEvidenceBridge $reviewEvidenceBridge,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $publicLocale = is_string($request->query('locale')) ? (string) $request->query('locale') : 'zh-CN';

        try {
            $payload = $this->responseCache->jobIndexPayload($publicLocale);

            return response()->json($this->reviewEvidenceBridge->projectJobIndexPayload($payload));
        } catch (\RuntimeException) {
            return response()->json([
                'ok' => false,
                'error_code' => 'CAREER_JOB_INDEX_NOT_WARM',
                'message' => 'career job index temporarily unavailable.',
            ], 503)->header('Retry-After', '60');
        }
    }
}
