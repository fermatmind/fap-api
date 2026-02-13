<?php

namespace App\Http\Controllers\API\V0_2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V0_2\GetShareRequest;
use App\Http\Requests\V0_2\ShareClickRequest;
use App\Http\Requests\V0_2\ShareViewRequest;
use App\Services\Legacy\LegacyShareFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShareController extends Controller
{
    public function __construct(private readonly LegacyShareFlowService $shareFlow)
    {
    }

    public function click(ShareClickRequest $request, string $shareId): JsonResponse
    {
        $routeShareId = (string) $request->route('shareId', $shareId);
        $result = $this->shareFlow->clickAndComposeReport(
            $routeShareId,
            $request->validated(),
            $this->requestMeta($request)
        );

        return response()->json(array_merge(['ok' => true], $result), 200);
    }

    public function getShare(GetShareRequest $request, string $id): JsonResponse
    {
        $input = array_merge($request->validated(), $this->requestMeta($request));
        $result = $this->shareFlow->getShareLinkForAttempt($id, $input);

        return response()->json(array_merge(['ok' => true], $result), 200);
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
}
