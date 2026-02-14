<?php

namespace App\Http\Controllers\API\V0_3;

use App\DTO\Attempts\StartAttemptDTO;
use App\DTO\Attempts\SubmitAttemptDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\V0_3\StartAttemptRequest;
use App\Http\Requests\V0_3\SubmitAttemptRequest;
use App\Services\Attempts\AttemptStartService;
use App\Services\Attempts\AttemptSubmitService;
use App\Support\OrgContext;
use Illuminate\Http\JsonResponse;

class AttemptWriteController extends Controller
{
    public function __construct(
        protected OrgContext $orgContext,
        private AttemptStartService $startService,
        private AttemptSubmitService $submitService,
    ) {}

    /**
     * POST /api/v0.3/attempts/start
     */
    public function start(StartAttemptRequest $request): JsonResponse
    {
        $payload = $request->validated();

        $anonId = trim((string) ($payload['anon_id'] ?? $request->header('X-Anon-Id') ?? ''));
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

        $result = $this->startService->start($this->orgContext, StartAttemptDTO::fromArray($payload));

        return response()->json($result);
    }

    /**
     * POST /api/v0.3/attempts/submit
     */
    public function submit(SubmitAttemptRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $attemptId = trim((string) ($payload['attempt_id'] ?? ''));

        $payload['user_id'] = $request->attributes->get('fm_user_id')
            ?? $request->attributes->get('user_id')
            ?? $this->orgContext->userId();
        $payload['anon_id'] = $request->attributes->get('anon_id')
            ?? $request->attributes->get('fm_anon_id')
            ?? $this->orgContext->anonId();

        $result = $this->submitService->submit($this->orgContext, $attemptId, SubmitAttemptDTO::fromArray($payload));

        return response()->json($result);
    }
}
