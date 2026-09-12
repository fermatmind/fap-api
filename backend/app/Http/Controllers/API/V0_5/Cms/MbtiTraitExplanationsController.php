<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Controller;
use App\Services\Cms\MbtiTraitExplanations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class MbtiTraitExplanationsController extends Controller
{
    public function __invoke(Request $request, MbtiTraitExplanations $catalog): JsonResponse
    {
        $request->validate(['locale' => ['required', 'in:zh-CN'], 'org_id' => ['sometimes', 'in:0']]);
        try {
            $document = $catalog->published();
        } catch (Throwable $error) {
            report($error);

            return response()->json(['ok' => false, 'error_code' => 'MBTI_TRAIT_CONTENT_UNAVAILABLE'], 503)->header('Cache-Control', 'no-store');
        }
        if ($document === null) {
            return response()->json(['ok' => false, 'error_code' => 'MBTI_TRAIT_CONTENT_NOT_FOUND'], 404)->header('Cache-Control', 'no-store');
        }

        return response()->json(['ok' => true, ...$document])->header('Cache-Control', 'no-store');
    }
}
