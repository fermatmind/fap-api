<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Services\Analytics\CareerAttributionEventMapper;
use App\Services\Analytics\EventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class CareerAttributionEventController extends Controller
{
    /**
     * @var list<string>
     */
    private const TOP_LEVEL_KEYS = [
        'eventName',
        'payload',
        'anonymousId',
        'sessionId',
        'requestId',
        'path',
        'timestamp',
    ];

    /**
     * @var list<string>
     */
    private const PAYLOAD_KEYS = [
        'entry_surface',
        'source_page_type',
        'target_action',
        'landing_path',
        'route_family',
        'subject_kind',
        'subject_key',
        'query_mode',
        'query_text',
        'locale',
        'blocked_claim_kind',
    ];

    public function __construct(
        private readonly CareerAttributionEventMapper $mapper,
        private readonly EventRecorder $eventRecorder,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $this->authorizeIngest($request);
        $this->rejectUnexpectedKeys($request->all(), self::TOP_LEVEL_KEYS, 'request');

        $data = $request->validate([
            'eventName' => ['required', 'string', 'max:64'],
            'payload' => ['required', 'array:'.implode(',', self::PAYLOAD_KEYS)],
            'payload.entry_surface' => ['required', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.source_page_type' => ['required', 'string', 'max:64'],
            'payload.target_action' => ['required', 'string', 'max:128', 'regex:/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/'],
            'payload.landing_path' => ['required', 'string', 'max:512', 'regex:/\A\/[^\r\n]*\z/'],
            'payload.route_family' => ['required', 'string', 'max:64'],
            'payload.subject_kind' => ['required', 'string', 'max:64'],
            'payload.subject_key' => ['nullable', 'string', 'max:128', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'payload.query_mode' => ['required', 'string', 'max:32'],
            'payload.query_text' => ['nullable', 'string', 'max:128'],
            'payload.locale' => ['nullable', 'string', 'max:16', 'in:en,zh,zh-cn,zh-CN'],
            'payload.blocked_claim_kind' => ['nullable', 'string', 'max:64', 'regex:/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/'],
            'anonymousId' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'sessionId' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'requestId' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'path' => ['nullable', 'string', 'max:512', 'regex:/\A\/[^\r\n]*\z/'],
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

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowedKeys
     */
    private function rejectUnexpectedKeys(array $input, array $allowedKeys, string $scope): void
    {
        $unexpected = array_values(array_diff(array_keys($input), $allowedKeys));
        if ($unexpected === []) {
            return;
        }

        throw ValidationException::withMessages([
            $unexpected[0] => "Unexpected public career attribution $scope field.",
        ]);
    }
}
