<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Services\Cms\CareerRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class CareerRecommendationController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly CareerRecommendationService $careerRecommendationService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        return response()->json([
            'items' => $this->careerRecommendationService->listPublicRecommendations(
                $validated['org_id'],
                $validated['locale'],
            ),
        ]);
    }

    public function show(Request $request, string $type): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $payload = $this->careerRecommendationService->getPublicRecommendationByType(
            $type,
            $validated['org_id'],
            $validated['locale'],
        );

        if (! is_array($payload)) {
            return $this->notFoundResponse('career recommendation not found.');
        }

        return response()->json($payload);
    }

    /**
     * @return array{org_id:int,locale:string}|JsonResponse
     */
    private function validateReadQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
            'locale' => ['required', 'in:en,zh-CN'],
        ]);

        if ($validator->fails()) {
            return $this->invalidArgument($validator->errors()->first());
        }

        $validated = $validator->validated();

        return [
            'org_id' => (int) ($validated['org_id'] ?? 0),
            'locale' => (string) $validated['locale'],
        ];
    }

    private function invalidArgument(string $message): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'INVALID_ARGUMENT',
            'message' => $message,
        ], 422);
    }
}
