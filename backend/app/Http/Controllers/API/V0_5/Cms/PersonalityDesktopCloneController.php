<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Concerns\RespondsWithNotFound;
use App\Http\Controllers\Controller;
use App\Models\PersonalityProfile;
use App\Models\PersonalityResultIntroduction;
use App\Services\Cms\PersonalityDesktopCloneContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class PersonalityDesktopCloneController extends Controller
{
    use RespondsWithNotFound;

    public function __construct(
        private readonly PersonalityDesktopCloneContentService $service,
    ) {}

    public function intro(Request $request, string $type): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }
        // Global editorial assets have no tenant-specific or private-answer content.
        if ($validated['org_id'] !== 0 || ! preg_match('/^[EI][SN][TF][JP]-[AT]$/D', strtoupper($type))) {
            return $this->notFoundResponse('personality result introduction not found.');
        }
        $record = PersonalityResultIntroduction::publishedFor($type, $validated['locale']);
        if ($record === null) {
            return $this->notFoundResponse('personality result introduction not found.');
        }

        return response()->json([
            'ok' => true,
            'schema' => 'mbti_result_introduction.v1',
            'full_code' => $record->full_code,
            'locale' => $record->locale,
            'paragraphs' => $record->paragraphs,
            'revision' => $record->revision,
            'content_hash' => $record->content_hash,
        ])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $type): JsonResponse
    {
        $validated = $this->validateReadQuery($request);
        if ($validated instanceof JsonResponse) {
            return $validated;
        }

        $payload = $this->service->getPublishedByType(
            $type,
            $validated['org_id'],
            $validated['scale_code'],
            $validated['locale'],
        );

        if (! is_array($payload)) {
            return $this->notFoundResponse('personality desktop clone content not found.');
        }

        return response()->json([
            'ok' => true,
            ...$payload,
        ]);
    }

    /**
     * @return array{org_id:int,scale_code:string,locale:string}|JsonResponse
     */
    private function validateReadQuery(Request $request): array|JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'org_id' => ['nullable', 'integer', 'min:0'],
            'scale_code' => ['nullable', 'in:MBTI'],
            'locale' => ['required', 'in:en,zh-CN'],
        ]);

        if ($validator->fails()) {
            return $this->invalidArgument($validator->errors()->first());
        }

        $validated = $validator->validated();

        return [
            'org_id' => (int) ($validated['org_id'] ?? 0),
            'scale_code' => (string) ($validated['scale_code'] ?? PersonalityProfile::SCALE_CODE_MBTI),
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
