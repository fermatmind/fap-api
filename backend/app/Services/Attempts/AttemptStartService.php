<?php

namespace App\Services\Attempts;

use App\DTO\Attempts\StartAttemptDTO;
use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Services\Analytics\EventRecorder;
use App\Services\Content\ContentPacksIndex;
use App\Services\Scale\ScaleRegistry;
use App\Support\OrgContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AttemptStartService
{
    public function __construct(
        private ScaleRegistry $registry,
        private ContentPacksIndex $packsIndex,
        private AttemptProgressService $progressService,
        private EventRecorder $eventRecorder,
    ) {}

    public function start(OrgContext $ctx, StartAttemptDTO $dto): array
    {
        $orgId = $ctx->orgId();

        $scaleCode = $dto->scaleCode;
        if ($scaleCode === '') {
            throw new ApiProblemException(400, 'VALIDATION_FAILED', 'scale_code is required.');
        }

        $row = $this->registry->getByCode($scaleCode, $orgId);
        if (! $row) {
            throw new ApiProblemException(404, 'NOT_FOUND', 'scale not found.');
        }

        $packId = (string) ($row['default_pack_id'] ?? '');
        $dirVersion = (string) ($row['default_dir_version'] ?? '');
        if ($packId === '' || $dirVersion === '') {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'scale pack not configured.');
        }

        $region = (string) ($dto->region ?? $row['default_region'] ?? config('content_packs.default_region', ''));
        $locale = (string) ($dto->locale ?? $row['default_locale'] ?? config('content_packs.default_locale', ''));

        $questionCount = $this->resolveQuestionCount($packId, $dirVersion);
        $contentPackageVersion = $this->resolveContentPackageVersion($packId, $dirVersion);

        $anonId = trim((string) ($dto->anonId ?? ''));
        if ($anonId === '') {
            $anonId = 'anon_'.Str::uuid();
        }

        $clientPlatform = (string) ($dto->clientPlatform ?? 'unknown');
        $clientVersion = (string) ($dto->clientVersion ?? '');
        $channel = (string) ($dto->channel ?? '');
        $referrer = (string) ($dto->referrer ?? '');

        $attempt = Attempt::create([
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'user_id' => $ctx->userId(),
            'scale_code' => $scaleCode,
            'scale_version' => 'v0.3',
            'region' => $region,
            'locale' => $locale,
            'question_count' => $questionCount,
            'client_platform' => $clientPlatform,
            'client_version' => $clientVersion !== '' ? $clientVersion : null,
            'channel' => $channel !== '' ? $channel : null,
            'referrer' => $referrer !== '' ? $referrer : null,
            'started_at' => now(),
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => $contentPackageVersion !== '' ? $contentPackageVersion : null,
            'answers_summary_json' => [
                'stage' => 'start',
                'created_at_ms' => (int) round(microtime(true) * 1000),
                'meta' => $dto->meta,
            ],
        ]);

        $draft = $this->progressService->createDraftForAttempt($attempt);
        if (! empty($draft['expires_at'])) {
            $attempt->resume_expires_at = $draft['expires_at'];
            $attempt->save();
        }

        $this->eventRecorder->record('test_start', $ctx->userId(), [
            'scale_code' => $scaleCode,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => $contentPackageVersion,
            'attempt_id' => (string) $attempt->id,
        ], [
            'org_id' => $orgId,
            'anon_id' => $anonId,
            'attempt_id' => (string) $attempt->id,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'channel' => $channel !== '' ? $channel : null,
        ]);

        return [
            'ok' => true,
            'attempt_id' => (string) $attempt->id,
            'scale_code' => $scaleCode,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'region' => $region,
            'locale' => $locale,
            'question_count' => $questionCount,
            'resume_token' => (string) ($draft['token'] ?? ''),
            'resume_expires_at' => ! empty($draft['expires_at']) ? $draft['expires_at']->toISOString() : null,
        ];
    }

    private function resolveQuestionCount(string $packId, string $dirVersion): int
    {
        $questionsPath = '';

        $found = $this->packsIndex->find($packId, $dirVersion);
        if (! ($found['ok'] ?? false)) {
            $this->logAndThrowContentPackError(
                'QUESTIONS_INDEX_FIND_FAILED',
                $packId,
                $dirVersion,
                $questionsPath
            );
        }

        $item = $found['item'] ?? [];
        $questionsPath = (string) ($item['questions_path'] ?? '');
        if ($questionsPath === '' || ! File::exists($questionsPath)) {
            $this->logAndThrowContentPackError(
                'QUESTIONS_FILE_MISSING',
                $packId,
                $dirVersion,
                $questionsPath
            );
        }

        try {
            $raw = File::get($questionsPath);
        } catch (\Throwable $e) {
            $this->logAndThrowContentPackError(
                'QUESTIONS_FILE_READ_FAILED',
                $packId,
                $dirVersion,
                $questionsPath,
                $e
            );
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $this->logAndThrowContentPackError(
                'QUESTIONS_JSON_DECODE_FAILED',
                $packId,
                $dirVersion,
                $questionsPath
            );
        }

        $items = $decoded['items'] ?? null;
        if (! is_array($items)) {
            $this->logAndThrowContentPackError(
                'QUESTIONS_JSON_INVALID_SHAPE',
                $packId,
                $dirVersion,
                $questionsPath
            );
        }

        return count($items);
    }

    private function logAndThrowContentPackError(
        string $reason,
        string $packId,
        string $dirVersion,
        string $questionsPath,
        ?\Throwable $e = null
    ): never {
        Log::error($reason, [
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'questions_path' => $questionsPath,
            'exception_message' => $e?->getMessage() ?? $reason,
            'json_error' => json_last_error_msg(),
        ]);

        throw new \RuntimeException('CONTENT_PACK_ERROR', 0, $e);
    }

    private function resolveContentPackageVersion(string $packId, string $dirVersion): string
    {
        $found = $this->packsIndex->find($packId, $dirVersion);
        if (! ($found['ok'] ?? false)) {
            return '';
        }

        $item = $found['item'] ?? [];

        return (string) ($item['content_package_version'] ?? '');
    }
}
