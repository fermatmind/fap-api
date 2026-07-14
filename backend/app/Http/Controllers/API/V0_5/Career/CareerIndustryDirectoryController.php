<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Services\Career\CareerIndustryDirectoryReadModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class CareerIndustryDirectoryController extends Controller
{
    public function __construct(
        private readonly CareerIndustryDirectoryReadModel $readModel,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'locale' => ['nullable', 'string', Rule::in(['en', 'en-US', 'zh', 'zh-CN'])],
        ]);

        try {
            return response()->json($this->readModel->payload(
                isset($validated['locale']) ? (string) $validated['locale'] : 'zh-CN',
            ));
        } catch (\RuntimeException) {
            return response()->json([
                'ok' => false,
                'error_code' => 'CAREER_INDUSTRY_DIRECTORY_UNAVAILABLE',
                'message' => 'career industry directory temporarily unavailable.',
            ], 503)->header('Retry-After', '60');
        }
    }
}
