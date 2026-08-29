<?php

declare(strict_types=1);

use App\Services\SeoCouncil\Entrypoints\LocalSkillMissionAdapter;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $path = $argv[1] ?? '-';
    $bytes = $path === '-' ? stream_get_contents(STDIN) : file_get_contents($path);
    $input = json_decode((string) $bytes, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($input)) {
        throw new RuntimeException('MISSION_REQUEST_INVALID');
    }

    echo json_encode(
        $app->make(LocalSkillMissionAdapter::class)->submit($input),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ).PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode([
        'status' => 'REQUEST_INVALID',
        'safe_error_code' => preg_match('/^[A-Z0-9_]+$/D', $exception->getMessage()) === 1
            ? $exception->getMessage()
            : 'MISSION_SUBMISSION_FAILED',
        'execution_allowed' => false,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
