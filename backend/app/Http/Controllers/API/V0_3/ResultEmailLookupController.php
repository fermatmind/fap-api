<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_3\ResultEmailLookupRequest;
use App\Services\Results\ResultEmailLookupService;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;

final class ResultEmailLookupController extends Controller
{
    public function __construct(
        private readonly ResultEmailLookupService $lookup,
        private readonly OrgContext $orgContext,
    ) {}

    /**
     * POST /api/v0.3/results/lookup-by-email
     */
    public function store(ResultEmailLookupRequest $request): JsonResponse
    {
        /** @var array{email:string,locale?:string|null} $payload */
        $payload = $request->validated();

        return response()->json($this->lookup->lookup(
            (string) $payload['email'],
            (int) $this->orgContext->orgId(),
            $payload['locale'] ?? null,
            $this->orgContext->userId(),
            $this->orgContext->anonId(),
            $this->resolveClientAnonId($request),
        ));
    }

    private function resolveClientAnonId(ResultEmailLookupRequest $request): ?string
    {
        $candidates = [
            $request->attributes->get('client_anon_id'),
            $request->header('X-Anon-Id'),
            $request->cookie('fap_anonymous_id_v1'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) && ! is_numeric($candidate)) {
                continue;
            }

            $normalized = trim((string) $candidate);
            if ($normalized === '' || strlen($normalized) > 128) {
                continue;
            }

            $lower = mb_strtolower($normalized, 'UTF-8');
            $isPlaceholder = false;
            foreach (['todo', 'placeholder', 'fixme', 'tbd', '填这里'] as $bad) {
                if (mb_strpos($lower, $bad) !== false) {
                    $isPlaceholder = true;
                    break;
                }
            }

            if (! $isPlaceholder) {
                return $normalized;
            }
        }

        return null;
    }
}
