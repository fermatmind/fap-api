<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\V0_3;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\SchemaBaseline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MbtiAttributionEventController extends Controller
{
    /**
     * @var list<string>
     */
    private const TOP_LEVEL_KEYS = [
        'eventName',
        'payload',
        'anonymousId',
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
        'slug',
        'test_slug',
        'scaleCode',
        'scale_code',
        'form_code',
        'landing_path',
        'current_path',
        'locale',
        'attempt_id',
        'attemptIdMasked',
        'target_attempt_id',
        'answered_count',
        'durationMs',
        'duration_ms',
        'duration_bucket',
        'order_no',
        'orderNo',
        'orderNoMasked',
        'order_id',
        'transaction_id',
        'amount',
        'value',
        'price',
        'currency',
        'provider',
        'pack_version',
        'manifest_hash',
        'norms_version',
        'quality_level',
        'locked',
        'variant',
        'sku_id',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'gclid',
        'msclkid',
        'fbclid',
        'referrer',
        'session_id',
        'url',
        'lang',
        'page_type',
        'source_url',
        'source_article',
        'target_test',
        'scale_id',
        'form_id',
    ];

    /**
     * @var list<string>
     */
    private const ALLOWED_EVENT_NAMES = [
        'landing_view',
        'start_click',
        'start_attempt',
        'submit_attempt',
        'view_result',
        'click_unlock',
        'create_order',
        'payment_confirmed',
        'purchase_success',
        'unlock_success',
        'invite_create_start',
        'invite_create_success',
        'invite_create_failed',
        'invite_share_or_copy',
        'invite_progress_advanced',
        'landing_pv',
        'article_to_test_click',
        'start_test',
        'complete_test',
    ];

    /**
     * @var list<string>
     */
    private const SEO_CONVERSION_EVENT_NAMES = [
        'landing_pv',
        'article_to_test_click',
        'start_test',
        'complete_test',
        'view_result',
    ];

    /**
     * @var list<string>
     */
    private const SEO_FORBIDDEN_IDENTIFIER_KEYS = [
        'attempt_id',
        'attemptIdMasked',
        'target_attempt_id',
        'order_no',
        'orderNo',
        'orderNoMasked',
        'order_id',
        'transaction_id',
    ];

    public function store(Request $request): JsonResponse
    {
        $this->authorizeIngest($request);
        $this->rejectUnexpectedKeys($request->all(), self::TOP_LEVEL_KEYS, 'request');
        $payloadInput = $request->input('payload');
        if ($payloadInput !== null && ! is_array($payloadInput)) {
            throw ValidationException::withMessages([
                'payload' => 'The payload field must be an array.',
            ]);
        }
        if (is_array($payloadInput)) {
            $this->rejectUnexpectedKeys($payloadInput, self::PAYLOAD_KEYS, 'payload');
        }

        $data = $request->validate([
            'eventName' => ['required', 'string', 'max:64'],
            'payload' => ['nullable', 'array'],
            'payload.entry_surface' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.source_page_type' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.target_action' => ['nullable', 'string', 'max:128', 'regex:/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/'],
            'payload.slug' => ['nullable', 'string', 'max:128', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'payload.test_slug' => ['nullable', 'string', 'max:128', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
            'payload.scaleCode' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.scale_code' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.form_code' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.landing_path' => ['nullable', 'string', 'max:512', 'regex:/\A\/[^\r\n]*\z/'],
            'payload.current_path' => ['nullable', 'string', 'max:512', 'regex:/\A\/[^\r\n]*\z/'],
            'payload.locale' => ['nullable', 'string', 'max:16', 'in:en,zh,zh-cn,zh-CN'],
            'payload.attempt_id' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.attemptIdMasked' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.target_attempt_id' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.answered_count' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'payload.durationMs' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'payload.duration_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'payload.duration_bucket' => ['nullable', 'string', 'max:32', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.order_no' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.orderNo' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.orderNoMasked' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.order_id' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.transaction_id' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.amount' => ['nullable', 'numeric', 'min:0'],
            'payload.value' => ['nullable', 'numeric', 'min:0'],
            'payload.price' => ['nullable', 'numeric', 'min:0'],
            'payload.currency' => ['nullable', 'string', 'size:3', 'regex:/\A[A-Z]{3}\z/'],
            'payload.provider' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.pack_version' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.manifest_hash' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.norms_version' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.quality_level' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.locked' => ['nullable', 'boolean'],
            'payload.variant' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.sku_id' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.utm_source' => ['nullable', 'string', 'max:128', 'regex:/\A[^\r\n]*\z/'],
            'payload.utm_medium' => ['nullable', 'string', 'max:128', 'regex:/\A[^\r\n]*\z/'],
            'payload.utm_campaign' => ['nullable', 'string', 'max:128', 'regex:/\A[^\r\n]*\z/'],
            'payload.utm_term' => ['nullable', 'string', 'max:128', 'regex:/\A[^\r\n]*\z/'],
            'payload.utm_content' => ['nullable', 'string', 'max:128', 'regex:/\A[^\r\n]*\z/'],
            'payload.gclid' => ['nullable', 'string', 'max:256', 'regex:/\A[^\r\n]*\z/'],
            'payload.msclkid' => ['nullable', 'string', 'max:256', 'regex:/\A[^\r\n]*\z/'],
            'payload.fbclid' => ['nullable', 'string', 'max:256', 'regex:/\A[^\r\n]*\z/'],
            'payload.referrer' => ['nullable', 'string', 'max:512', 'regex:/\A[^\r\n]*\z/'],
            'payload.session_id' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.url' => ['nullable', 'string', 'max:512', 'regex:/\A[^\r\n]*\z/'],
            'payload.lang' => ['nullable', 'string', 'max:16', 'in:en,zh,zh-cn,zh-CN'],
            'payload.page_type' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.source_url' => ['nullable', 'string', 'max:512', 'regex:/\A[^\r\n]*\z/'],
            'payload.source_article' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:\/-]+\z/'],
            'payload.target_test' => ['nullable', 'string', 'max:512', 'regex:/\A[^\r\n]*\z/'],
            'payload.scale_id' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'payload.form_id' => ['nullable', 'string', 'max:64', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'anonymousId' => ['nullable', 'string', 'max:128', 'regex:/\A[A-Za-z0-9._:-]+\z/'],
            'path' => ['nullable', 'string', 'max:512', 'regex:/\A\/[^\r\n]*\z/'],
            'timestamp' => ['nullable', 'date'],
        ]);

        $eventName = strtolower(trim((string) ($data['eventName'] ?? '')));
        if (! in_array($eventName, self::ALLOWED_EVENT_NAMES, true)) {
            return response()->json([
                'ok' => false,
                'error_code' => 'INVALID_EVENT_NAME',
                'message' => 'eventName is not supported by mbti attribution ingest.',
            ], 422);
        }

        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $path = $this->normalizeOptionalString($data['path'] ?? null, 2048) ?? '/';
        $isSeoConversionEvent = $this->isSeoConversionEvent($eventName, $payload);
        if ($isSeoConversionEvent) {
            $path = $this->sanitizeSeoPublicUrl($path, 'path') ?? '/';
            $payload = $this->sanitizeSeoConversionPayload($payload);
        }
        $anonymousId = $this->normalizeOptionalString($data['anonymousId'] ?? null, 128);
        $occurredAt = Carbon::parse((string) ($data['timestamp'] ?? now()->toISOString()));

        $attemptId = $this->normalizeOptionalString(
            $payload['attempt_id']
                ?? $payload['target_attempt_id']
                ?? null,
            64
        );

        $meta = [
            'entry_surface' => $this->normalizeOptionalString($payload['entry_surface'] ?? null, 128) ?? 'unknown',
            'source_page_type' => $this->normalizeOptionalString($payload['source_page_type'] ?? null, 64) ?? 'unknown',
            'target_action' => $this->normalizeOptionalString($payload['target_action'] ?? null, 128),
            'test_slug' => $this->normalizeOptionalString($payload['test_slug'] ?? null, 128),
            'form_code' => $this->normalizeOptionalString($payload['form_code'] ?? $payload['form_id'] ?? null, 64),
            'landing_path' => $this->normalizeOptionalString($payload['landing_path'] ?? $payload['url'] ?? null, 512) ?? $path,
            'path' => $path,
            'anonymous_id' => $anonymousId,
            'target_attempt_id' => $this->normalizeOptionalString($payload['target_attempt_id'] ?? null, 64),
            'attempt_id' => $attemptId,
            'raw_payload' => $payload,
        ];
        if ($isSeoConversionEvent) {
            $meta['seo_conversion'] = [
                'event_name' => $eventName,
                'url' => $this->normalizeOptionalString($payload['url'] ?? null, 512) ?? $path,
                'lang' => $this->normalizeOptionalString($payload['lang'] ?? $payload['locale'] ?? null, 16)
                    ?? ($path !== '' && str_starts_with($path, '/zh') ? 'zh' : 'en'),
                'page_type' => $this->normalizeOptionalString($payload['page_type'] ?? $payload['source_page_type'] ?? null, 64) ?? 'unknown',
                'source_url' => $this->normalizeOptionalString($payload['source_url'] ?? null, 512),
                'source_article' => $this->normalizeOptionalString($payload['source_article'] ?? null, 128),
                'target_test' => $this->normalizeOptionalString($payload['target_test'] ?? null, 512),
                'scale_id' => $this->normalizeOptionalString($payload['scale_id'] ?? $payload['scale_code'] ?? $payload['scaleCode'] ?? null, 64),
                'form_id' => $this->normalizeOptionalString($payload['form_id'] ?? $payload['form_code'] ?? null, 64),
                'session_id' => $this->normalizeOptionalString($payload['session_id'] ?? null, 128),
                'referrer' => $this->normalizeOptionalString($payload['referrer'] ?? null, 512),
            ];
        }

        $locale = $this->normalizeOptionalString($payload['locale'] ?? $payload['lang'] ?? null, 16)
            ?? ($path !== '' && str_starts_with($path, '/zh') ? 'zh' : 'en');

        $orgId = $this->resolveOrgId($request);
        $scaleCode = $this->normalizeOptionalString(
            $payload['scale_code']
                ?? $payload['scaleCode']
                ?? $payload['scale_id']
                ?? null,
            64
        ) ?? 'MBTI';

        $attributes = [
            'id' => (string) Str::uuid(),
            'event_code' => $eventName,
            'event_name' => $eventName,
            'org_id' => $orgId,
            'anon_id' => $anonymousId,
            'attempt_id' => $attemptId,
            'meta_json' => $meta,
            'occurred_at' => $occurredAt,
            'scale_code' => $scaleCode,
            'channel' => 'web',
            'locale' => $locale,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (SchemaBaseline::hasColumn('events', 'scale_code_v2')) {
            $attributes['scale_code_v2'] = $scaleCode;
        }

        Event::query()->create($attributes);

        return response()->json([
            'ok' => true,
            'event_code' => $eventName,
            'org_id' => $orgId,
        ], 202);
    }

    private function authorizeIngest(Request $request): void
    {
        $configuredToken = trim((string) config('fap.events.ingest_token', ''));
        if ($configuredToken === '') {
            abort(response()->json([
                'ok' => false,
                'error_code' => 'INGEST_DISABLED',
                'message' => 'MBTI attribution ingest is not configured.',
            ], 503));
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
        if (trim((string) ($request->header('X-Org-Id') ?? '')) !== '') {
            throw ValidationException::withMessages([
                'X-Org-Id' => 'Public MBTI attribution ingest does not accept caller-supplied tenant identifiers.',
            ]);
        }

        $attr = $request->attributes->get('org_id');
        if (is_numeric($attr)) {
            return max(0, (int) $attr);
        }

        return 0;
    }

    private function normalizeOptionalString(mixed $value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        if (mb_strlen($normalized, 'UTF-8') > $maxLength) {
            $normalized = mb_substr($normalized, 0, $maxLength, 'UTF-8');
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isSeoConversionEvent(string $eventName, array $payload): bool
    {
        if (! in_array($eventName, self::SEO_CONVERSION_EVENT_NAMES, true)) {
            return false;
        }

        if ($eventName !== 'view_result') {
            return true;
        }

        foreach (['url', 'lang', 'page_type', 'source_url', 'source_article', 'target_test', 'scale_id', 'form_id', 'session_id'] as $key) {
            if (array_key_exists($key, $payload)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeSeoConversionPayload(array $payload): array
    {
        foreach (self::SEO_FORBIDDEN_IDENTIFIER_KEYS as $key) {
            if ($this->normalizeOptionalString($payload[$key] ?? null, 256) !== null) {
                throw ValidationException::withMessages([
                    'payload.'.$key => 'Canonical SEO conversion ingest does not accept raw attempt, order, or result identifiers.',
                ]);
            }
        }

        $sessionId = $this->normalizeOptionalString($payload['session_id'] ?? null, 128);
        if ($sessionId !== null && ! preg_match('/\Aseo_sess_[A-Za-z0-9_-]{16,96}\z/', $sessionId)) {
            throw ValidationException::withMessages([
                'payload.session_id' => 'Canonical SEO conversion session_id must use the seo_sess_ public session format.',
            ]);
        }

        foreach (['url', 'source_url', 'referrer', 'landing_path', 'current_path'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = $this->sanitizeSeoPublicUrl($payload[$key], 'payload.'.$key);
            }
        }

        if (array_key_exists('target_test', $payload)) {
            $targetTest = $this->normalizeOptionalString($payload['target_test'], 512);
            if ($targetTest !== null && (str_starts_with($targetTest, '/') || preg_match('/\Ahttps?:\/\//i', $targetTest) === 1)) {
                $payload['target_test'] = $this->sanitizeSeoPublicUrl($targetTest, 'payload.target_test');
            }
        }

        $url = $this->normalizeOptionalString($payload['url'] ?? null, 512);
        if ($url !== null) {
            $payload['landing_path'] = $payload['landing_path'] ?? $url;
            $payload['current_path'] = $payload['current_path'] ?? $url;
        }

        if (isset($payload['lang']) && ! isset($payload['locale'])) {
            $payload['locale'] = $payload['lang'];
        }
        if (isset($payload['page_type']) && ! isset($payload['source_page_type'])) {
            $payload['source_page_type'] = $payload['page_type'];
        }
        if (isset($payload['form_id']) && ! isset($payload['form_code'])) {
            $payload['form_code'] = $payload['form_id'];
        }
        if (isset($payload['scale_id']) && ! isset($payload['scale_code'])) {
            $payload['scale_code'] = $payload['scale_id'];
        }

        return $payload;
    }

    private function sanitizeSeoPublicUrl(mixed $value, string $field): ?string
    {
        $normalized = $this->normalizeOptionalString($value, 512);
        if ($normalized === null) {
            return null;
        }

        $parts = parse_url($normalized);
        if ($parts === false) {
            throw ValidationException::withMessages([
                $field => 'Canonical SEO conversion URL is malformed.',
            ]);
        }

        $path = (string) ($parts['path'] ?? ($normalized[0] === '/' ? $normalized : '/'));
        if ($path === '') {
            $path = '/';
        }
        if (! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        if ($this->isPrivateAnalyticsPath($path)) {
            throw ValidationException::withMessages([
                $field => 'Canonical SEO conversion ingest does not accept private result, order, share, pay, or history paths.',
            ]);
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($scheme !== '' && ! in_array($scheme, ['http', 'https'], true)) {
            throw ValidationException::withMessages([
                $field => 'Canonical SEO conversion URL must use http, https, or a root-relative path.',
            ]);
        }

        if ($host !== '') {
            return ($scheme !== '' ? $scheme : 'https').'://'.$host.$path;
        }

        return $path;
    }

    private function isPrivateAnalyticsPath(string $path): bool
    {
        return preg_match('#(^|/)(result|results|order|orders|share|shares|pay|payment|history)(/|$)#i', $path) === 1;
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
            $unexpected[0] => "Unexpected MBTI attribution $scope field.",
        ]);
    }
}
