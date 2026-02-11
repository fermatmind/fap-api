<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_2;

use App\Exceptions\PaymentRequiredException;
use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Services\Legacy\LegacyMbtiReportService;
use App\Services\Report\ReportGatekeeper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegacyReportController extends Controller
{
    public function __construct(
        private LegacyMbtiReportService $service,
        private ReportGatekeeper $gatekeeper,
    ) {
    }

    public function getResult(Request $request, string $attemptId): JsonResponse
    {
        $attempt = $this->service->ownedAttemptOrFail($attemptId, $request);
        $this->enforceGatekeeperOrThrow($request, $attempt);

        $payload = $this->service->getResultPayload($attempt);

        $this->service->recordResultViewEvents($request, $attempt, $payload);

        return response()->json($payload, 200);
    }

    public function getReport(Request $request, string $attemptId): JsonResponse
    {
        $attempt = $this->service->ownedAttemptOrFail($attemptId, $request);
        $this->enforceGatekeeperOrThrow($request, $attempt);

        $payload = $this->service->getReportPayload($attempt, $request);

        return response()->json($payload['body'], $payload['status']);
    }

    private function enforceGatekeeperOrThrow(Request $request, Attempt $attempt): void
    {
        $gate = $this->gatekeeper->ensureAccess(
            (int) ($attempt->org_id ?? 0),
            (string) $attempt->id,
            $this->resolveUserId($request),
            $this->resolveAnonId($request),
            null
        );

        if (!($gate['ok'] ?? false)) {
            abort(404);
        }

        if (($gate['locked'] ?? false) === true) {
            throw new PaymentRequiredException();
        }
    }

    private function resolveUserId(Request $request): ?string
    {
        $userId = trim((string) ($request->user()?->id
            ?? $request->attributes->get('fm_user_id')
            ?? $request->attributes->get('user_id')
            ?? ''));

        return $userId !== '' ? $userId : null;
    }

    private function resolveAnonId(Request $request): ?string
    {
        $anonId = trim((string) ($request->attributes->get('anon_id')
            ?? $request->attributes->get('fm_anon_id')
            ?? $request->header('X-Anon-Id')
            ?? $request->query('anon_id', '')));

        return $anonId !== '' ? $anonId : null;
    }
}
