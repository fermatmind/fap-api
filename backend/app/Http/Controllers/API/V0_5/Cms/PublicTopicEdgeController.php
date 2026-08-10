<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Cms;

use App\Http\Controllers\Controller;
use App\Models\PublicTopicEdge;
use App\Services\Cms\PublicTopicEdgeReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class PublicTopicEdgeController extends Controller
{
    public function __invoke(Request $request, PublicTopicEdgeReadModel $readModel): JsonResponse
    {
        $validated = $request->validate([
            'source_type' => [
                'required',
                'string',
                Rule::in(array_merge(PublicTopicEdge::PUBLIC_ENTITY_TYPES, PublicTopicEdge::CAREER_ENTITY_TYPES)),
            ],
            'source_id' => ['required', 'integer', 'min:1'],
            'source_locale' => ['required', 'string', Rule::in(PublicTopicEdge::SUPPORTED_LOCALES)],
        ]);

        return response()
            ->json($readModel->read(
                (string) $validated['source_type'],
                (int) $validated['source_id'],
                (string) $validated['source_locale'],
            ))
            ->header('Cache-Control', 'no-store');
    }
}
