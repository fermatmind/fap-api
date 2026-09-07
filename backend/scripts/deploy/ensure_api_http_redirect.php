<?php

declare(strict_types=1);

use FermatMind\Deploy\ApiHttpRedirectNginxTransformer;

require __DIR__.'/ApiHttpRedirectNginxTransformer.php';

if ($argc !== 4) {
    fwrite(STDERR, "Usage: ensure_api_http_redirect.php <nginx-site> <api-host> <certbot-webroot>\n");
    exit(64);
}

$source = file_get_contents($argv[1]);

if (! is_string($source) || $source === '') {
    fwrite(STDERR, "Nginx site is empty or unreadable.\n");
    exit(1);
}

try {
    echo ApiHttpRedirectNginxTransformer::transform($source, $argv[2], $argv[3]);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
