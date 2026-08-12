<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Services\Career\CareerDirectoryAuthorityService;
use App\Support\Career\CareerVerifyOnlyRequestAuthorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

final class CareerDirectoryController extends Controller
{
    public function __construct(
        private readonly CareerDirectoryAuthorityService $directoryAuthority,
        private readonly CareerVerifyOnlyRequestAuthorizer $careerVerifyOnly,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->merge([
            'q' => is_string($request->query('q')) ? trim((string) $request->query('q')) : $request->query('q'),
            'family' => is_string($request->query('family')) ? trim((string) $request->query('family')) : $request->query('family'),
        ]);

        $validated = $request->validate([
            'locale' => ['nullable', 'string', Rule::in(['en', 'en-US', 'zh', 'zh-CN'])],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'family' => ['nullable', 'string', 'max:120'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);

        $verifyOnly = $this->careerVerifyOnly->isAuthorized($request);
        try {
            return response()->json($this->directoryAuthority->payload(
                locale: isset($validated['locale']) ? (string) $validated['locale'] : 'zh-CN',
                page: (int) ($validated['page'] ?? 1),
                perPage: (int) ($validated['per_page'] ?? 50),
                family: isset($validated['family']) ? (string) $validated['family'] : null,
                query: isset($validated['q']) ? (string) $validated['q'] : null,
                recordCacheState: ! $verifyOnly,
            ));
        } catch (Throwable $failure) {
            if (! $verifyOnly) {
                throw $failure;
            }

            return response()->json(['message' => 'career verify-only read unavailable.'], 503);
        }
    }
}
