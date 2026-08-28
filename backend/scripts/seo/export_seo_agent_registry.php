<?php

declare(strict_types=1);

use App\Services\SeoAgentGovernance\SeoPolicyRegistry;
use App\Services\SeoAgentGovernance\SeoPromptRegistry;
use App\Services\SeoAgentGovernance\SeoRegistryHasher;
use App\Services\SeoAgentGovernance\SeoRoleCapabilityRegistry;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$outputDirectory = dirname(__DIR__, 2).'/docs/seo/generated';
if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0775, true) && ! is_dir($outputDirectory)) {
    throw new RuntimeException('Unable to create SEO generated output directory.');
}

/** @var SeoRoleCapabilityRegistry $registryAuthority */
$registryAuthority = $app->make(SeoRoleCapabilityRegistry::class);
/** @var SeoPromptRegistry $promptAuthority */
$promptAuthority = $app->make(SeoPromptRegistry::class);
/** @var SeoPolicyRegistry $policyAuthority */
$policyAuthority = $app->make(SeoPolicyRegistry::class);
/** @var SeoRegistryHasher $hasher */
$hasher = $app->make(SeoRegistryHasher::class);

$registry = $registryAuthority->registry();
$promptManifest = [
    'schema_version' => 'seo.prompt_manifest.v1',
    'manifest_version' => '1.0.0',
    'status' => 'frozen',
    'owner_repository' => 'fap-api',
    'prompts' => $promptAuthority->definitions(),
];
$promptManifest['manifest_hash'] = $hasher->hash($promptManifest);

$policyManifest = [
    'schema_version' => 'seo.policy_manifest.v1',
    'manifest_version' => '1.0.0',
    'status' => 'frozen',
    'owner_repository' => 'fap-api',
    'policies' => $policyAuthority->definitions(),
];
$policyManifest['manifest_hash'] = $hasher->hash($policyManifest);

$legacyCli = array_values(array_filter(
    $registry['superseded_assets'],
    static fn (array $asset): bool => str_starts_with($asset['asset_id'], 'fap-api.cli.seo-agent.')
));
$supersessionManifest = [
    'schema_version' => 'seo.authority_supersession.v1',
    'manifest_version' => '1.0.0',
    'status' => 'frozen',
    'owner_repository' => 'fap-api',
    'canonical_registry' => [
        'registry_id' => $registry['registry_id'],
        'registry_version' => $registry['registry_version'],
        'registry_hash' => $registry['registry_hash'],
    ],
    'fixed_boundaries' => $registry['global_guards'],
    'legacy_seo_agent_cli_dispositions' => $legacyCli,
    'legacy_seo_agent_cli_count' => count($legacyCli),
    'fap_web_agent_authority' => false,
    'runtime_created' => false,
    'model_calls_performed' => 0,
    'cms_writes' => 0,
    'seo_data_writes' => 0,
    'search_submissions' => 0,
    'production_data_writes' => 0,
];
$supersessionManifest['manifest_hash'] = $hasher->hash($supersessionManifest);

$outputs = [
    'seo-agent-role-capability-registry.v1.json' => $registry,
    'seo-agent-prompt-manifest.v1.json' => $promptManifest,
    'seo-agent-policy-manifest.v1.json' => $policyManifest,
    'seo-platform-11a-authority-supersession.v1.json' => $supersessionManifest,
];

foreach ($outputs as $file => $payload) {
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    file_put_contents($outputDirectory.'/'.$file, $json."\n");
}

fwrite(STDOUT, json_encode([
    'ok' => true,
    'registry_hash' => $registry['registry_hash'],
    'role_count' => count($registry['roles']),
    'capability_count' => count($registry['capabilities']),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
