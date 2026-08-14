<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\API\V0_3\Concerns\ResolvesAttemptOwnership;
use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Services\Commerce\MembershipService;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MembershipController extends Controller
{
    use ResolvesAttemptOwnership;

    public function __construct(private readonly MembershipService $memberships) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->memberships->status(
            app(OrgContext::class)->orgId(),
            $this->resolveUserId($request),
            $this->resolveAnonId($request),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'plan_id' => ['required', 'string', 'in:annual,lifetime'],
            'sku' => ['prohibited'],
            'amount_cents' => ['prohibited'],
            'quantity' => ['prohibited'],
        ]);
        $orgId = app(OrgContext::class)->orgId();
        $userId = $this->resolveUserId($request);
        $anonId = $this->resolveAnonId($request);
        if ($userId === null && $anonId === null) {
            return response()->json(['ok' => false, 'error_code' => 'ACTOR_REQUIRED'], 422);
        }
        $planId = (string) $payload['plan_id'];
        $offer = $this->memberships->offer($orgId, $userId, $anonId, $planId);
        if ($offer === null) {
            return response()->json(['ok' => false, 'error_code' => 'MEMBERSHIP_ALREADY_ACTIVE'], 409);
        }
        $scale = match ((string) $offer['sku']) {
            'WEAPP_MEMBERSHIP_LIFETIME_UPGRADE_999' => 'MEMBERSHIP_UPGRADE',
            'WEAPP_MEMBERSHIP_LIFETIME_1999' => 'MEMBERSHIP_LIFETIME',
            default => 'MEMBERSHIP_ANNUAL',
        };
        $actor = $userId !== null ? 'user:'.$userId : 'anon:'.$anonId;
        $digest = hash('sha256', implode('|', [$orgId, $actor, $planId, $offer['sku'], $offer['quantity'], (string) \Illuminate\Support\Str::uuid()]));
        $query = Attempt::withoutGlobalScopes()->where('org_id', $orgId)->where('scale_code', $scale)->where('answers_digest', $digest);
        $userId !== null ? $query->where('user_id', $userId) : $query->where('anon_id', $anonId);
        $attempt = $query->first();
        if (! $attempt instanceof Attempt) {
            $attempt = Attempt::withoutGlobalScopes()->create([
                'anon_id' => $anonId ?? 'user:'.$userId,
                'user_id' => $userId,
                'org_id' => $orgId,
                'scale_code' => $scale,
                'scale_version' => 'membership.v1',
                'question_count' => 0,
                'answers_summary_json' => [],
                'client_platform' => 'wechat-miniprogram',
                'channel' => 'wechat_miniapp',
                'started_at' => now(),
                'submitted_at' => now(),
                'duration_ms' => 0,
                'answers_digest' => $digest,
            ]);
        }

        return response()->json([
            'ok' => true,
            'attempt_id' => (string) $attempt->id,
            'product' => $offer,
        ]);
    }
}
