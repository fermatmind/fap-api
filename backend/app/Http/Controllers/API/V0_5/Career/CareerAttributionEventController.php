<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_5\Career;

use App\Http\Controllers\Controller;
use App\Services\Analytics\CareerAttributionEventMapper;
use App\Services\Analytics\EventRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

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
        'tool_run_id', 'output_format',
        'resource_key',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'traffic_quality',
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
            'payload.tool_run_id' => ['nullable', 'uuid'],
            'payload.output_format' => ['nullable', 'in:text,markdown,print'],
            'payload.resource_key' => ['nullable', 'in:onet_interest_profiler,mynextmove,onet_online,england_profiles,england_courses,england_skills,england_support'],
            'payload.utm_source' => ['nullable', 'string', 'max:96', 'regex:/\A[a-zA-Z0-9._-]+\z/'],
            'payload.utm_medium' => ['nullable', 'string', 'max:32', 'regex:/\A[a-zA-Z0-9._-]+\z/'],
            'payload.utm_campaign' => ['nullable', 'string', 'max:96', 'regex:/\A[a-zA-Z0-9._-]+\z/'],
            'payload.utm_content' => ['nullable', 'string', 'max:96', 'regex:/\A[a-zA-Z0-9._-]+\z/'],
            'payload.traffic_quality' => ['nullable', 'in:qa,internal,bot,unknown,production_user'],
            'anonymousId' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'sessionId' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'requestId' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'path' => ['nullable', 'string', 'max:512', 'regex:/\A\/[^\r\n]*\z/'],
            'timestamp' => ['nullable', 'date'],
        ]);

        $mapped = $this->mapper->map($data, $this->resolveOrgId($request));
        $requestId = trim((string) ($data['requestId'] ?? ''));
        $fingerprint = hash('sha256', json_encode([$data['eventName'], $data['payload'], $data['anonymousId'] ?? null, $data['path'] ?? null], JSON_THROW_ON_ERROR));
        if ($requestId !== '') {
            $mapped['context']['_event_id'] = Uuid::uuid5(Uuid::NAMESPACE_URL, 'career:'.$mapped['context']['org_id'].':'.$requestId)->toString();
            $mapped['meta']['ingest_fingerprint'] = $fingerprint;
        }
        $confirmed = $this->eventRecorder->recordConfirmed(
            $mapped['event_code'],
            null,
            $mapped['meta'],
            $mapped['context'],
        );

        if (! $confirmed) {
            return response()->json(['ok' => false, 'error_code' => 'EVENT_WRITE_FAILED'], 503);
        }
        if ($requestId !== '') {
            $row = DB::table('events')->where('org_id', $mapped['context']['org_id'])
                ->where('id', $mapped['context']['_event_id'])->first();
            if ($row === null) {
                return response()->json(['ok' => false, 'error_code' => 'EVENT_WRITE_FAILED'], 503);
            }
            $stored = json_decode((string) ($row->meta_json ?? '{}'), true);
            if (($stored['ingest_fingerprint'] ?? null) !== $fingerprint) {
                return response()->json(['ok' => false, 'error_code' => 'IDEMPOTENCY_CONFLICT'], 409);
            }
        }

        return response()->json([
            'ok' => true,
            'persisted' => true,
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
