<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\API\V0_3\Concerns\ResolvesAttemptOwnership;
use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Services\Commerce\ReportUnlockProductCatalog;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LocalReportSessionController extends Controller
{
    use ResolvesAttemptOwnership;

    public function __construct(private readonly ReportUnlockProductCatalog $products) {}

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'client_ref' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'scale_code' => ['prohibited'],
            'form_code' => ['prohibited'],
            'answers' => ['prohibited'],
            'scores' => ['prohibited'],
            'result' => ['prohibited'],
        ]);

        $orgId = app(OrgContext::class)->orgId();
        $userId = $this->resolveUserId($request);
        $anonId = $this->resolveAnonId($request);
        if ($userId === null && $anonId === null) {
            return response()->json([
                'ok' => false,
                'error_code' => 'ACTOR_REQUIRED',
                'message' => 'authenticated user or anonymous session is required.',
            ], 422);
        }

        $actor = $userId !== null ? 'user:'.$userId : 'anon:'.$anonId;
        $digest = hash('sha256', $orgId.'|'.$actor.'|'.trim((string) $payload['client_ref']));
        $query = Attempt::withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('scale_code', 'LOCAL_REPORT')
            ->where('answers_digest', $digest);
        if ($userId !== null) {
            $query->where('user_id', $userId);
        } else {
            $query->where('anon_id', $anonId);
        }

        $attempt = $query->first();
        if (! $attempt instanceof Attempt) {
            $attempt = Attempt::withoutGlobalScopes()->create([
                'anon_id' => $anonId ?? 'user:'.$userId,
                'user_id' => $userId,
                'org_id' => $orgId,
                'scale_code' => 'LOCAL_REPORT',
                'scale_version' => 'commerce.v1',
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

        $contract = $this->products->forScale('LOCAL_REPORT');
        $benefitCode = strtoupper(trim((string) ($contract['benefit_code'] ?? 'WEAPP_LOCAL_REPORT_FULL')));
        $granted = DB::table('benefit_grants')
            ->where('org_id', $orgId)
            ->where('attempt_id', (string) $attempt->id)
            ->where('benefit_code', $benefitCode)
            ->where('status', 'active')
            ->exists();

        return response()->json([
            'ok' => true,
            'attempt_id' => (string) $attempt->id,
            'granted' => $granted,
            'product' => [
                'sku' => (string) ($contract['sku'] ?? 'WEAPP_LOCAL_REPORT_FULL_199'),
                'price_cents' => (int) ($contract['price_cents'] ?? 199),
                'currency' => (string) ($contract['currency'] ?? 'CNY'),
                'display_price' => '¥1.99',
            ],
        ]);
    }
}
