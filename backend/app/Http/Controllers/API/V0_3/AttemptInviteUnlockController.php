<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Exceptions\Api\ApiProblemException;
use App\Http\Controllers\API\V0_3\Concerns\ResolvesAttemptOwnership;
use App\Http\Controllers\Controller;
use App\Models\Attempt;
use App\Services\Analytics\EventRecorder;
use App\Services\Attempts\AttemptInviteUnlockService;
use App\Services\Attempts\InviteUnlock\InviteUnlockDiagnostics;
use App\Support\Logging\SensitiveDiagnosticRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class AttemptInviteUnlockController extends Controller
{
    use ResolvesAttemptOwnership;

    public function __construct(
        private readonly AttemptInviteUnlockService $inviteUnlocks,
        private readonly EventRecorder $eventRecorder,
    ) {}

    /**
     * POST /api/v0.3/attempts/{id}/invite-unlocks
     */
    public function store(Request $request, string $id): JsonResponse
    {
        $startedAt = hrtime(true);
        $attempt = $this->resolveOwnedAttempt($request, $id);
        $result = $this->inviteUnlocks->createOrReuseInvite(
            $attempt,
            $this->resolveUserId($request),
            $this->resolveAnonId($request)
        );
        if ((bool) ($result['created'] ?? false) === true) {
            $attemptId = (string) ($attempt->id ?? '');
            $anonId = $this->resolveAnonId($request);
            $this->eventRecorder->record(
                'invite_unlock_created',
                $this->resolveEventUserId($request),
                [
                    'scale_code' => strtoupper(trim((string) ($attempt->scale_code ?? ''))),
                    'target_attempt_id' => $attemptId,
                    'invite_id' => (string) ($result['invite_id'] ?? ''),
                    'invite_code' => (string) ($result['invite_code'] ?? ''),
                    'completed_invitees' => (int) ($result['completed_invitees'] ?? 0),
                    'required_invitees' => (int) ($result['required_invitees'] ?? 2),
                    'unlock_stage' => 'locked',
                    'unlock_source' => 'none',
                ],
                [
                    'org_id' => (int) ($attempt->org_id ?? 0),
                    'anon_id' => $anonId !== null && trim($anonId) !== '' ? trim($anonId) : null,
                    'attempt_id' => $attemptId !== '' ? $attemptId : null,
                    'scale_code' => strtoupper(trim((string) ($attempt->scale_code ?? ''))),
                ]
            );
        }

        $diagnostics = is_array($result['invite_unlock_diag_v1'] ?? null)
            ? $result['invite_unlock_diag_v1']
            : InviteUnlockDiagnostics::build(
                (int) ($result['completed_invitees'] ?? 0),
                (int) ($result['required_invitees'] ?? 2),
                (string) ($result['unlock_stage'] ?? 'locked'),
                (string) ($result['unlock_source'] ?? 'none'),
                (string) ($result['status'] ?? 'pending'),
            );
        $durationMs = (int) floor((hrtime(true) - $startedAt) / 1_000_000);
        Log::info('INVITE_UNLOCK_API_DIAGNOSTIC', [
            'route' => 'attempts.invite_unlocks.store',
            'source' => __METHOD__,
            'org_id' => (int) ($attempt->org_id ?? 0),
            'attempt_fingerprint' => SensitiveDiagnosticRedactor::fingerprint((string) ($attempt->id ?? '')),
            'invite_fingerprint' => SensitiveDiagnosticRedactor::fingerprint((string) ($result['invite_id'] ?? '')),
            'invite_code_fingerprint' => SensitiveDiagnosticRedactor::fingerprint((string) ($result['invite_code'] ?? '')),
            'created' => (bool) ($result['created'] ?? false),
            'diagnostic_status' => (string) ($diagnostics['status'] ?? 'locked'),
            'unlock_stage' => (string) ($diagnostics['unlock_stage'] ?? 'locked'),
            'unlock_source' => (string) ($diagnostics['unlock_source'] ?? 'none'),
            'completed_invitees' => (int) ($diagnostics['completed_invitees'] ?? 0),
            'required_invitees' => (int) ($diagnostics['required_invitees'] ?? 2),
            'remaining_invitees' => (int) ($diagnostics['remaining_invitees'] ?? 2),
            'progress_percent' => (int) ($diagnostics['progress_percent'] ?? 0),
            'duration_ms' => $durationMs,
        ]);

        return response()->json(array_merge(['ok' => true], $result, ['invite_unlock_diag_v1' => $diagnostics]));
    }

    /**
     * GET /api/v0.3/attempts/{id}/invite-unlocks
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $startedAt = hrtime(true);
        $attempt = $this->resolveOwnedAttempt($request, $id);
        $result = $this->inviteUnlocks->getInviteProgress($attempt);
        $diagnostics = is_array($result['invite_unlock_diag_v1'] ?? null)
            ? $result['invite_unlock_diag_v1']
            : InviteUnlockDiagnostics::build(
                (int) ($result['completed_invitees'] ?? 0),
                (int) ($result['required_invitees'] ?? 2),
                (string) ($result['unlock_stage'] ?? 'locked'),
                (string) ($result['unlock_source'] ?? 'none'),
                isset($result['status']) ? (string) $result['status'] : null,
            );
        $durationMs = (int) floor((hrtime(true) - $startedAt) / 1_000_000);
        Log::info('INVITE_UNLOCK_API_DIAGNOSTIC', [
            'route' => 'attempts.invite_unlocks.show',
            'source' => __METHOD__,
            'org_id' => (int) ($attempt->org_id ?? 0),
            'attempt_fingerprint' => SensitiveDiagnosticRedactor::fingerprint((string) ($attempt->id ?? '')),
            'invite_fingerprint' => SensitiveDiagnosticRedactor::fingerprint((string) ($result['invite_id'] ?? '')),
            'invite_code_fingerprint' => SensitiveDiagnosticRedactor::fingerprint((string) ($result['invite_code'] ?? '')),
            'has_invite' => (bool) ($result['has_invite'] ?? false),
            'diagnostic_status' => (string) ($diagnostics['status'] ?? 'locked'),
            'unlock_stage' => (string) ($diagnostics['unlock_stage'] ?? 'locked'),
            'unlock_source' => (string) ($diagnostics['unlock_source'] ?? 'none'),
            'completed_invitees' => (int) ($diagnostics['completed_invitees'] ?? 0),
            'required_invitees' => (int) ($diagnostics['required_invitees'] ?? 2),
            'remaining_invitees' => (int) ($diagnostics['remaining_invitees'] ?? 2),
            'progress_percent' => (int) ($diagnostics['progress_percent'] ?? 0),
            'duration_ms' => $durationMs,
        ]);

        return response()->json(array_merge(['ok' => true], $result, ['invite_unlock_diag_v1' => $diagnostics]));
    }

    private function resolveOwnedAttempt(Request $request, string $id): Attempt
    {
        $attempt = $this->ownedAttemptQuery($request, $id)->first();
        if (! $attempt instanceof Attempt) {
            throw new ApiProblemException(404, 'RESOURCE_NOT_FOUND', 'attempt not found.');
        }

        return $attempt;
    }

    private function resolveEventUserId(Request $request): ?int
    {
        $userId = trim((string) ($this->resolveUserId($request) ?? ''));
        if ($userId === '' || preg_match('/^\d+$/', $userId) !== 1) {
            return null;
        }

        return (int) $userId;
    }
}
