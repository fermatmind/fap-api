<?php

declare(strict_types=1);

use App\Services\SeoCouncil\Contracts\CouncilContractRegistry;
use App\Services\SeoCouncil\Measurement\MeasurementContractRegistry;
use App\Services\SeoCouncil\Platform11\Platform11ContractRegistry;
use App\Services\SeoCouncil\Platform12\Platform12ContractRegistry;
use App\Services\SeoCouncil\TechnicalDiagnosis\TechnicalDiagnosisContractRegistry;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$artifacts = [
    dirname(__DIR__, 2).'/docs/seo/generated/seo-council-contract-manifest.v3.json' => $app->make(CouncilContractRegistry::class)->manifest(),
    dirname(__DIR__, 2).'/docs/seo/generated/seo-technical-diagnosis-contract-manifest.v2.json' => $app->make(TechnicalDiagnosisContractRegistry::class)->manifest(),
    dirname(__DIR__, 2).'/docs/seo/generated/seo-measurement-contract-manifest.v3.json' => $app->make(MeasurementContractRegistry::class)->manifest(),
];
foreach ($app->make(Platform11ContractRegistry::class)->artifacts() as $relative => $artifact) {
    $artifacts[dirname(__DIR__, 2).'/'.$relative] = $artifact;
}
foreach ($app->make(Platform12ContractRegistry::class)->artifacts() as $relative => $artifact) {
    $artifacts[dirname(__DIR__, 2).'/'.$relative] = $artifact;
}

if (in_array('--check', $argv, true)) {
    foreach ($artifacts as $path => $artifact) {
        $bytes = json_encode($artifact, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
        if (! is_file($path) || ! hash_equals((string) file_get_contents($path), $bytes)) {
            exit(1);
        }
    }

    exit(0);
}

foreach ($artifacts as $path => $artifact) {
    $bytes = json_encode($artifact, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }
    file_put_contents($path, $bytes, LOCK_EX);
}
