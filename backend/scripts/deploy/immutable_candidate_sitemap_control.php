<?php

declare(strict_types=1);

use function Deployer\before;
use function Deployer\get;
use function Deployer\run;
use function Deployer\task;

const IMMUTABLE_CANDIDATE_SHA = '49038deb50cda789e4365ea42068832ed28d6023';
const IMMUTABLE_CANDIDATE_RECIPE_SHA256 = 'e814b6ff4996669097db0f32fd3caebc1fcd05dd9015e2260016e3f4ece3c068';
const IMMUTABLE_SITEMAP_WARM_HELPER_SHA256 = '04f9d6b6b66b0be10a4996064cdf9150e1b0f1ec300edf93f87e8c0368ea0713';

/**
 * This file is a runner-side deployment control recipe. It is never copied
 * into the immutable release and it never changes the release REVISION.
 */
function immutableCandidateControlFile(string $environmentName, string $expectedSha256): string
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
if (! hash_equals(IMMUTABLE_CANDIDATE_SHA, $candidateSha)) {
    throw new \RuntimeException('Immutable sitemap control is restricted to the reviewed candidate SHA.');
}

$candidateRecipe = immutableCandidateControlFile(
    'IMMUTABLE_CANDIDATE_RECIPE_PATH',
    IMMUTABLE_CANDIDATE_RECIPE_SHA256,
);
$warmHelper = immutableCandidateControlFile(
    'IMMUTABLE_SITEMAP_WARM_HELPER_PATH',
    IMMUTABLE_SITEMAP_WARM_HELPER_SHA256,
);
$warmHelperPayload = base64_encode((string) file_get_contents($warmHelper));

require $candidateRecipe;

task('seo:warm-sitemap-source-cache', function () use ($warmHelperPayload): void {
    $artisan = deployPlaceholderPathArg('{{release_path}}', 'backend/artisan');
    $payload = deployShellArg($warmHelperPayload);

    run(
        'printf %s '.$payload
        .' | base64 -d'
        .' | sudo -n -u www-data -- env'
        .' SITEMAP_SOURCE_WARM_PHP_BIN={{bin/php}}'
        .' SITEMAP_SOURCE_WARM_ARTISAN='.$artisan
        .' SITEMAP_SOURCE_WARM_TIMEOUT_SECONDS=180'
        .' SITEMAP_SOURCE_WARM_KILL_AFTER_SECONDS=30'
        .' SITEMAP_SOURCE_WARM_STRICT=false'
        .' bash -s'
    );
});

task('healthcheck:sitemap-source', function (): void {
    $host = deploySafeHost((string) get('healthcheck_host'), 'healthcheck_host');
    $resolveArg = deployCurlResolveArg($host, (bool) get('healthcheck_use_resolve', true));
    $url = deployHttpsUrlArg($host, '/api/v0.5/seo/sitemap-source');
    $jq = deployShellArg(
        '.ok==true and .count >= 1 and (.source=="backend_sitemap_generator" or .source=="backend_sitemap_generator_fallback")'
    );

    run("curl -fsS {$resolveArg}{$url} | jq -e {$jq}");
});

before('healthcheck:public-dns', 'healthcheck:sitemap-source');
