<?php

declare(strict_types=1);

$output = stream_get_contents(STDIN);

if (($argv[1] ?? null) === '--diagnose') {
    $payload = null;
    foreach (array_reverse(preg_split('/\R/', trim($output)) ?: []) as $line) {
        $decoded = json_decode(trim($line), true);
        if (is_array($decoded)) {
            $payload = $decoded;
            break;
        }
    }
    $stage = is_array($payload) ? (string) ($payload['failed_stage'] ?? '') : '';
    $reason = is_array($payload) ? (string) ($payload['reason_code'] ?? '') : '';
    if (preg_match('/^[a-z0-9_]{3,48}$/D', $stage) !== 1) {
        $stage = 'prepare_command';
    }
    if (preg_match('/^[A-Z0-9_]{3,64}$/D', $reason) !== 1) {
        $reason = 'COMPETITIVE_PREPARE_COMMAND_FAILED';
    }
    fwrite(STDERR, "competitive_prepare_status=HOLD\n");
    fwrite(STDERR, 'competitive_prepare_stage='.$stage."\n");
    fwrite(STDERR, 'competitive_prepare_reason='.$reason."\n");
    exit(1);
}

use App\Services\SeoCouncil\Competitive\CompetitiveReleasePrepareEnvelope;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$candidateSha = (string) ($argv[1] ?? '');
$environment = (string) ($argv[2] ?? '');

try {
    $receipt = $app->make(CompetitiveReleasePrepareEnvelope::class)->extract(
        $output,
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
