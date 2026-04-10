<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Services\Analytics\CareerAttributionEventMapper;
use App\Services\Analytics\EventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CareerAttributionEventController extends Controller
{
    public function __construct(
        private readonly CareerAttributionEventMapper $mapper,
        private readonly EventRecorder $eventRecorder,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $this->authorizeIngest($request);

        $data = $request->validate([
            'eventName' => ['required', 'string', 'max:64'],
            'payload' => ['nullable', 'array'],
            'anonymousId' => ['nullable', 'string', 'max:128'],
            'sessionId' => ['nullable', 'string', 'max:128'],
            'requestId' => ['nullable', 'string', 'max:128'],
            'path' => ['nullable', 'string', 'max:2048'],
            'timestamp' => ['nullable', 'date'],
        ]);

        $mapped = $this->mapper->map($data, $this->resolveOrgId($request));

        $this->eventRecorder->record(
            $mapped['event_code'],
            null,
            $mapped['meta'],
            $mapped['context'],
        );

        return response()->json([
            'ok' => true,
            'event_code' => $mapped['event_code'],
            'org_id' => (int) ($mapped['context']['org_id'] ?? 0),
        ], 202);
    }

    private function authorizeIngest(Request $request): void
    {
        $configuredToken = trim((string) config('fap.events.ingest_token', ''));
        if ($configuredToken === '') {
            return;
        }

        $provided = trim((string) ($request->bearerToken() ?? ''));
        if ($provided === '') {
            $provided = trim((string) $request->header('X-Track-Ingest-Token', ''));
        }

        if ($provided === '' || ! hash_equals($configuredToken, $provided)) {
            abort(response()->json([
                'ok' => false,
                'error_code' => 'UNAUTHORIZED',
                'message' => 'Invalid ingest token.',
            ], 401));
        }
    }

    private function resolveOrgId(Request $request): int
    {
        $attr = $request->attributes->get('org_id');
        if (is_numeric($attr)) {
            return max(0, (int) $attr);
        }

        $header = trim((string) ($request->header('X-Org-Id') ?? ''));
        if ($header !== '' && preg_match('/^\d+$/', $header) === 1) {
            return (int) $header;
        }

        return 0;
    }
}
