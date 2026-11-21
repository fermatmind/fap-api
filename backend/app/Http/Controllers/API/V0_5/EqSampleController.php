<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5;

use App\Http\Controllers\Controller;
use App\Services\Eq\Eq60ResultPageProjection;
use Illuminate\Http\JsonResponse;

final class EqSampleController extends Controller
{
    public function show(Eq60ResultPageProjection $projection): JsonResponse
    {
        // Fixed public example, never accepts an attempt, answers, identity or score input.
        $dimensions = [];
        foreach (['SA' => [58, 109], 'ER' => [47, 87], 'EM' => [54, 101], 'RM' => [48, 89]] as $code => [$raw, $score]) {
            $dimensions[$code] = ['raw_score' => $raw, 'standard_score' => $score];
        }
        $page = $projection->project(['scale_code' => 'EQ_60', 'schema_version' => 'eq_60.report.v2',
            'eq_report_mode' => 'self_report', 'scores' => ['dimensions' => $dimensions],
            'interpretation' => ['core_formulation_id' => 'aware_but_unregulated']]);

        return response()->json(['ok' => true, 'sample' => true, 'eq60_result_page_v1' => $page], 200,
            ['Cache-Control' => 'public, max-age=300', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
