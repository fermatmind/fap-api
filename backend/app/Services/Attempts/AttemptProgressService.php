<?php

namespace App\Services\Attempts;

use App\Models\Attempt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AttemptProgressService
{
    public function createDraftForAttempt(Attempt $attempt): array
    {
        $token = $this->generateToken();
        $expiresAt = Carbon::now()->addDays($this->draftTtlDays());
        $hash = $this->hashToken($token);

        $draft = [
            'attempt_id' => (string) $attempt->id,
            'org_id' => (int) ($attempt->org_id ?? 0),
            'resume_token_hash' => $hash,
            'last_seq' => 0,
            'cursor' => null,
            'duration_ms' => 0,
            'answers' => [],
            'answered_count' => 0,
            'updated_at' => Carbon::now()->toISOString(),
            'expires_at' => $expiresAt->toISOString(),
        ];

        $this->persistDraft($draft, true);
        $this->storeCache($draft, $expiresAt);

        return [
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    public function saveProgress(Attempt $attempt, ?string $token, ?int $userId, array $payload): array
    {
        $attemptId = (string) $attempt->id;
        $orgId = (int) ($attempt->org_id ?? 0);
        $draft = $this->loadDraft($attemptId);
        if (!$draft) {
            return [
                'ok' => false,
                'status' => 404,
                'error' => 'DRAFT_NOT_FOUND',
                'message' => 'draft not found.',
            ];
        }

        $expiresAt = $this->parseExpiresAt($draft['expires_at'] ?? null);
        if ($expiresAt && $expiresAt->isPast()) {
            return [
                'ok' => false,
                'status' => 410,
                'error' => 'RESUME_EXPIRED',
                'message' => 'resume token expired.',
            ];
        }

        if (($token === null || trim($token) === '') && $userId !== null && !$this->matchesAttemptOwner($attempt, $userId)) {
            return [
                'ok' => false,
                'status' => 404,
                'error' => 'DRAFT_NOT_FOUND',
                'message' => 'draft not found.',
            ];
        }

        if (!$this->canAccessDraft($draft, $token, $userId)) {
            return [
                'ok' => false,
                'status' => 404,
                'error' => 'DRAFT_NOT_FOUND',
                'message' => 'draft not found.',
            ];
        }

        $seq = (int) ($payload['seq'] ?? 0);
        $lastSeq = (int) ($draft['last_seq'] ?? 0);
        if ($seq < $lastSeq) {
            return [
                'ok' => false,
                'status' => 409,
                'error' => 'SEQ_OUT_OF_ORDER',
                'message' => 'progress seq out of order.',
                'data' => [
                    'last_seq' => $lastSeq,
                    'incoming_seq' => $seq,
                ],
            ];
        }

        $incomingAnswers = $payload['answers'] ?? [];
        if (!is_array($incomingAnswers)) {
            $incomingAnswers = [];
        }

        $existingAnswers = $draft['answers'] ?? [];
        if (!is_array($existingAnswers)) {
            $existingAnswers = [];
        }

        $merged = $this->mergeAnswers($existingAnswers, $incomingAnswers);
        $answeredCount = count($merged);

        $updated = [
            'attempt_id' => $attemptId,
            'org_id' => $orgId,
            'resume_token_hash' => (string) ($draft['resume_token_hash'] ?? ''),
            'last_seq' => $seq,
            'cursor' => $payload['cursor'] ?? null,
            'duration_ms' => (int) ($payload['duration_ms'] ?? 0),
            'answers' => array_values($merged),
            'answered_count' => $answeredCount,
            'updated_at' => Carbon::now()->toISOString(),
            'expires_at' => $expiresAt ? $expiresAt->toISOString() : null,
        ];

        $this->persistDraft($updated, false);
        $this->storeCache($updated, $expiresAt);

        return [
            'ok' => true,
            'data' => [
                'attempt_id' => $attemptId,
                'cursor' => $updated['cursor'],
                'duration_ms' => $updated['duration_ms'],
                'answered_count' => $answeredCount,
                'answers' => $updated['answers'],
                'updated_at' => $updated['updated_at'],
            ],
        ];
    }

    public function getProgress(Attempt $attempt, ?string $token, ?int $userId): array
    {
        $attemptId = (string) $attempt->id;
        $draft = $this->loadDraft($attemptId);
        if (!$draft) {
            return [
                'ok' => false,
                'status' => 404,
                'error' => 'DRAFT_NOT_FOUND',
                'message' => 'draft not found.',
            ];
        }

        $expiresAt = $this->parseExpiresAt($draft['expires_at'] ?? null);
        if ($expiresAt && $expiresAt->isPast()) {
            return [
                'ok' => false,
                'status' => 410,
                'error' => 'RESUME_EXPIRED',
                'message' => 'resume token expired.',
            ];
        }

        if (($token === null || trim($token) === '') && $userId !== null && !$this->matchesAttemptOwner($attempt, $userId)) {
            return [
                'ok' => false,
                'status' => 404,
                'error' => 'DRAFT_NOT_FOUND',
                'message' => 'draft not found.',
            ];
        }

        if (!$this->canAccessDraft($draft, $token, $userId)) {
            return [
                'ok' => false,
                'status' => 404,
                'error' => 'DRAFT_NOT_FOUND',
                'message' => 'draft not found.',
            ];
        }

        return [
            'ok' => true,
            'data' => [
                'attempt_id' => $attemptId,
                'cursor' => $draft['cursor'] ?? null,
                'duration_ms' => (int) ($draft['duration_ms'] ?? 0),
                'answered_count' => (int) ($draft['answered_count'] ?? 0),
                'answers' => $draft['answers'] ?? [],
                'updated_at' => $draft['updated_at'] ?? null,
                'expires_at' => $draft['expires_at'] ?? null,
            ],
        ];
    }

    public function clearProgress(string $attemptId): void
    {
        $this->forgetCache($attemptId);
        DB::table('attempt_drafts')->where('attempt_id', $attemptId)->delete();
    }

    public function loadDraftAnswers(Attempt $attempt): array
    {
        $row = DB::table('attempt_drafts')->where('attempt_id', (string) $attempt->id)->first();
        if (!$row) {
            return [];
        }

        $raw = $row->answers_json ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function persistDraft(array $draft, bool $isCreate): void
    {
        $now = Carbon::now();
        $payload = [
            'attempt_id' => $draft['attempt_id'],
            'org_id' => $draft['org_id'] ?? 0,
            'resume_token_hash' => $draft['resume_token_hash'] ?? '',
            'last_seq' => $draft['last_seq'] ?? 0,
            'cursor' => $draft['cursor'] ?? null,
            'duration_ms' => $draft['duration_ms'] ?? 0,
            'answers_json' => $this->encodeAnswers($draft['answers'] ?? []),
            'answered_count' => $draft['answered_count'] ?? 0,
            'updated_at' => $now,
            'expires_at' => $draft['expires_at'] ? Carbon::parse($draft['expires_at']) : null,
        ];

        if ($isCreate) {
            $payload['created_at'] = $now;
        }

        DB::table('attempt_drafts')->updateOrInsert(
            ['attempt_id' => $draft['attempt_id']],
            $payload
        );
    }

    private function loadDraft(string $attemptId): ?array
    {
        $cached = $this->loadCache($attemptId);
        if ($cached) {
            return $cached;
        }

        $row = DB::table('attempt_drafts')->where('attempt_id', $attemptId)->first();
        if (!$row) {
            return null;
        }

        $answers = [];
        $raw = $row->answers_json ?? null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $answers = $decoded;
            }
        }

        $draft = [
            'attempt_id' => (string) ($row->attempt_id ?? $attemptId),
            'org_id' => (int) ($row->org_id ?? 0),
            'resume_token_hash' => (string) ($row->resume_token_hash ?? ''),
            'last_seq' => (int) ($row->last_seq ?? 0),
            'cursor' => $row->cursor ?? null,
            'duration_ms' => (int) ($row->duration_ms ?? 0),
            'answers' => $answers,
            'answered_count' => (int) ($row->answered_count ?? 0),
            'updated_at' => $this->normalizeTimestamp($row->updated_at ?? null),
            'expires_at' => $this->normalizeTimestamp($row->expires_at ?? null),
        ];

        $expiresAt = $this->parseExpiresAt($draft['expires_at']);
        $this->storeCache($draft, $expiresAt);

        return $draft;
    }

    private function mergeAnswers(array $existing, array $incoming): array
    {
        $map = [];
        foreach ($existing as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $qid = trim((string) ($answer['question_id'] ?? ''));
            if ($qid === '') {
                continue;
            }
            $map[$qid] = $answer;
        }

        foreach ($incoming as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $qid = trim((string) ($answer['question_id'] ?? ''));
            if ($qid === '') {
                continue;
            }
            $map[$qid] = $answer;
        }

        ksort($map);

        return $map;
    }

    private function encodeAnswers(array $answers): ?string
    {
        $normalized = [];
        foreach ($answers as $answer) {
            if (!is_array($answer)) {
                continue;
            }
            $normalized[] = $answer;
        }

        return json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function generateToken(): string
    {
        return 'resume_' . (string) Str::uuid();
    }

    private function hashToken(string $token): string
    {
        $salt = (string) config('app.key', '');
        return hash('sha256', $token . '|' . $salt);
    }

    private function matchesAttemptOwner(Attempt $attempt, int $userId): bool
    {
        $attemptOwnerId = trim((string) ($attempt->user_id ?? ''));
        if ($attemptOwnerId === '') {
            return false;
        }

        return $attemptOwnerId === (string) $userId;
    }

    private function canAccessDraft(array $draft, ?string $token, ?int $userId): bool
    {
        if ($token === null || trim($token) === '') {
            return $userId !== null;
        }

        $hash = $this->hashToken(trim($token));
        return hash_equals((string) ($draft['resume_token_hash'] ?? ''), $hash);
    }

    private function draftTtlDays(): int
    {
        $days = (int) config('fap_attempts.draft_ttl_days', 14);
        return $days > 0 ? $days : 14;
    }

    private function cacheStore(): string
    {
        $store = (string) config('fap_attempts.draft_cache_store', '');
        return $store !== '' ? $store : config('cache.default');
    }

    private function cacheKey(string $attemptId): string
    {
        return 'attempt_draft:' . $attemptId;
    }

    private function storeCache(array $draft, ?Carbon $expiresAt): void
    {
        $ttl = null;
        if ($expiresAt) {
            $ttlSeconds = $expiresAt->getTimestamp() - time();
            if ($ttlSeconds > 0) {
                $ttl = $ttlSeconds;
            }
        }

        $store = $this->cacheStore();
        if ($ttl !== null) {
            Cache::store($store)->put($this->cacheKey($draft['attempt_id']), $draft, $ttl);
        } else {
            Cache::store($store)->put($this->cacheKey($draft['attempt_id']), $draft, 3600);
        }
    }

    private function loadCache(string $attemptId): ?array
    {
        $store = $this->cacheStore();
        $cached = Cache::store($store)->get($this->cacheKey($attemptId));
        return is_array($cached) ? $cached : null;
    }

    private function forgetCache(string $attemptId): void
    {
        $store = $this->cacheStore();
        Cache::store($store)->forget($this->cacheKey($attemptId));
    }

    private function parseExpiresAt(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function normalizeTimestamp($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->toISOString();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
