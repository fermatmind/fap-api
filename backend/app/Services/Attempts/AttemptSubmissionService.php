<?php

declare(strict_types=1);

namespace App\Services\Attempts;

use App\DTO\Attempts\SubmitAttemptDTO;
use App\Exceptions\Api\ApiProblemException;
use App\Jobs\ProcessAttemptSubmissionJob;
use App\Models\Attempt;
use App\Support\OrgContext;
use App\Support\SchemaBaseline;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AttemptSubmissionService
{
    public function __construct(
        private readonly AttemptSubmitService $attemptSubmitService,
    ) {}

    /**
     * @return array{http_status:int,payload:array<string,mixed>}
     */
    public function submit(OrgContext $ctx, string $attemptId, SubmitAttemptDTO $dto, bool $preferAsync): array
    {
        $attemptId = trim($attemptId);
        $orgId = $ctx->scopedOrgId();

        $hasTable = SchemaBaseline::hasTable('attempt_submissions');

        // sync: keep submit contract unchanged and mirror status into attempt_submissions for /submission.
        if (! $preferAsync || ! $hasTable) {
            try {
                $resultPayload = $this->attemptSubmitService->submit($ctx, $attemptId, $dto);
            } catch (ApiProblemException $e) {
                if ($hasTable) {
                    $this->recordSyncSubmissionFailure(
                        $ctx,
                        $attemptId,
                        $dto,
                        $e->errorCode(),
                        $e->getMessage()
                    );
                }
                throw $e;
            } catch (\Throwable $e) {
                if ($hasTable) {
                    $this->recordSyncSubmissionFailure(
                        $ctx,
                        $attemptId,
                        $dto,
                        'SUBMISSION_SYNC_FAILED',
                        $e::class.': '.$e->getMessage()
                    );
                }
                throw $e;
            }

            if ($hasTable) {
                $this->recordSyncSubmissionSuccess($ctx, $attemptId, $dto, $resultPayload);
            }

            return [
                'http_status' => 200,
                'payload' => $resultPayload,
            ];
        }

        if ($attemptId === '') {
            throw new ApiProblemException(400, 'VALIDATION_FAILED', 'attempt_id is required.');
        }

        $actorUserId = $this->resolveUserId($ctx, $dto->userId);
        $actorAnonId = $this->resolveAnonId($ctx, $dto->anonId);

        $attempt = $this->ownedAttemptQuery($ctx, $attemptId, $actorUserId, $actorAnonId)->first();
        if (! $attempt) {
            throw new ApiProblemException(404, 'RESOURCE_NOT_FOUND', 'attempt not found.');
        }

        $payload = $this->buildPayload($dto, $actorUserId, $actorAnonId);
        $dedupeKey = $this->buildDedupeKey($orgId, $attemptId, $payload);
        $now = now();

        $submission = DB::transaction(function () use ($orgId, $attemptId, $actorUserId, $actorAnonId, $dedupeKey, $payload, $now): array {
            $existing = DB::table('attempt_submissions')
                ->where('org_id', $orgId)
                ->where('dedupe_key', $dedupeKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return ['row' => $existing, 'created' => false];
            }

            $submissionId = (string) Str::uuid();
            DB::table('attempt_submissions')->insert([
                'id' => $submissionId,
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'actor_user_id' => $actorUserId !== null ? (int) $actorUserId : null,
                'actor_anon_id' => $actorAnonId,
                'dedupe_key' => $dedupeKey,
                'mode' => 'async',
                'state' => 'pending',
                'error_code' => null,
                'error_message' => null,
                'request_payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'response_payload_json' => null,
                'started_at' => null,
                'finished_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $fresh = DB::table('attempt_submissions')
                ->where('id', $submissionId)
                ->first();

            return ['row' => $fresh, 'created' => true];
        });

        /** @var object|null $row */
        $row = $submission['row'] ?? null;
        if ($row === null) {
            throw new ApiProblemException(500, 'INTERNAL_ERROR', 'submission creation failed.');
        }

        if (($submission['created'] ?? false) === true) {
            ProcessAttemptSubmissionJob::dispatch((string) $row->id)->afterCommit();
        }

        return [
            'http_status' => 202,
            'payload' => [
                'ok' => true,
                'attempt_id' => $attemptId,
                'submission_id' => (string) ($row->id ?? ''),
                'submission_state' => strtolower(trim((string) ($row->state ?? 'pending'))),
                'generating' => true,
                'mode' => 'async',
            ],
        ];
    }

    public function process(string $submissionId): void
    {
        $submissionId = trim($submissionId);
        if ($submissionId === '' || ! SchemaBaseline::hasTable('attempt_submissions')) {
            return;
        }

        $locked = DB::transaction(function () use ($submissionId): array {
            $row = DB::table('attempt_submissions')
                ->where('id', $submissionId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return ['state' => 'missing'];
            }

            $state = strtolower(trim((string) ($row->state ?? 'pending')));
            if (in_array($state, ['running', 'succeeded'], true)) {
                return ['state' => $state];
            }

            $now = now();
            DB::table('attempt_submissions')
                ->where('id', $submissionId)
                ->update([
                    'state' => 'running',
                    'error_code' => null,
                    'error_message' => null,
                    'started_at' => $now,
                    'updated_at' => $now,
                ]);

            $fresh = DB::table('attempt_submissions')
                ->where('id', $submissionId)
                ->first();

            return ['state' => 'ready', 'row' => $fresh];
        });

        if (($locked['state'] ?? '') !== 'ready') {
            return;
        }

        /** @var object|null $row */
        $row = $locked['row'] ?? null;
        if ($row === null) {
            return;
        }

        $orgId = (int) ($row->org_id ?? 0);
        $attemptId = trim((string) ($row->attempt_id ?? ''));
        $actorUserId = $this->normalizeUserId($row->actor_user_id ?? null);
        $actorAnonId = $this->normalizeAnonId($row->actor_anon_id ?? null);
        $payload = $this->decodeJsonArray($row->request_payload_json ?? null);

        if ($attemptId === '' || $payload === []) {
            $this->markFailed($submissionId, 'INVALID_SUBMISSION_PAYLOAD', 'submission payload is empty.');

            return;
        }

        $ctx = new OrgContext;
        $ctx->set(
            $orgId,
            $actorUserId !== null ? (int) $actorUserId : null,
            'public',
            $actorAnonId,
            OrgContext::deriveContextKind($orgId)
        );

        try {
            $result = $this->attemptSubmitService->submit($ctx, $attemptId, SubmitAttemptDTO::fromArray($payload));
            $this->markSucceeded($submissionId, $result);
        } catch (ApiProblemException $e) {
            $this->markFailed($submissionId, $e->errorCode(), $e->getMessage());
        } catch (\Throwable $e) {
            $this->markFailed($submissionId, 'SUBMISSION_JOB_FAILED', $e::class.': '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function latestForAttempt(OrgContext $ctx, string $attemptId, ?string $actorUserId, ?string $actorAnonId): array
    {
        if (! SchemaBaseline::hasTable('attempt_submissions')) {
            return [
                'ok' => false,
                'error_code' => 'SUBMISSION_NOT_READY',
                'message' => 'attempt submission table is not ready.',
                'http_status' => 503,
            ];
        }

        $attemptId = trim($attemptId);
        if ($attemptId === '') {
            return [
                'ok' => false,
                'error_code' => 'VALIDATION_FAILED',
                'message' => 'attempt_id is required.',
                'http_status' => 400,
            ];
        }

        $orgId = $ctx->scopedOrgId();
        $actorUserId = $this->normalizeUserId($actorUserId);
        $actorAnonId = $this->normalizeAnonId($actorAnonId);

        $query = DB::table('attempt_submissions')
            ->where('org_id', $orgId)
            ->where('attempt_id', $attemptId);

        if ($actorUserId !== null) {
            $query->where('actor_user_id', (int) $actorUserId);
        } elseif ($actorAnonId !== null) {
            $query->where('actor_anon_id', $actorAnonId);
        } else {
            return [
                'ok' => false,
                'error_code' => 'FORBIDDEN',
                'message' => 'submission owner unresolved.',
                'http_status' => 403,
            ];
        }

        $row = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->first();

        if ($row === null) {
            return [
                'ok' => false,
                'error_code' => 'SUBMISSION_NOT_FOUND',
                'message' => 'submission not found.',
                'http_status' => 404,
            ];
        }

        $state = strtolower(trim((string) ($row->state ?? 'pending')));
        $resultPayload = $this->decodeJsonArray($row->response_payload_json ?? null);

        return [
            'ok' => true,
            'attempt_id' => $attemptId,
            'submission' => [
                'id' => (string) ($row->id ?? ''),
                'mode' => strtolower(trim((string) ($row->mode ?? ''))),
                'state' => $state,
                'error_code' => $this->nullableString($row->error_code ?? null),
                'error_message' => $this->nullableString($row->error_message ?? null),
                'started_at' => $row->started_at !== null ? (string) $row->started_at : null,
                'finished_at' => $row->finished_at !== null ? (string) $row->finished_at : null,
                'updated_at' => $row->updated_at !== null ? (string) $row->updated_at : null,
            ],
            'result' => $resultPayload !== [] ? $resultPayload : null,
            'generating' => in_array($state, ['pending', 'running'], true),
            'http_status' => in_array($state, ['pending', 'running'], true) ? 202 : 200,
        ];
    }

    private function markSucceeded(string $submissionId, array $payload): void
    {
        DB::table('attempt_submissions')
            ->where('id', $submissionId)
            ->update([
                'state' => 'succeeded',
                'response_payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error_code' => null,
                'error_message' => null,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function markFailed(string $submissionId, string $errorCode, string $message): void
    {
        $errorCode = strtoupper(trim($errorCode));
        if ($errorCode === '') {
            $errorCode = 'SUBMISSION_FAILED';
        }

        DB::table('attempt_submissions')
            ->where('id', $submissionId)
            ->update([
                'state' => 'failed',
                'error_code' => $errorCode,
                'error_message' => $this->truncate($message, 255),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  array<string,mixed>  $responsePayload
     */
    private function recordSyncSubmissionSuccess(OrgContext $ctx, string $attemptId, SubmitAttemptDTO $dto, array $responsePayload): void
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '' || ! SchemaBaseline::hasTable('attempt_submissions')) {
            return;
        }

        $actorUserId = $this->resolveUserId($ctx, $dto->userId);
        $actorAnonId = $this->resolveAnonId($ctx, $dto->anonId);

        $payload = $this->buildPayload($dto, $actorUserId, $actorAnonId);
        $orgId = $ctx->scopedOrgId();
        $dedupeKey = $this->buildDedupeKey($orgId, $attemptId, $payload);
        $now = now();

        $encodedRequest = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $encodedResponse = json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        DB::transaction(function () use ($orgId, $attemptId, $actorUserId, $actorAnonId, $dedupeKey, $encodedRequest, $encodedResponse, $now): void {
            $existing = DB::table('attempt_submissions')
                ->where('org_id', $orgId)
                ->where('dedupe_key', $dedupeKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                DB::table('attempt_submissions')
                    ->where('id', (string) $existing->id)
                    ->update([
                        'mode' => 'sync',
                        'state' => 'succeeded',
                        'error_code' => null,
                        'error_message' => null,
                        'request_payload_json' => $encodedRequest,
                        'response_payload_json' => $encodedResponse,
                        'started_at' => $existing->started_at ?? $now,
                        'finished_at' => $now,
                        'updated_at' => $now,
                    ]);

                return;
            }

            DB::table('attempt_submissions')->insert([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'actor_user_id' => $actorUserId !== null ? (int) $actorUserId : null,
                'actor_anon_id' => $actorAnonId,
                'dedupe_key' => $dedupeKey,
                'mode' => 'sync',
                'state' => 'succeeded',
                'error_code' => null,
                'error_message' => null,
                'request_payload_json' => $encodedRequest,
                'response_payload_json' => $encodedResponse,
                'started_at' => $now,
                'finished_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function recordSyncSubmissionFailure(OrgContext $ctx, string $attemptId, SubmitAttemptDTO $dto, string $errorCode, string $message): void
    {
        $attemptId = trim($attemptId);
        if ($attemptId === '' || ! SchemaBaseline::hasTable('attempt_submissions')) {
            return;
        }

        $actorUserId = $this->resolveUserId($ctx, $dto->userId);
        $actorAnonId = $this->resolveAnonId($ctx, $dto->anonId);

        $payload = $this->buildPayload($dto, $actorUserId, $actorAnonId);
        $orgId = $ctx->scopedOrgId();
        $dedupeKey = $this->buildDedupeKey($orgId, $attemptId, $payload);
        $now = now();

        $errorCode = strtoupper(trim($errorCode));
        if ($errorCode === '') {
            $errorCode = 'SUBMISSION_FAILED';
        }

        $encodedRequest = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $errorMessage = $this->truncate($message, 255);

        DB::transaction(function () use ($orgId, $attemptId, $actorUserId, $actorAnonId, $dedupeKey, $encodedRequest, $errorCode, $errorMessage, $now): void {
            $existing = DB::table('attempt_submissions')
                ->where('org_id', $orgId)
                ->where('dedupe_key', $dedupeKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                DB::table('attempt_submissions')
                    ->where('id', (string) $existing->id)
                    ->update([
                        'mode' => 'sync',
                        'state' => 'failed',
                        'error_code' => $errorCode,
                        'error_message' => $errorMessage,
                        'request_payload_json' => $encodedRequest,
                        'response_payload_json' => null,
                        'started_at' => $existing->started_at ?? $now,
                        'finished_at' => $now,
                        'updated_at' => $now,
                    ]);

                return;
            }

            DB::table('attempt_submissions')->insert([
                'id' => (string) Str::uuid(),
                'org_id' => $orgId,
                'attempt_id' => $attemptId,
                'actor_user_id' => $actorUserId !== null ? (int) $actorUserId : null,
                'actor_anon_id' => $actorAnonId,
                'dedupe_key' => $dedupeKey,
                'mode' => 'sync',
                'state' => 'failed',
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'request_payload_json' => $encodedRequest,
                'response_payload_json' => null,
                'started_at' => $now,
                'finished_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    /**
     * @return array<string,mixed>
     */
    private function buildPayload(SubmitAttemptDTO $dto, ?string $actorUserId, ?string $actorAnonId): array
    {
        return [
            'answers' => $dto->answers,
            'validity_items' => $dto->validityItems,
            'duration_ms' => $dto->durationMs,
            'consent' => [
                'accepted' => $dto->consentAccepted,
                'version' => $dto->consentVersion,
                'hash' => $dto->consentHash,
            ],
            'invite_token' => $dto->inviteToken,
            'user_id' => $actorUserId,
            'anon_id' => $actorAnonId,
            'share_id' => $dto->shareId,
            'compare_invite_id' => $dto->compareInviteId,
            'share_click_id' => $dto->shareClickId,
            'entrypoint' => $dto->entrypoint,
            'referrer' => $dto->referrer,
            'landing_path' => $dto->landingPath,
            'utm' => $dto->utm,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function buildDedupeKey(int $orgId, string $attemptId, array $payload): string
    {
        $raw = json_encode([
            'org_id' => $orgId,
            'attempt_id' => $attemptId,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($raw === false) {
            $raw = $orgId.'|'.$attemptId.'|'.microtime(true);
        }

        return hash('sha256', $raw);
    }

    private function resolveUserId(OrgContext $ctx, ?string $actorUserId): ?string
    {
        $candidates = [
            $actorUserId,
            $ctx->userId(),
        ];

        foreach ($candidates as $candidate) {
            $resolved = $this->normalizeUserId($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function resolveAnonId(OrgContext $ctx, ?string $actorAnonId): ?string
    {
        $candidates = [
            $actorAnonId,
            $ctx->anonId(),
        ];

        foreach ($candidates as $candidate) {
            $resolved = $this->normalizeAnonId($candidate);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    private function normalizeUserId(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '' || preg_match('/^\d+$/', $normalized) !== 1) {
            return null;
        }

        return $normalized;
    }

    private function normalizeAnonId(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonArray(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function truncate(string $value, int $max): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'submission failed';
        }

        if (strlen($trimmed) <= $max) {
            return $trimmed;
        }

        return substr($trimmed, 0, $max);
    }

    private function ownedAttemptQuery(
        OrgContext $ctx,
        string $attemptId,
        ?string $actorUserId,
        ?string $actorAnonId
    ): Builder {
        $query = Attempt::onWriteConnection()
            ->where('id', $attemptId)
            ->where('org_id', $ctx->scopedOrgId());

        $actorUserId = $this->normalizeUserId($actorUserId);
        $actorAnonId = $this->normalizeAnonId($actorAnonId);

        if ($actorUserId !== null) {
            return $query->where('user_id', $actorUserId);
        }

        if ($actorAnonId !== null) {
            return $query->where('anon_id', $actorAnonId);
        }

        throw new ApiProblemException(401, 'UNAUTHORIZED', 'actor identity missing.');
    }
}
