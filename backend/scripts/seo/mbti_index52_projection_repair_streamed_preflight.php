<?php

declare(strict_types=1);

$deployPath = (string) getenv('DEPLOY_PATH');
$expectedControlPlaneSha = (string) getenv('EXPECTED_CONTROL_PLANE_SHA');
$expectedActiveRevision = (string) getenv('EXPECTED_ACTIVE_REVISION');
$current = realpath($deployPath.'/current');
if ($current === false
    || ! is_dir($current.'/backend')
    || trim((string) file_get_contents($current.'/REVISION')) !== $expectedActiveRevision
    || file_exists($deployPath.'/.dep/deploy.lock')
) {
    throw new RuntimeException('Active production release precondition mismatch.');
}

chdir($current.'/backend');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

eval(base64_decode('__PACKAGE_CLASS_B64__', true) ?: throw new RuntimeException('Package class payload invalid.'));
eval(base64_decode('__SERVICE_CLASS_B64__', true) ?: throw new RuntimeException('Service class payload invalid.'));
$package = json_decode(base64_decode('__PACKAGE_JSON_B64__', true) ?: '', true, flags: JSON_THROW_ON_ERROR);
$authorization = json_decode(base64_decode('__AUTHORIZATION_JSON_B64__', true) ?: '', true, flags: JSON_THROW_ON_ERROR);
$atSourcePrestate = json_decode(base64_decode('__AT_SOURCE_PRESTATE_JSON_B64__', true) ?: '', true, flags: JSON_THROW_ON_ERROR);

$contract = new App\Services\Cms\StreamedMbtiIndex52\MbtiIndex52ProjectionRepairPackage($atSourcePrestate);
$service = new App\Services\Cms\StreamedMbtiIndex52\MbtiIndex52ProjectionRepairService($contract);
$plan = $service->plan(
    $package,
    $authorization,
    $expectedControlPlaneSha,
    $expectedActiveRevision,
);

echo json_encode([
    'contract_version' => 'mbti.index52.comparison_projection_repair.production_preflight.v1',
    'status' => ($plan['ok'] ?? false) === true ? 'PASS_PREFLIGHT' : 'HOLD',
    'control_plane_sha' => $expectedControlPlaneSha,
    'active_revision' => $expectedActiveRevision,
    'record_count' => $plan['record_count'] ?? null,
    'at_comparison_count' => $plan['at_comparison_count'] ?? null,
    'cross_type_comparison_count' => $plan['cross_type_comparison_count'] ?? null,
    'exact_slugs' => $plan['exact_slugs'] ?? [],
    'package_sha256' => $plan['package_sha256'] ?? null,
    'authorization_sha256' => $plan['authorization_sha256'] ?? null,
    'current_state_sha256' => $plan['current_state_sha256'] ?? null,
    'desired_state_sha256' => $plan['desired_state_sha256'] ?? null,
    'rollback_manifest_sha256' => $plan['rollback_manifest_sha256'] ?? null,
    'readback_contract_sha256' => $plan['readback_contract_sha256'] ?? null,
    'required_production_authorization' => $plan['required_production_authorization'] ?? null,
    'production_authorization_sha256' => $plan['production_authorization_sha256'] ?? null,
    'writes_committed' => false,
    'body_or_faq_mutated' => false,
    'publication_or_indexability_mutated' => false,
    'sitemap_or_llms_mutated' => false,
    'search_submission_executed' => false,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
