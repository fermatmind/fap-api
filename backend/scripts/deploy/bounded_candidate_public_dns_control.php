<?php

declare(strict_types=1);

use function Deployer\currentHost;
use function Deployer\deployHttpsUrlArg;
use function Deployer\deploySafeHost;
use function Deployer\deployShellArg;
use function Deployer\get;
use function Deployer\run;
use function Deployer\task;

const BOUNDED_PUBLIC_DNS_CANDIDATE_SHA = '363bbba54f7cac78b9cbb6118c1800dd0c6b7340';
const BOUNDED_PUBLIC_DNS_CANDIDATE_RECIPE_SHA256 = 'e27282825c2074e56067e6ec4cb9a8a3951ad8d4207c0c3f598fc93a1d02128b';

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

function boundedPublicDnsBusinessEvidenceCommand(string $host): string
{
    $healthUrl = deployHttpsUrlArg($host, '/api/healthz');
    $flagsUrl = deployHttpsUrlArg($host, '/api/v0.3/flags');
    $personalityUrl = deployHttpsUrlArg(
        $host,
        '/api/v0.5/personality-content-assets/big_five/hub/big-five?locale=zh-CN'
    );
    $httpCode = deployShellArg("\n%{http_code}");
    $personalityContract = deployShellArg(
        '.ok==true and (.personality_public_content_asset_v1.source_hash | strings | test("^[0-9a-f]{64}$"))'
    );
    $probeFunction = 'production_probe() { '
        .'url="$1"; PROBE_STATUS=; PROBE_BODY=; '
        .'if ! raw="$(curl -sS --connect-timeout 3 --max-time 10 '
        ."-w {$httpCode} \"\$url\" 2>/dev/null)\"; then return 75; fi; "
        .'PROBE_STATUS="${raw##*$\'\n\'}"; PROBE_BODY="${raw%$\'\n\'*}"; '
        .'case "$PROBE_STATUS" in 429|502|503|504) return 75 ;; esac; return 0; }';
    $verifyFunction = 'verify_public_evidence() { '
        ."PROBE_STAGE=public_health; production_probe {$healthUrl} || return \$?; "
        .'[ "$PROBE_STATUS" = "404" ] || return 1; '
        ."PROBE_STAGE=public_flags; production_probe {$flagsUrl} || return \$?; "
        .'[ "$PROBE_STATUS" = "200" ] || return 1; '
        ."PROBE_STAGE=public_bigfive; production_probe {$personalityUrl} || return \$?; "
        .'[ "$PROBE_STATUS" = "200" ] '
        .'|| return 1; PROBE_STAGE=public_bigfive_contract; '
        ."printf '%s' \"\$PROBE_BODY\" | jq -e {$personalityContract} >/dev/null; }";

    return 'PRODUCTION_PUBLIC_PROBE_ATTEMPTS=3; PROBE_STATUS=; PROBE_BODY=; PROBE_STAGE=not_started; '
        .$probeFunction.'; '.$verifyFunction.'; '
        .'attempt=1; while [ "$attempt" -le "$PRODUCTION_PUBLIC_PROBE_ATTEMPTS" ]; do '
        .'set +e; verify_public_evidence; probe_rc=$?; set -e; '
        .'if [ "$probe_rc" -eq 0 ]; then exit 0; fi; '
        .'if [ "$probe_rc" -ne 75 ]; then '
        .'echo "Public DNS business evidence failed terminally on attempt ${attempt}: stage=${PROBE_STAGE} status=${PROBE_STATUS:-none}" >&2; exit 1; fi; '
        .'if [ "$attempt" -eq "$PRODUCTION_PUBLIC_PROBE_ATTEMPTS" ]; then '
        .'echo "Public DNS business evidence failed after 3 attempts: stage=${PROBE_STAGE} status=${PROBE_STATUS:-none}" >&2; exit 1; fi; '
        .'case "$attempt" in 1) sleep 2 ;; 2) sleep 5 ;; esac; '
        .'attempt=$((attempt + 1)); done';
}

$candidateSha = trim((string) getenv('DEPLOY_SHA'));
if (! hash_equals(BOUNDED_PUBLIC_DNS_CANDIDATE_SHA, $candidateSha)) {
    throw new \RuntimeException('Bounded public-DNS control is restricted to the reviewed candidate SHA.');
}

$candidateRecipe = boundedPublicDnsControlFile(
    'BOUNDED_PUBLIC_DNS_CANDIDATE_RECIPE_PATH',
    BOUNDED_PUBLIC_DNS_CANDIDATE_RECIPE_SHA256,
);

require $candidateRecipe;

task('guard:public-dns-health', function (): void {
    if (currentHost()->getAlias() !== 'production') {
        return;
    }

    $host = deploySafeHost((string) get('healthcheck_host'), 'healthcheck_host');
    run('bash -lc '.deployShellArg(boundedPublicDnsBusinessEvidenceCommand($host)));
});
