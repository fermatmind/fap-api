<?php

declare(strict_types=1);

use App\Services\PublicSurface\AnswerSurfaceContractService;
use App\Services\PublicSurface\LandingSurfaceContractService;

require __DIR__.'/../../vendor/autoload.php';

$requestedPaths = array_slice($argv, 1);
if ($requestedPaths === []) {
    fwrite(STDERR, json_encode(['status' => 'FAIL', 'safe_error_code' => 'PAGE_PATH_REQUIRED']).PHP_EOL);
    exit(1);
}

$root = realpath(__DIR__.'/../../content_assets/personality_public/current');
$manifestPath = $root === false ? false : realpath($root.'/manifest.json');
if ($root === false || $manifestPath === false) {
    fwrite(STDERR, json_encode(['status' => 'FAIL', 'safe_error_code' => 'CURRENT_ROOT_MISSING']).PHP_EOL);
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
$allowedPaths = [];
foreach ($manifest['files'] as $entry) {
    $candidate = realpath($root.'/'.$entry['path']);
    if ($candidate !== false) {
        $allowedPaths[$candidate] = true;
    }
}

$landingService = new LandingSurfaceContractService;
$answerService = new AnswerSurfaceContractService;

foreach ($requestedPaths as $path) {
    $resolvedPath = realpath($path);
    if ($resolvedPath === false || ! isset($allowedPaths[$resolvedPath]) || $resolvedPath === $manifestPath) {
        fwrite(STDERR, json_encode(['status' => 'FAIL', 'safe_error_code' => 'PAGE_PATH_FORBIDDEN']).PHP_EOL);
        exit(1);
    }

    $path = $resolvedPath;
    $page = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if (($page['identity']['framework'] ?? null) !== 'mbti' || ! in_array($page['identity']['page_kind'] ?? null, ['profile', 'variant'], true)) {
        fwrite(STDERR, json_encode(['status' => 'FAIL', 'safe_error_code' => 'MBTI_PAGE_REQUIRED']).PHP_EOL);
        exit(1);
    }
    if (! isset($page['payload']['landing_surface_v1'], $page['payload']['answer_surface_v1'])) {
        fwrite(STDERR, json_encode(['status' => 'FAIL', 'safe_error_code' => 'SURFACE_CONTRACT_REQUIRED']).PHP_EOL);
        exit(1);
    }
    $landing = $page['payload']['landing_surface_v1'];
    $answer = $page['payload']['answer_surface_v1'];
    $runtimeTypeCode = $page['identity']['page_kind'] === 'variant' ? (string) $landing['primary_content_ref'] : '';
    $seed = [
        'slug' => (string) $page['payload']['profile']['slug'],
        'runtime_type_code' => $runtimeTypeCode,
        'locale' => (string) $page['locale'],
        'scale_code' => strtoupper((string) $page['payload']['profile']['scale_code']),
    ];
    $landingContext = array_diff_key($landing, array_flip(['version', 'landing_contract_version', 'landing_fingerprint']));
    $landingContext['fingerprint_seed'] = $seed;
    $expectedLanding = $landingService->build($landingContext);
    $oldLandingFingerprint = (string) $answer['landing_surface_ref'];
    $answer['landing_surface_ref'] = $expectedLanding['landing_fingerprint'];
    $answer['evidence_refs'] = array_map(
        static fn (string $value): string => $value === $oldLandingFingerprint ? $expectedLanding['landing_fingerprint'] : $value,
        $answer['evidence_refs'],
    );
    $answerSeed = [
        'slug' => (string) $page['payload']['profile']['slug'],
        'locale' => (string) $page['locale'],
        'runtime_type_code' => $runtimeTypeCode,
        'scale_code' => strtoupper((string) $page['payload']['profile']['scale_code']),
    ];
    $answerContext = array_diff_key($answer, array_flip(['version', 'answer_contract_version', 'answer_fingerprint']));
    $answerContext['fingerprint_seed'] = $answerSeed;
    $expectedAnswer = $answerService->build($answerContext);
    $page['payload']['landing_surface_v1'] = $expectedLanding;
    $page['payload']['answer_surface_v1'] = $expectedAnswer;
    $sort = static function (mixed $value) use (&$sort): mixed {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $sort($item);
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    };
    file_put_contents($path, json_encode($sort($page), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
}

fwrite(STDOUT, json_encode(['status' => 'PASS', 'pages' => count($requestedPaths)]).PHP_EOL);
