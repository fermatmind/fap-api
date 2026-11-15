<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Services\Assessment\Scorers\CareerAssessmentScorer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;

final class CareerAssessmentController
{
    public function questions(CareerAssessmentScorer $scorer): JsonResponse
    {
        return $this->respond($scorer->questionnaire());
    }

    public function score(Request $request, CareerAssessmentScorer $scorer): JsonResponse
    {
        if (! $request->isJson()) {
            return $this->respond(['error_code' => 'EXPECTED_JSON'], 415);
        }

        try {
            $payload = json_decode($request->getContent(), associative: true, depth: 8, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->respond(['error_code' => 'INVALID_JSON'], 400);
        }

        $formVersion = $payload['form_version'] ?? null;
        $answers = $payload['answers'] ?? null;
        $items = array_column($scorer->questionnaire()['items'], null, 'id');
        if (! is_string($formVersion) || ! is_array($answers) || array_diff_key($items, $answers) !== []) {
            return $this->respond(['error_code' => 'INCOMPLETE_ASSESSMENT'], 422);
        }

        try {
            return $this->respond($scorer->score($formVersion, $answers));
        } catch (InvalidArgumentException) {
            return $this->respond(['error_code' => 'INVALID_ASSESSMENT'], 422);
        }
    }

    private function respond(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
