<?php

namespace App\Services\Legacy;

use App\Exceptions\Api\ApiProblemException;
use App\Mail\EmailBindingVerificationMail;
use App\Services\Auth\IdentityService;
use App\Services\Legacy\Me\MeAttemptsService;
use App\Services\Legacy\Me\MeMetricsService;
use App\Services\Legacy\Me\MeProfileService;
use App\Support\OrgContext;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

class LegacyMeFacadeService
{
    public function __construct(
        private readonly OrgContext $orgContext,
        private readonly IdentityService $identityService,
        private readonly MeAttemptsService $attemptsService,
        private readonly MeMetricsService $metricsService,
        private readonly MeProfileService $profileService,
        private readonly LoggerInterface $logger,
        private readonly MailFactory $mailer,
        private readonly CacheRepository $cache,
    ) {
    }

    public function listAttempts(int $pageSize, int $page = 1): array
    {
        $userId = $this->orgContext->userId();

        return $this->attemptsService->list(
            max(0, (int) $this->orgContext->orgId()),
            $userId !== null ? (string) $userId : null,
            $this->orgContext->anonId(),
            $this->normalizePageSize($pageSize),
            max(1, $page)
        );
    }

    public function getProfile(): array
    {
        return $this->profileService->profile();
    }

    public function bindEmail(string $email): array
    {
        $userId = $this->orgContext->userId();
        if ($userId === null) {
            throw new ApiProblemException(401, 'UNAUTHORIZED', 'Missing or invalid fm_token.');
        }

        if (!\App\Support\SchemaBaseline::hasTable('users') || !\App\Support\SchemaBaseline::hasColumn('users', 'email')) {
            throw new ApiProblemException(500, 'INVALID_SCHEMA', 'users.email column not found.');
        }

        $pk = \App\Support\SchemaBaseline::hasColumn('users', 'uid') ? 'uid' : 'id';

        $exists = DB::table('users')
            ->where('email', $email)
            ->where($pk, '!=', (string) $userId)
            ->exists();

        if ($exists) {
            throw new ApiProblemException(422, 'EMAIL_IN_USE', 'email already in use.');
        }

        $update = ['email' => $email];
        if (\App\Support\SchemaBaseline::hasColumn('users', 'email_verified_at')) {
            $update['email_verified_at'] = now();
        }
        if (\App\Support\SchemaBaseline::hasColumn('users', 'updated_at')) {
            $update['updated_at'] = now();
        }

        try {
            $updated = DB::table('users')->where($pk, (string) $userId)->update($update);
        } catch (\Throwable $e) {
            $this->logger->warning('ME_BIND_EMAIL_UPDATE_FAILED', [
                'user_id' => (string) $userId,
                'exception' => $e::class,
            ]);

            throw new ApiProblemException(422, 'EMAIL_BIND_FAILED', 'email bind failed.');
        }

        if ($updated < 1) {
            throw new ApiProblemException(404, 'USER_NOT_FOUND', 'User not found for email bind.');
        }

        $identity = $this->identityService->bind(
            (string) $userId,
            'email',
            $email,
            [
                'bound_by' => 'me_bind_email',
                'org_id' => $this->orgContext->orgId(),
            ]
        );

        if (!($identity['ok'] ?? false)) {
            $error = (string) ($identity['error'] ?? 'IDENTITY_BIND_FAILED');
            if ($error === 'IDENTITY_CONFLICT') {
                throw new ApiProblemException(422, 'EMAIL_IN_USE', 'email already in use.');
            }

            if ($error === 'TABLE_MISSING') {
                throw new ApiProblemException(500, 'INVALID_SCHEMA', 'identities table missing.');
            }

            throw new ApiProblemException((int) ($identity['status'] ?? 422), 'IDENTITY_BIND_FAILED', (string) ($identity['message'] ?? 'identity bind failed.'));
        }

        $token = 'email_bind_' . (string) Str::uuid();
        $this->cache->put($this->verifyCacheKey($token), [
            'user_id' => (string) $userId,
            'email' => $email,
        ], now()->addMinutes(30));

        try {
            $this->mailer->to($email)->send(new EmailBindingVerificationMail($token));
        } catch (\Throwable $e) {
            $this->logger->warning('ME_BIND_EMAIL_SEND_FAILED', [
                'user_id' => (string) $userId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'email' => $email,
        ];
    }

    public function verifyBinding(string $token): array
    {
        $key = $this->verifyCacheKey($token);
        $payload = $this->cache->get($key);

        if (!is_array($payload)) {
            throw new ApiProblemException(422, 'INVALID_TOKEN', 'verification token invalid.');
        }

        $this->cache->forget($key);

        $email = trim((string) ($payload['email'] ?? ''));
        $userId = trim((string) ($payload['user_id'] ?? ''));

        if ($email !== '' && $userId !== '' && \App\Support\SchemaBaseline::hasTable('users') && \App\Support\SchemaBaseline::hasColumn('users', 'email_verified_at')) {
            DB::table('users')
                ->where('id', $userId)
                ->update([
                    'email_verified_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return [
            'verified' => true,
            'email' => $email,
        ];
    }

    public function sleepData(int $days): array
    {
        $userId = $this->orgContext->userId();

        return $this->metricsService->sleepData(
            $userId !== null ? (string) $userId : null,
            $this->normalizeDays($days)
        );
    }

    public function moodData(int $days): array
    {
        $userId = $this->orgContext->userId();

        return $this->metricsService->moodData(
            $userId !== null ? (string) $userId : null,
            $this->normalizeDays($days)
        );
    }

    public function screenTimeData(int $days): array
    {
        $userId = $this->orgContext->userId();

        return $this->metricsService->screenTimeData(
            $userId !== null ? (string) $userId : null,
            $this->normalizeDays($days)
        );
    }

    private function normalizePageSize(int $pageSize): int
    {
        if ($pageSize <= 0) {
            return 20;
        }

        return min(50, $pageSize);
    }

    private function normalizeDays(int $days): int
    {
        if ($days <= 0) {
            return 30;
        }

        return min(90, $days);
    }

    private function verifyCacheKey(string $token): string
    {
        return 'email_binding_verify:' . hash('sha256', $token);
    }
}
