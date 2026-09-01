<?php

declare(strict_types=1);

use App\Services\SeoAgentEvidence\Competitive\CompetitiveEvidenceContractRegistry;
use App\Services\SeoAgentEvidence\Contracts\SeoEvidenceContractRegistry;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$manifests = [
    dirname(__DIR__, 2).'/docs/seo/generated/seo-agent-evidence-contract-manifest.v2.json' => $app->make(SeoEvidenceContractRegistry::class)->manifest(),
    dirname(__DIR__, 2).'/docs/seo/generated/seo-agent-evidence-contract-manifest.v5.json' => $app->make(CompetitiveEvidenceContractRegistry::class)->manifest(),
];

foreach ($manifests as $target => $manifest) {
    $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    if (file_put_contents($target, $json, LOCK_EX) !== strlen($json)) {
        fwrite(STDERR, "Unable to write evidence contract manifest.\n");
        exit(1);
    }
}

// Preserve the v2 exporter's stdout contract for existing CI consumers. The
// append-only Competitive artifacts are generated and drift-checked without widening it.
fwrite(STDOUT, $manifests[dirname(__DIR__, 2).'/docs/seo/generated/seo-agent-evidence-contract-manifest.v2.json']['manifest_hash']."\n");
