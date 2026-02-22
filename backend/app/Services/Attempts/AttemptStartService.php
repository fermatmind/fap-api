<?php

namespace App\Services\Attempts;

use App\DTO\Attempts\StartAttemptDTO;
use App\Exceptions\Api\ApiProblemException;
use App\Models\Attempt;
use App\Services\Analytics\EventRecorder;
use App\Services\Content\BigFivePackLoader;
use App\Services\Content\ContentPacksIndex;
use App\Services\Observability\BigFiveTelemetry;
use App\Services\Scale\ScaleRegistry;
use App\Services\Scale\ScaleRolloutGate;
use App\Support\OrgContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AttemptStartService
{
    public function __construct(
        private ScaleRegistry $registry,
        private ContentPacksIndex $packsIndex,
        private BigFivePackLoader $bigFivePackLoader,
        private AttemptRateLimitService $attemptRateLimitService,
        private AttemptProgressService $progressService,
        private EventRecorder $eventRecorder,
        private BigFiveTelemetry $bigFiveTelemetry,
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

        $region = (string) ($dto->region ?? $row['default_region'] ?? config('content_packs.default_region', ''));
        $locale = (string) ($dto->locale ?? $row['default_locale'] ?? config('content_packs.default_locale', ''));

        $anonId = trim((string) ($dto->anonId ?? ''));
        if ($anonId === '') {
            $anonId = 'anon_'.Str::uuid();
        }

        ScaleRolloutGate::assertEnabled($scaleCode, $row, $region, $anonId);

        if (strtoupper($scaleCode) === 'BIG5_OCEAN') {
            $retakePolicy = $this->resolveBigFiveRetakePolicy((string) ($row['default_dir_version'] ?? 'v1'));
            $this->attemptRateLimitService->assertRetakeAllowed(
                $orgId,
                $scaleCode,
                $ctx->userId(),
                $anonId,
                (int) ($retakePolicy['cooldown_hours'] ?? 24),
                (int) ($retakePolicy['max_attempts_per_30_days'] ?? 3)
            );
        }

        $packId = (string) ($row['default_pack_id'] ?? '');
        $dirVersion = (string) ($row['default_dir_version'] ?? '');
        if ($packId === '' || $dirVersion === '') {
            throw new ApiProblemException(500, 'CONTENT_PACK_ERROR', 'scale pack not configured.');
        }

        $questionCount = $this->resolveQuestionCount($scaleCode, $packId, $dirVersion);
        $contentPackageVersion = $this->resolveContentPackageVersion($packId, $dirVersion);
        $answersSummaryMeta = $dto->meta;
        if (strtoupper($scaleCode) === 'BIG5_OCEAN') {
            $legalCompiled = $this->bigFivePackLoader->readCompiledJson('legal.compiled.json', $dirVersion);
            $legal = is_array($legalCompiled['legal'] ?? null) ? $legalCompiled['legal'] : [];
            $disclaimerVersion = trim((string) ($legal['disclaimer_version'] ?? ''));
            $disclaimerHash = trim((string) ($legal['hash'] ?? ''));
            $normalizedLocale = str_starts_with(strtolower($locale), 'zh') ? 'zh-CN' : 'en';
            $disclaimerTexts = is_array($legal['texts'] ?? null) ? $legal['texts'] : [];
            $disclaimerText = trim((string) ($disclaimerTexts[$normalizedLocale] ?? ''));

            if ($disclaimerVersion === '') {
                $disclaimerVersion = 'BIG5_OCEAN_'.$dirVersion;
            }
            if ($disclaimerHash === '') {
                $disclaimerHash = hash('sha256', $disclaimerVersion.'|'.$disclaimerText);
            }

            $answersMeta = is_array($answersSummaryMeta) ? $answersSummaryMeta : [];
            $answersMeta['disclaimer_version_accepted'] = $disclaimerVersion;
            $answersMeta['disclaimer_hash'] = $disclaimerHash;
            $answersMeta['disclaimer_locale'] = $normalizedLocale;
            $answersSummaryMeta = $answersMeta;
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
                'meta' => $answersSummaryMeta,
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

        if (strtoupper($scaleCode) === 'BIG5_OCEAN') {
            $this->bigFiveTelemetry->recordAttemptStarted(
                $orgId,
                $ctx->userId(),
                $anonId,
                (string) $attempt->id,
                $locale,
                $region,
                $packId,
                $dirVersion
            );
        }

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

    private function resolveQuestionCount(string $scaleCode, string $packId, string $dirVersion): int
    {
        if (strtoupper($scaleCode) === 'BIG5_OCEAN') {
            $questionIndex = $this->bigFivePackLoader->readQuestionIndexPreferred($dirVersion, 120);
            if (is_array($questionIndex)) {
                return count($questionIndex);
            }

            $compiled = $this->bigFivePackLoader->readCompiledJson('questions.compiled.json', $dirVersion);
            if (!is_array($compiled)) {
                $this->logAndThrowContentPackError('BIG5_COMPILED_QUESTIONS_MISSING', $packId, $dirVersion, 'questions.compiled.json');
            }
            $doc = is_array($compiled['questions_doc'] ?? null) ? $compiled['questions_doc'] : [];
            $items = is_array($doc['items'] ?? null) ? $doc['items'] : null;
            if (!is_array($items)) {
                $this->logAndThrowContentPackError('BIG5_COMPILED_QUESTIONS_INVALID', $packId, $dirVersion, 'questions.compiled.json');
            }

            return count($items);
        }

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

    /**
     * @return array{cooldown_hours:int,max_attempts_per_30_days:int}
     */
    private function resolveBigFiveRetakePolicy(string $dirVersion): array
    {
        $cooldownHours = 24;
        $maxAttempts = 3;

        $compiled = $this->bigFivePackLoader->readCompiledJson('policy.compiled.json', $dirVersion);
        $policy = is_array($compiled['policy'] ?? null) ? $compiled['policy'] : [];
        $retake = is_array($policy['retake'] ?? null) ? $policy['retake'] : [];

        if ($retake === []) {
            $rawPath = base_path('content_packs/BIG5_OCEAN/v1/raw/policy.json');
            if (is_file($rawPath)) {
                $raw = json_decode((string) file_get_contents($rawPath), true);
                if (is_array($raw)) {
                    $retake = is_array($raw['retake'] ?? null) ? $raw['retake'] : [];
                }
            }
        }

        if (is_numeric($retake['cooldown_hours'] ?? null)) {
            $cooldownHours = max(0, (int) $retake['cooldown_hours']);
        }
        if (is_numeric($retake['max_attempts_per_30_days'] ?? null)) {
            $maxAttempts = max(0, (int) $retake['max_attempts_per_30_days']);
        }

        return [
            'cooldown_hours' => $cooldownHours,
            'max_attempts_per_30_days' => $maxAttempts,
        ];
    }

}
