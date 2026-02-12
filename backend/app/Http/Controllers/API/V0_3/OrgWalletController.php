<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrgWalletController extends Controller
{
    public function __construct(
        private OrgContext $orgContext,
    ) {
    }

    /**
     * GET /api/v0.3/orgs/{org_id}/wallets
     */
    public function wallets(Request $request, string $org_id): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $role = $this->orgContext->role();

        if ((int) $org_id !== $orgId || $orgId <= 0 || !$this->isAdminRole($role)) {
            return $this->orgNotFound();
        }

        $items = DB::table('benefit_wallets')
            ->where('org_id', $orgId)
            ->orderBy('benefit_code')
            ->get();

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    /**
     * GET /api/v0.3/orgs/{org_id}/wallets/{benefit_code}/ledger
     */
    public function ledger(Request $request, string $org_id, string $benefit_code): JsonResponse
    {
        $orgId = $this->orgContext->orgId();
        $role = $this->orgContext->role();

        if ((int) $org_id !== $orgId || $orgId <= 0 || !$this->isAdminRole($role)) {
            return $this->orgNotFound();
        }

        $benefitCode = strtoupper(trim($benefit_code));
        if ($benefitCode === '') {
            return response()->json([
                'ok' => false,
                'error_code' => 'BENEFIT_REQUIRED',
                'message' => 'benefit_code is required.',
            ], 400);
        }

        $limit = (int) $request->query('limit', 50);
        if ($limit <= 0) {
            $limit = 50;
        }
        if ($limit > 200) {
            $limit = 200;
        }

        $items = DB::table('benefit_wallet_ledgers')
            ->where('org_id', $orgId)
            ->where('benefit_code', $benefitCode)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'ok' => true,
            'items' => $items,
        ]);
    }

    private function isAdminRole(?string $role): bool
    {
        return in_array($role, ['owner', 'admin'], true);
    }

    private function orgNotFound(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'error_code' => 'ORG_NOT_FOUND',
            'message' => 'org not found.',
        ], 404);
    }
}
