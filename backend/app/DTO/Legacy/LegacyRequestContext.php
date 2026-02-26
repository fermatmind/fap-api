<?php

declare(strict_types=1);

namespace App\DTO\Legacy;

use App\Support\OrgContext;
use Illuminate\Http\Request;

final class LegacyRequestContext
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $attributes
     */
    public function __construct(
        public readonly int $orgId,
        public readonly ?string $userId,
        public readonly ?string $anonId,
        public readonly ?string $requestId,
        public readonly ?string $sessionId,
        public readonly array $headers,
        public readonly array $query,
        public readonly array $input,
        public readonly array $attributes,
    ) {}

    public static function fromRequest(Request $request, OrgContext $orgContext): self
    {
        $scopedOrgId = self::normalizeOrgId(
            $request->attributes->get('org_id')
                ?? $request->attributes->get('fm_org_id')
        );
        if ($scopedOrgId === null) {
            $scopedOrgId = max(0, (int) $orgContext->orgId());
        }

        $userId = self::normalizeUserId(
            $request->attributes->get('fm_user_id')
                ?? $request->attributes->get('user_id')
                ?? $request->user()?->id
        );

        $anonId = self::nullableString(
            $request->attributes->get('anon_id')
                ?? $request->attributes->get('fm_anon_id')
                ?? $request->header('X-Anon-Id')
                ?? $request->header('X-FAP-Anon-Id')
                ?? $orgContext->anonId()
        );

        $requestId = self::nullableString(
            $request->attributes->get('request_id')
                ?? $request->header('X-Request-Id')
                ?? $request->header('X-Request-ID')
        );

        $sessionId = self::nullableString(
            $request->attributes->get('session_id')
                ?? $request->header('X-Session-Id')
        );

        $headers = [
            'x-experiment' => self::nullableString($request->header('X-Experiment')),
            'x-app-version' => self::nullableString($request->header('X-App-Version')),
            'x-channel' => self::nullableString($request->header('X-Channel')),
            'x-client-platform' => self::nullableString($request->header('X-Client-Platform')),
            'x-entry-page' => self::nullableString($request->header('X-Entry-Page')),
            'x-share-id' => self::nullableString($request->header('X-Share-Id') ?? $request->header('X-Share-ID')),
            'x-request-id' => self::nullableString($request->header('X-Request-Id') ?? $request->header('X-Request-ID')),
            'x-session-id' => self::nullableString($request->header('X-Session-Id')),
        ];

        $attributes = [];
        foreach ([
            'org_id',
            'fm_org_id',
            'fm_user_id',
            'user_id',
            'anon_id',
            'fm_anon_id',
            'request_id',
            'session_id',
            'channel',
            'client_platform',
            'client_version',
        ] as $key) {
            $value = self::nullableString($request->attributes->get($key));
            if ($value !== null) {
                $attributes[$key] = $value;
            }
        }

        $query = $request->query();
        if (! is_array($query)) {
            $query = [];
        }

        $input = $request->all();
        if (! is_array($input)) {
            $input = [];
        }

        return new self(
            orgId: $scopedOrgId,
            userId: $userId,
            anonId: $anonId,
            requestId: $requestId,
            sessionId: $sessionId,
            headers: array_filter($headers, static fn (?string $value): bool => $value !== null),
            query: $query,
            input: $input,
            attributes: $attributes,
        );
    }

    public function scopedOrgId(): int
    {
        return $this->orgId;
    }

    public function resolvedUserId(): ?string
    {
        return $this->userId;
    }

    public function resolvedAnonId(): ?string
    {
        return $this->anonId;
    }

    public function resolvedRequestId(): string
    {
        return $this->requestId ?? '';
    }

    public function queryString(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? null;
        if (is_string($value) || is_numeric($value)) {
            $normalized = trim((string) $value);

            return $normalized !== '' ? $normalized : $default;
        }

        return $default;
    }

    public function queryFlag(string $key): bool
    {
        $value = $this->queryString($key);

        return in_array($value, ['1', 'true', 'TRUE', 'yes', 'YES'], true);
    }

    public function includeContains(string $needle): bool
    {
        $includeRaw = $this->queryString('include');
        if ($includeRaw === '') {
            return false;
        }

        $include = array_values(array_filter(array_map('trim', explode(',', $includeRaw))));

        return in_array($needle, $include, true);
    }

    public function shareId(): ?string
    {
        $shareId = $this->queryString('share_id');
        if ($shareId !== '') {
            return $shareId;
        }

        $headerShareId = $this->headerValue('X-Share-Id') ?? $this->headerValue('X-Share-ID');
        if ($headerShareId === null) {
            return null;
        }

        $normalized = trim($headerShareId);

        return $normalized !== '' ? $normalized : null;
    }

    public function headerValue(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $queryOverrides
     * @param  array<string, mixed>  $inputOverrides
     */
    public function toEventRequest(array $queryOverrides = [], array $inputOverrides = []): Request
    {
        $query = array_merge($this->query, $queryOverrides);
        $input = array_merge($this->input, $inputOverrides);

        $server = [];
        foreach ($this->headers as $name => $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $request = Request::create('/internal/legacy/events', 'GET', $query, [], [], $server);
        $request->request->replace($input);

        foreach ($this->attributes as $key => $value) {
            if ($value === '') {
                continue;
            }
            $request->attributes->set($key, $value);
        }

        $request->attributes->set('org_id', (string) $this->orgId);
        $request->attributes->set('fm_org_id', (string) $this->orgId);

        if ($this->userId !== null) {
            $request->attributes->set('fm_user_id', $this->userId);
            $request->attributes->set('user_id', $this->userId);
        }

        if ($this->anonId !== null) {
            $request->attributes->set('anon_id', $this->anonId);
            $request->attributes->set('fm_anon_id', $this->anonId);
        }

        if ($this->requestId !== null) {
            $request->attributes->set('request_id', $this->requestId);
        }

        if ($this->sessionId !== null) {
            $request->attributes->set('session_id', $this->sessionId);
        }

        return $request;
    }

    private static function normalizeOrgId(mixed $value): ?int
    {
        $normalized = self::nullableString($value);
        if ($normalized === null || preg_match('/^\d+$/', $normalized) !== 1) {
            return null;
        }

        return (int) $normalized;
    }

    private static function normalizeUserId(mixed $value): ?string
    {
        $normalized = self::nullableString($value);
        if ($normalized === null || preg_match('/^\d+$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
