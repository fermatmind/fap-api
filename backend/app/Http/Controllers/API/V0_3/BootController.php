<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Services\Experiments\ExperimentAssigner;
use App\Services\Flags\FlagManager;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BootController extends Controller
{
    public function __construct(
        private FlagManager $flagManager,
        private ExperimentAssigner $experimentAssigner,
        private OrgContext $orgContext,
    ) {
    }

    /**
     * GET /api/v0.3/boot
     */
    public function show(Request $request): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->resolveAnonId($request);

        $flags = $this->flagManager->resolve($orgId, $userId, $anonId);
        $experiments = $this->experimentAssigner->assignActive($orgId, $anonId, $userId);

        return response()->json([
            'ok' => true,
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'flags' => $flags,
            'experiments' => $experiments,
        ]);
    }

    /**
     * GET /api/v0.3/flags
     */
    public function flags(Request $request): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->resolveAnonId($request);

        return response()->json([
            'ok' => true,
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'flags' => $this->flagManager->resolve($orgId, $userId, $anonId),
        ]);
    }

    /**
     * GET /api/v0.3/experiments
     */
    public function experiments(Request $request): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $userId = $this->orgContext->userId();
        $anonId = $this->resolveAnonId($request);

        return response()->json([
            'ok' => true,
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'experiments' => $this->experimentAssigner->assignActive($orgId, $anonId, $userId),
        ]);
    }

    private function resolveAnonId(Request $request): string
    {
        $candidates = [
            $request->attributes->get('anon_id'),
            $request->attributes->get('fm_anon_id'),
            $request->attributes->get('client_anon_id'),
            $request->query('anon_id'),
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) && !is_numeric($candidate)) {
                continue;
            }
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return 'anon_' . Str::uuid();
    }
}
