<?php

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_3\GetShareRequest;
use App\Http\Requests\V0_3\ShareClickRequest;
use App\Http\Requests\V0_3\ShareViewRequest;
use App\Services\V0_3\ShareFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class ShareController extends Controller
{
    public function __construct(private readonly ShareFlowService $shareFlow)
    {
    }

    public function click(ShareClickRequest $request, string $shareId): JsonResponse
    {
        $routeShareId = (string) $request->route('shareId', $shareId);
        $this->ensureSupportedShareId($routeShareId);

        try {
            $result = $this->shareFlow->clickAndComposeReport(
                $routeShareId,
                $request->validated(),
                $this->requestMeta($request)
            );

            return response()->json(array_merge(['ok' => true], $result), 200);
        } catch (Throwable $e) {
            $this->logShareFlowFailed($request, 'click', $routeShareId, null, $e);
            throw $e;
        }
    }

    public function getShare(GetShareRequest $request, string $id): JsonResponse
    {
        try {
            $input = array_merge($request->validated(), $this->requestMeta($request));
            $result = $this->shareFlow->getShareLinkForAttempt($id, $input);

            return response()->json(array_merge(['ok' => true], $result), 200);
        } catch (Throwable $e) {
            $this->logShareFlowFailed($request, 'get_share', null, $id, $e);
            throw $e;
        }
    }

    public function getShareView(ShareViewRequest $request, string $id): JsonResponse
    {
        $shareId = (string) ($request->validated()['id'] ?? $id);
        $result = $this->shareFlow->getShareView($shareId);

        return response()->json(array_merge(['ok' => true], $result), 200);
    }

    /**
     * @return array<string, string>
     */
    private function requestMeta(Request $request): array
    {
        return [
            'ip' => (string) ($request->ip() ?? ''),
            'ua' => (string) ($request->userAgent() ?? ''),
            'referer' => (string) $request->header('Referer', ''),
            'experiment' => (string) $request->header('X-Experiment', ''),
            'version' => (string) $request->header('X-App-Version', ''),
            'channel' => (string) $request->header('X-Channel', ''),
            'client_platform' => (string) $request->header('X-Client-Platform', ''),
            'entry_page' => (string) $request->header('X-Entry-Page', ''),
        ];
    }

    private function logShareFlowFailed(
        Request $request,
        string $action,
        ?string $shareId,
        ?string $attemptId,
        Throwable $e
    ): void {
        Log::error('share_flow_failed', [
            'action' => $action,
            'share_id' => $shareId,
            'attempt_id' => $attemptId,
            'org_id' => $this->resolveOrgId($request),
            'request_id' => $this->resolveRequestId($request),
            'exception' => $e,
        ]);
    }

    private function resolveOrgId(Request $request): int
    {
        $orgId = $request->attributes->get('org_id');
        if (!is_numeric($orgId)) {
            $orgId = $request->attributes->get('fm_org_id');
        }

        return is_numeric($orgId) ? (int) $orgId : 0;
    }

    private function resolveRequestId(Request $request): string
    {
        $requestId = trim((string) ($request->attributes->get('request_id') ?? ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $requestId = trim((string) $request->header('X-Request-Id', ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $requestId = trim((string) $request->header('X-Request-ID', ''));
        if ($requestId !== '') {
            return $requestId;
        }

        return (string) Str::uuid();
    }

    private function ensureSupportedShareId(string $shareId): void
    {
        $normalized = trim($shareId);
        if ($normalized === '') {
            throw new NotFoundHttpException('Not Found');
        }

        if (Str::isUuid($normalized)) {
            return;
        }

        if (preg_match('/^[0-9a-fA-F]{32}$/', $normalized) === 1) {
            return;
        }

        if (preg_match('/^[A-Za-z0-9_]{6,128}$/', $normalized) === 1) {
            return;
        }

        throw new NotFoundHttpException('Not Found');
    }
}
