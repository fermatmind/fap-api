<?php

declare(strict_types=1);

/**
 * MAINT-006 line-count quality gate.
 *
 * Rules:
 * 1) Any backend/app/Services/*.php file > 800 lines fails by default.
 * 2) Four target files have stricter thresholds.
 * 3) Temporary whitelist files only warn when > 800 lines.
 */

$repoRoot = dirname(__DIR__, 2);
$servicesRoot = $repoRoot . '/backend/app/Services';

if (!is_dir($servicesRoot)) {
    fwrite(STDERR, "[guard_max_lines] services root not found: {$servicesRoot}\n");
    exit(1);
}

$defaultMax = 800;

/** @var array<string,int> */
$strictThresholds = [
    'backend/app/Services/SelfCheck/SelfCheckIo.php' => 350,
    'backend/app/Services/Legacy/Mbti/Report/LegacyMbtiReportPayloadBuilder.php' => 350,
    'backend/app/Services/Commerce/PaymentWebhookProcessor.php' => 450,
    'backend/app/Services/Content/ContentStore.php' => 400,
];

/** @var array<string,true> */
$temporaryWhitelist = array_fill_keys([
    'backend/app/Services/Overrides/ReportOverridesApplier.php',
    'backend/app/Services/Rules/RuleEngine.php',
    'backend/app/Services/Legacy/Mbti/Attempt/LegacyMbtiAttemptLifecycleService.php',
    'backend/app/Services/Content/Publisher/ContentPackPublisher.php',
], true);

/**
 * @return list<string>
 */
function collectPhpFiles(string $root): array
{
    $out = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        /** @var SplFileInfo $fileInfo */
        if (!$fileInfo->isFile()) {
            continue;
        }

        if (strtolower($fileInfo->getExtension()) !== 'php') {
            continue;
        }

        $out[] = $fileInfo->getPathname();
    }

    sort($out);

    return $out;
}

function toRepoRelative(string $repoRoot, string $absPath): string
{
    $repoRoot = rtrim(str_replace('\\', '/', $repoRoot), '/');
    $absPath = str_replace('\\', '/', $absPath);

    if (str_starts_with($absPath, $repoRoot . '/')) {
        return substr($absPath, strlen($repoRoot) + 1);
    }

    return $absPath;
}

function lineCountOf(string $absPath): int
{
    $content = file_get_contents($absPath);
    if ($content === false) {
        return -1;
    }

    if ($content === '') {
        return 0;
    }

    return substr_count($content, "\n") + 1;
}

$phpFiles = collectPhpFiles($servicesRoot);

/** @var list<string> */
$errors = [];
/** @var list<string> */
$warnings = [];
/** @var array<string,int> */
$lineCounts = [];

foreach ($phpFiles as $absPath) {
    $relPath = toRepoRelative($repoRoot, $absPath);
    $lineCount = lineCountOf($absPath);

    if ($lineCount < 0) {
        $errors[] = "cannot read file: {$relPath}";
        continue;
    }

    $lineCounts[$relPath] = $lineCount;

    if (isset($strictThresholds[$relPath])) {
        $strictMax = $strictThresholds[$relPath];
        if ($lineCount > $strictMax) {
            $errors[] = "{$relPath} has {$lineCount} lines (strict max {$strictMax})";
        }
        continue;
    }

    if ($lineCount <= $defaultMax) {
        continue;
    }

    if (isset($temporaryWhitelist[$relPath])) {
        $warnings[] = "{$relPath} has {$lineCount} lines (temporary whitelist, default max {$defaultMax})";
        continue;
    }

    $errors[] = "{$relPath} has {$lineCount} lines (default max {$defaultMax})";
}

foreach ($strictThresholds as $relPath => $_) {
    if (!array_key_exists($relPath, $lineCounts)) {
        $errors[] = "strict target missing: {$relPath}";
    }
}

foreach ($warnings as $warning) {
    fwrite(STDERR, "[guard_max_lines][WARN] {$warning}\n");
}

if ($errors !== []) {
    fwrite(STDERR, "[guard_max_lines] FAILED\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "[guard_max_lines] OK\n");
exit(0);

