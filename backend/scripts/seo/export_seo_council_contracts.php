<?php

declare(strict_types=1);

use App\Services\SeoCouncil\Contracts\CouncilContractRegistry;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$path = dirname(__DIR__, 2).'/docs/seo/generated/seo-council-contract-manifest.v2.json';
$bytes = json_encode(
    $app->make(CouncilContractRegistry::class)->manifest(),
    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
).PHP_EOL;

if (in_array('--check', $argv, true)) {
    exit(is_file($path) && hash_equals((string) file_get_contents($path), $bytes) ? 0 : 1);
}

file_put_contents($path, $bytes, LOCK_EX);
