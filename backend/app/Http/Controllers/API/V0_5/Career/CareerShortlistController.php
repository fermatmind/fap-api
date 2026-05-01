<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Services\Career\CareerShortlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class CareerShortlistController extends Controller
{
    public function __construct(
        private readonly CareerShortlistService $shortlistService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $this->rejectUnexpectedKeys($request->all(), [
            'visitor_key',
            'subject_kind',
            'subject_slug',
            'source_page_type',
            'locale',
        ]);

        $validated = $request->validate([
            'visitor_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'subject_kind' => ['required', 'string', 'in:job_slug'],
            'subject_slug' => ['required', 'string', 'max:96', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'source_page_type' => ['required', 'string', 'in:career_job_detail,career_recommendation_detail'],
        ]);

        $result = $this->shortlistService->add($validated);
        $item = $result['item'];

        return response()->json([
            'ok' => true,
            'data' => [
                'shortlist_item_uuid' => (string) $item->id,
                'subject_kind' => (string) $item->subject_kind,
                'subject_slug' => (string) $item->subject_slug,
                'source_page_type' => (string) $item->source_page_type,
                'context_snapshot_uuid' => $item->context_snapshot_id,
                'projection_uuid' => $item->profile_projection_id,
                'recommendation_snapshot_uuid' => $item->recommendation_snapshot_id,
                'created_at' => optional($item->created_at)->toISOString(),
                'is_new' => (bool) ($result['is_new'] ?? false),
            ],
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        $this->rejectUnexpectedKeys($request->all(), [
            'visitor_key',
            'subject_kind',
            'subject_slug',
            'source_page_type',
            'locale',
        ]);

        $validated = $request->validate([
            'visitor_key' => ['required', 'string', 'min:8', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'subject_kind' => ['required', 'string', 'in:job_slug'],
            'subject_slug' => ['required', 'string', 'max:96', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'source_page_type' => ['required', 'string', 'in:career_job_detail,career_recommendation_detail'],
        ]);

        $state = $this->shortlistService->resolveState(
            visitorKey: (string) $validated['visitor_key'],
            subjectKind: (string) $validated['subject_kind'],
            subjectSlug: strtolower(trim((string) $validated['subject_slug'])),
            sourcePageType: strtolower(trim((string) $validated['source_page_type'])),
        );

        $item = $state['latest_item'];

        return response()->json([
            'ok' => true,
            'data' => [
                'is_shortlisted' => (bool) ($state['is_shortlisted'] ?? false),
                'latest_item' => $item ? [
                    'shortlist_item_uuid' => (string) $item->id,
                    'subject_kind' => (string) $item->subject_kind,
                    'subject_slug' => (string) $item->subject_slug,
                    'source_page_type' => (string) $item->source_page_type,
                    'created_at' => optional($item->created_at)->toISOString(),
                ] : null,
            ],
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
            $unexpected[0] => 'Unexpected public shortlist field.',
        ]);
    }
}
