<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Feedback\CareerFeedbackTimelineAuthorityService;
use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerRecommendationFeedbackController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerFeedbackTimelineAuthorityService $feedbackTimelineAuthorityService,
    ) {}

    public function store(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'burnout_checkin' => ['nullable', 'integer', 'between:1,5'],
            'career_satisfaction' => ['nullable', 'integer', 'between:1,5'],
            'switch_urgency' => ['nullable', 'integer', 'between:1,5'],
        ]);

        $snapshot = $this->feedbackTimelineAuthorityService->resolveCurrentSnapshotByType($type);
        if ($snapshot === null) {
            return $this->notFoundResponse('career recommendation detail bundle unavailable.');
        }

        $createdSnapshot = $this->feedbackTimelineAuthorityService->appendFeedbackRefresh($snapshot, [
            ...$validated,
            'subject_slug' => strtolower(trim($type)),
        ]);

        $payload = $this->feedbackTimelineAuthorityService->buildForRecommendationSnapshot($createdSnapshot);

        return response()->json([
            'ok' => true,
            'data' => $payload,
        ]);
    }
}

