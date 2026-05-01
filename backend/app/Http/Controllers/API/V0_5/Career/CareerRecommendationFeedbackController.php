<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Domain\Career\Feedback\CareerFeedbackTimelineAuthorityService;
use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class CareerRecommendationFeedbackController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerFeedbackTimelineAuthorityService $feedbackTimelineAuthorityService,
    ) {}

    public function store(Request $request, string $type): JsonResponse
    {
        $this->rejectUnexpectedKeys($request->all(), [
            'burnout_checkin',
            'career_satisfaction',
            'switch_urgency',
            'locale',
        ]);
        $this->validatePublicRecommendationType($type);

        $validated = $request->validate([
            'burnout_checkin' => ['required_without_all:career_satisfaction,switch_urgency', 'nullable', 'integer', 'between:1,5'],
            'career_satisfaction' => ['required_without_all:burnout_checkin,switch_urgency', 'nullable', 'integer', 'between:1,5'],
            'switch_urgency' => ['required_without_all:burnout_checkin,career_satisfaction', 'nullable', 'integer', 'between:1,5'],
        ]);

        $snapshot = $this->feedbackTimelineAuthorityService->resolveCurrentSnapshotByType($type);
        if ($snapshot === null) {
            return $this->notFoundResponse('career recommendation detail bundle unavailable.');
        }

        $currentSnapshot = $this->feedbackTimelineAuthorityService->recordPublicFeedback($snapshot, [
            ...$validated,
            'subject_slug' => strtolower(trim($type)),
        ]);

        $payload = $this->feedbackTimelineAuthorityService->buildForRecommendationSnapshot($currentSnapshot);

        return response()->json([
            'ok' => true,
            'data' => $payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowedKeys
     */
    private function rejectUnexpectedKeys(array $input, array $allowedKeys): void
    {
        $unexpected = array_values(array_diff(array_keys($input), $allowedKeys));
        if ($unexpected === []) {
            return;
        }

        throw ValidationException::withMessages([
            $unexpected[0] => 'Unexpected public feedback field.',
        ]);
    }

    private function validatePublicRecommendationType(string $type): void
    {
        if (preg_match('/\A[a-z]{4}(?:-[at])?\z/i', trim($type)) === 1) {
            return;
        }

        throw ValidationException::withMessages([
            'type' => 'type must be a public MBTI recommendation route slug.',
        ]);
    }
}
