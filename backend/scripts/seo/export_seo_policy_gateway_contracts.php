<?php

declare(strict_types=1);

use App\Services\SeoAgentPolicyGateway\PolicyGatewayContractRegistry;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$manifest = $app->make(PolicyGatewayContractRegistry::class)->manifest();
$target = dirname(__DIR__, 2).'/docs/seo/generated/seo-policy-gateway-contract-manifest.v1.json';
$json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
if (file_put_contents($target, $json, LOCK_EX) !== strlen($json)) {
    fwrite(STDERR, "Unable to write Policy Gateway contract manifest.\n");
    exit(1);
}
fwrite(STDOUT, $manifest['manifest_hash']."\n");
