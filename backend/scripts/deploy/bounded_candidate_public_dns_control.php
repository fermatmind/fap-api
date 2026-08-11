<?php

declare(strict_types=1);

use function Deployer\currentHost;
use function Deployer\deploySafeHost;
use function Deployer\deployShellArg;
use function Deployer\get;
use function Deployer\run;
use function Deployer\task;

const BOUNDED_PUBLIC_DNS_CANDIDATE_SHA = '363bbba54f7cac78b9cbb6118c1800dd0c6b7340';
const BOUNDED_PUBLIC_DNS_CANDIDATE_RECIPE_SHA256 = 'e27282825c2074e56067e6ec4cb9a8a3951ad8d4207c0c3f598fc93a1d02128b';
const BOUNDED_PUBLIC_DNS_HELPER_SHA256 = '84274c505f7506c087c694cd0fbde5258e07b39742818824f0344e255f820dd5';

/**
 * This runner-side control recipe is never copied into the immutable release.
 * It retains the candidate recipe and replaces only guard:public-dns-health.
 */
function boundedPublicDnsControlFile(string $environmentName, string $expectedSha256): string
{
    $path = trim((string) getenv($environmentName));

    if ($path === '' || ! str_starts_with($path, '/') || ! is_file($path) || ! is_readable($path)) {
        throw new \RuntimeException("{$environmentName} must identify an absolute readable file.");
    }

    $observedSha256 = hash_file('sha256', $path);
    if (! is_string($observedSha256) || ! hash_equals($expectedSha256, $observedSha256)) {
        throw new \RuntimeException("{$environmentName} failed its immutable SHA-256 boundary.");
    }

    return $path;
}

$candidateSha = trim((string) getenv('DEPLOY_SHA'));
if (! hash_equals(BOUNDED_PUBLIC_DNS_CANDIDATE_SHA, $candidateSha)) {
    throw new \RuntimeException('Bounded public-DNS control is restricted to the reviewed candidate SHA.');
}

$candidateRecipe = boundedPublicDnsControlFile(
    'BOUNDED_PUBLIC_DNS_CANDIDATE_RECIPE_PATH',
    BOUNDED_PUBLIC_DNS_CANDIDATE_RECIPE_SHA256,
);
$publicDnsHelper = boundedPublicDnsControlFile(
    'BOUNDED_PUBLIC_DNS_HELPER_PATH',
    BOUNDED_PUBLIC_DNS_HELPER_SHA256,
);
$publicDnsHelperPayload = base64_encode((string) file_get_contents($publicDnsHelper));

require $candidateRecipe;

task('guard:public-dns-health', function () use ($publicDnsHelperPayload): void {
    if (currentHost()->getAlias() !== 'production') {
        return;
    }

    $host = deploySafeHost((string) get('healthcheck_host'), 'healthcheck_host');
    $environment = [
        'PUBLIC_DNS_PROBE_BASE_URL' => "https://{$host}",
        'PUBLIC_DNS_PROBE_ATTEMPTS' => '3',
        'PUBLIC_DNS_PROBE_RETRY_DELAYS_SECONDS' => '2 5',
        'PUBLIC_DNS_PROBE_CONNECT_TIMEOUT_SECONDS' => '3',
        'PUBLIC_DNS_PROBE_MAX_TIME_SECONDS' => '10',
    ];
    $assignments = [];

    foreach ($environment as $name => $value) {
        $assignments[] = $name.'='.deployShellArg($value);
    }

    $streamCommand = 'printf %s '.deployShellArg($publicDnsHelperPayload)
        .' | base64 -d'
        .' | env '.implode(' ', $assignments)
        .' bash -s';

    run('bash -o pipefail -c '.deployShellArg($streamCommand));
});
