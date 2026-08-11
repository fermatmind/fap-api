<?php

declare(strict_types=1);

use App\Models\AdminUser;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const FAILURE_PREFIX = 'OPS_OWNER_RECOVERY_FAILED:';

/**
 * @return never
 */
function failClosed(string $code): void
{
    fwrite(STDERR, FAILURE_PREFIX.$code.PHP_EOL);
    exit(1);
}

function requiredEnv(string $name): string
{
    $value = trim((string) getenv($name));

    if ($value === '') {
        failClosed('MISSING_'.$name);
    }

    return $value;
}

/**
 * @return array{email:string,password:string}
 */
function readSecretPayload(): array
{
    $raw = stream_get_contents(STDIN, 4097);

    if (! is_string($raw) || $raw === '' || strlen($raw) > 4096) {
        failClosed('INVALID_SECRET_PAYLOAD');
    }

    try {
        $payload = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        failClosed('INVALID_SECRET_PAYLOAD');
    }

    if (! is_array($payload) || array_keys($payload) !== ['email', 'password']) {
        failClosed('INVALID_SECRET_PAYLOAD');
    }

    $email = mb_strtolower(trim((string) $payload['email']));
    $password = (string) $payload['password'];

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
        failClosed('INVALID_EMAIL');
    }

    if (strlen($password) < 12 || strlen($password) > 256 || str_contains($password, "\0")) {
        failClosed('INVALID_PASSWORD');
    }

    return ['email' => $email, 'password' => $password];
}

/**
 * @return array{
 *   account_count:int,
 *   email_sha256:string,
 *   account_sha256:string,
 *   is_active:bool,
 *   is_locked:bool,
 *   password_matches:bool,
 *   totp_enrolled:bool,
 *   role_count:int,
 *   state_sha256:string
 * }
 */
function inspectAccount(string $email, string $password, bool $forUpdate = false): array
{
    $query = AdminUser::query()
        ->whereRaw('LOWER(email) = ?', [$email])
        ->orderBy('id')
        ->limit(2);

    if ($forUpdate) {
        $query->lockForUpdate();
    }

    $accounts = $query->get();
    $accountCount = $accounts->count();
    $emailSha256 = hash('sha256', $email);

    if ($accountCount !== 1) {
        return [
            'account_count' => $accountCount,
            'email_sha256' => $emailSha256,
            'account_sha256' => hash('sha256', 'missing-or-ambiguous|'.$emailSha256),
            'is_active' => false,
            'is_locked' => false,
            'password_matches' => false,
            'totp_enrolled' => false,
            'role_count' => 0,
            'state_sha256' => hash('sha256', 'missing-or-ambiguous|'.$emailSha256.'|'.$accountCount),
        ];
    }

    /** @var AdminUser $account */
    $account = $accounts->first();
    $accountSha256 = hash('sha256', $emailSha256.'|'.(string) $account->getKey());
    $isActive = (int) $account->is_active === 1;
    $isLocked = $account->locked_until !== null && $account->locked_until->isFuture();
    $passwordMatches = Hash::check($password, (string) $account->password);
    $totpEnrolled = $account->totp_enabled_at !== null && trim((string) $account->totp_secret) !== '';
    $roleIds = $account->roles()->orderBy('roles.id')->pluck('roles.id')->map(static fn ($id): int => (int) $id)->all();
    $state = [
        'account_sha256' => $accountSha256,
        'is_active' => $isActive,
        'is_locked' => $isLocked,
        'password_hash_sha256' => hash('sha256', (string) $account->password),
        'password_matches' => $passwordMatches,
        'totp_secret_sha256' => hash('sha256', (string) $account->totp_secret),
        'totp_enabled_at' => $account->totp_enabled_at?->toISOString(),
        'role_ids' => $roleIds,
        'failed_login_count' => (int) $account->failed_login_count,
        'updated_at' => $account->updated_at?->toISOString(),
    ];

    return [
        'account_count' => 1,
        'email_sha256' => $emailSha256,
        'account_sha256' => $accountSha256,
        'is_active' => $isActive,
        'is_locked' => $isLocked,
        'password_matches' => $passwordMatches,
        'totp_enrolled' => $totpEnrolled,
        'role_count' => count($roleIds),
        'state_sha256' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
    ];
}

function boolToken(bool $value): string
{
    return $value ? 'true' : 'false';
}

$mode = requiredEnv('MODE');
$deployPath = requiredEnv('DEPLOY_PATH');
$expectedActiveRevision = requiredEnv('EXPECTED_ACTIVE_REVISION');
$expectedStateSha256 = trim((string) getenv('EXPECTED_STATE_SHA256'));

if (! in_array($mode, ['preflight', 'apply'], true)) {
    failClosed('INVALID_MODE');
}

if (! preg_match('/^\/[A-Za-z0-9._\/-]+$/', $deployPath) || str_contains($deployPath, '..')) {
    failClosed('INVALID_DEPLOY_PATH');
}

if (! preg_match('/^[0-9a-f]{40}$/', $expectedActiveRevision)) {
    failClosed('INVALID_ACTIVE_REVISION');
}

if ($mode === 'preflight' && $expectedStateSha256 !== '') {
    failClosed('UNEXPECTED_STATE_BINDING');
}

if ($mode === 'apply' && ! preg_match('/^[0-9a-f]{64}$/', $expectedStateSha256)) {
    failClosed('INVALID_STATE_BINDING');
}

$currentLink = $deployPath.'/current';
$activeRelease = realpath($currentLink);
$releasesRoot = realpath($deployPath.'/releases');

if ($activeRelease === false || $releasesRoot === false || ! str_starts_with($activeRelease.'/', $releasesRoot.'/')) {
    failClosed('INVALID_ACTIVE_RELEASE');
}

$activeRevision = trim((string) @file_get_contents($activeRelease.'/REVISION'));

if ($activeRevision !== $expectedActiveRevision) {
    failClosed('ACTIVE_REVISION_MISMATCH');
}

$autoload = $activeRelease.'/backend/vendor/autoload.php';
$bootstrap = $activeRelease.'/backend/bootstrap/app.php';

if (! is_file($autoload) || ! is_file($bootstrap)) {
    failClosed('INVALID_APPLICATION_RELEASE');
}

require $autoload;
$app = require $bootstrap;
$app->make(Kernel::class)->bootstrap();

$secret = readSecretPayload();
$before = inspectAccount($secret['email'], $secret['password']);
$recoveryRequired = $before['account_count'] === 1
    && (! $before['is_active'] || $before['is_locked'] || ! $before['password_matches']);
$applySupported = $before['account_count'] === 1;

if ($mode === 'preflight') {
    echo implode("\t", [
        $activeRevision,
        $before['email_sha256'],
        $before['account_sha256'],
        (string) $before['account_count'],
        boolToken($before['is_active']),
        boolToken($before['is_locked']),
        boolToken($before['password_matches']),
        boolToken($before['totp_enrolled']),
        (string) $before['role_count'],
        $before['state_sha256'],
        boolToken($recoveryRequired),
        boolToken($applySupported),
    ]);
    exit(0);
}

if ($before['state_sha256'] !== $expectedStateSha256 || ! $applySupported) {
    failClosed('PREFLIGHT_STATE_DRIFT');
}

$beforeTotpSecretSha256 = '';
$beforeTotpEnabledAt = '';
$beforeRoleIds = [];

DB::transaction(function () use (
    $secret,
    $expectedStateSha256,
    &$beforeTotpSecretSha256,
    &$beforeTotpEnabledAt,
    &$beforeRoleIds,
): void {
    $locked = inspectAccount($secret['email'], $secret['password'], true);

    if ($locked['account_count'] !== 1 || $locked['state_sha256'] !== $expectedStateSha256) {
        failClosed('LOCKED_STATE_DRIFT');
    }

    /** @var AdminUser $account */
    $account = AdminUser::query()
        ->whereRaw('LOWER(email) = ?', [$secret['email']])
        ->lockForUpdate()
        ->firstOrFail();

    $beforeTotpSecretSha256 = hash('sha256', (string) $account->totp_secret);
    $beforeTotpEnabledAt = $account->totp_enabled_at?->toISOString() ?? '';
    $beforeRoleIds = $account->roles()->orderBy('roles.id')->pluck('roles.id')->map(static fn ($id): int => (int) $id)->all();

    $account->forceFill([
        'password' => Hash::make($secret['password']),
        'password_changed_at' => now(),
        'failed_login_count' => 0,
        'locked_until' => null,
        'is_active' => 1,
    ])->save();
});

$after = inspectAccount($secret['email'], $secret['password']);
$reloaded = AdminUser::query()->whereRaw('LOWER(email) = ?', [$secret['email']])->firstOrFail();
$afterRoleIds = $reloaded->roles()->orderBy('roles.id')->pluck('roles.id')->map(static fn ($id): int => (int) $id)->all();
$totpPreserved = hash_equals($beforeTotpSecretSha256, hash('sha256', (string) $reloaded->totp_secret))
    && $beforeTotpEnabledAt === ($reloaded->totp_enabled_at?->toISOString() ?? '');
$rolesPreserved = $beforeRoleIds === $afterRoleIds;
$recoverySucceeded = $after['account_count'] === 1
    && $after['is_active']
    && ! $after['is_locked']
    && $after['password_matches']
    && $totpPreserved
    && $rolesPreserved;

if (! $recoverySucceeded) {
    failClosed('POST_WRITE_VERIFICATION_FAILED');
}

echo implode("\t", [
    $activeRevision,
    $after['email_sha256'],
    $after['account_sha256'],
    $after['state_sha256'],
    boolToken($totpPreserved),
    boolToken($rolesPreserved),
    boolToken($recoverySucceeded),
]);
