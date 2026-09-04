<?php

declare(strict_types=1);

use App\Services\SeoCouncil\Competitive\CompetitiveReleasePrepareEnvelope;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$candidateSha = (string) ($argv[1] ?? '');
$environment = (string) ($argv[2] ?? '');

try {
    $receipt = $app->make(CompetitiveReleasePrepareEnvelope::class)->extract(
        stream_get_contents(STDIN),
        $candidateSha,
        $environment,
    );
    fwrite(STDOUT, json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
} catch (\Throwable) {
    fwrite(STDERR, "competitive_prepare_status=HOLD\n");
    fwrite(STDERR, "competitive_prepare_stage=preactivation_receipt_validation\n");
    fwrite(STDERR, "competitive_prepare_reason=COMPETITIVE_PREACTIVATION_ENVELOPE_INVALID\n");
    exit(1);
}
