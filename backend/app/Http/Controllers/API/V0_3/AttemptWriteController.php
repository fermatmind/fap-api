<?php

namespace App\Http\Controllers\API\V0_3;

use App\DTO\Attempts\StartAttemptDTO;
use App\DTO\Attempts\SubmitAttemptDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\V0_3\StartAttemptRequest;
use App\Http\Requests\V0_3\SubmitAttemptRequest;
use App\Services\Analytics\AccessTestIdentity;
use App\Services\Attempts\AttemptStartService;
use App\Services\Attempts\AttemptSubmissionService;
use App\Support\OrgContext;
use App\Support\SchemaBaseline;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AttemptWriteController extends Controller
{
    public function __construct(
        private AttemptStartService $startService,
        private AttemptSubmissionService $submissionService,
        private AccessTestIdentity $accessTestIdentity,
    ) {}

    /**
     * POST /api/v0.3/attempts/start
     */
    public function start(StartAttemptRequest $request): JsonResponse
    {
        $context = app(OrgContext::class);
        $payload = $request->validated();

        $anonId = trim((string) ($request->attributes->get('client_anon_id') ?? $payload['anon_id'] ?? ''));
        if ($anonId !== '') {
            $payload['anon_id'] = $anonId;
        }

        $clientPlatform = trim((string) ($payload['client_platform'] ?? $request->header('X-Client-Platform') ?? ''));
        if ($clientPlatform !== '') {
            $payload['client_platform'] = $clientPlatform;
        }

        $clientVersion = trim((string) ($payload['client_version'] ?? $request->header('X-App-Version') ?? ''));
        if ($clientVersion !== '') {
            $payload['client_version'] = $clientVersion;
        }

        $channel = trim((string) ($payload['channel'] ?? $request->header('X-Channel') ?? ''));
        if ($channel !== '') {
            $payload['channel'] = $channel;
        }

        $referrer = trim((string) ($payload['referrer'] ?? $request->header('X-Referrer') ?? ''));
        if ($referrer !== '') {
            $payload['referrer'] = $referrer;
        }

        $resumeToken = trim((string) ($request->header('X-Resume-Token') ?: ($payload['resume_token'] ?? '')));
        if ($resumeToken !== '') {
            $payload['resume_token'] = $resumeToken;
        }

        $result = $this->startService->start($context, StartAttemptDTO::fromArray($payload));
        $this->captureAttemptIdentity($request, (string) ($result['attempt_id'] ?? ''), 'start');

        return response()->json($result);
    }

    /**
     * POST /api/v0.3/attempts/submit
     */
    public function submit(SubmitAttemptRequest $request): JsonResponse
    {
        $context = app(OrgContext::class);
        $payload = $request->validated();
        $attemptId = trim((string) ($payload['attempt_id'] ?? ''));

        $payload['user_id'] = $request->attributes->get('fm_user_id')
            ?? $request->attributes->get('user_id')
            ?? $context->userId();

        $attrAnonId = trim((string) (
            $request->attributes->get('anon_id')
            ?? $request->attributes->get('fm_anon_id')
            ?? ''
        ));

        // ✅ 必须从原始 request input 取，不能依赖 validated()，否则 rules 里没放 anon_id 就会被过滤掉
        $bodyAnonId = trim((string) ($request->input('anon_id') ?? ''));

        $ctxAnonId = trim((string) ($context->anonId() ?? ''));

        $payload['anon_id'] = $attrAnonId !== ''
            ? $attrAnonId
            : ($bodyAnonId !== '' ? $bodyAnonId : ($ctxAnonId !== '' ? $ctxAnonId : null));

        $mode = strtolower(trim((string) $request->query('mode', '')));
        $asyncEnabled = (bool) config('fap.features.submit_async_v2', false);
        $preferAsync = $asyncEnabled && $mode !== 'sync_legacy';

        $outcome = $this->submissionService->submit(
            $context,
            $attemptId,
            SubmitAttemptDTO::fromArray($payload),
            $preferAsync
        );

        $status = (int) ($outcome['http_status'] ?? 200);
        $result = is_array($outcome['payload'] ?? null) ? $outcome['payload'] : [];
        if (in_array($status, [200, 202], true)) {
            $this->captureAttemptIdentity($request, $attemptId, 'submit');
        }

        return response()->json($result, $status);
    }

    private function captureAttemptIdentity(
        StartAttemptRequest|SubmitAttemptRequest $request,
        string $attemptId,
        string $phase,
    ): void {
        if ($attemptId === '' || ! SchemaBaseline::hasColumn('attempts', 'analytics_rule_version')) {
            return;
        }

        try {
            $snapshot = $this->accessTestIdentity->snapshotRequest($request);
            $prefix = $phase === 'submit' ? 'analytics_submit' : 'analytics_start';

            DB::table('attempts')
                ->where('id', $attemptId)
                ->whereNull($prefix.'_ip_status')
                ->update([
                    $prefix.'_ip_hash' => $snapshot['ip_hash'],
                    $prefix.'_ip_status' => $snapshot['ip_status'],
                    $prefix.'_eligible' => $snapshot['eligible'],
                    $prefix.'_exclusion_reason' => $snapshot['exclusion_reason'],
                    'analytics_rule_version' => $snapshot['rule_version'],
                    'updated_at' => now(),
                ]);
        } catch (Throwable $exception) {
            Log::warning('ACCESS_TEST_IDENTITY_CAPTURE_FAILED', [
                'attempt_id' => $attemptId,
                'phase' => $phase,
                'exception' => $exception,
            ]);
        }
    }
}
