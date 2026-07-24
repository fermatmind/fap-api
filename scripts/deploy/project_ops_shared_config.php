<?php

declare(strict_types=1);

if ($argc < 3 || $argc > 4) {
    exit(2);
}

$source = file_get_contents($argv[1]);
$candidate = base64_decode($argv[2], true);

if ($source === false || $candidate === false || $candidate === '') {
    exit(3);
}

$matched = preg_match_all(
    '/^\[([^\]\r\n]+)\][ \t]*\r?$/m',
    $source,
    $sections,
    PREG_OFFSET_CAPTURE,
);

if ($matched === false || $matched < 2) {
    exit(4);
}

$targetIndexes = [];

for ($index = 0; $index < $matched; $index++) {
    if ($sections[1][$index][0] === 'program:fap-queue-ops') {
        $targetIndexes[] = $index;
    }
}

if (count($targetIndexes) !== 1) {
    exit(5);
}

$targetIndex = $targetIndexes[0];
$start = $sections[0][$targetIndex][1];
$end = $targetIndex + 1 < $matched
    ? $sections[0][$targetIndex + 1][1]
    : strlen($source);
$currentOpsSection = substr($source, $start, $end - $start);
$foreignProjection = substr($source, 0, $start).substr($source, $end);
$renderedOpsSection = rtrim($candidate, "\r\n")."\n";
$patchedConfig = substr($source, 0, $start)
    .$renderedOpsSection
    .substr($source, $end);

if (substr_count($patchedConfig, '[program:fap-queue-ops]') !== 1) {
    exit(6);
}

if (isset($argv[3]) && $argv[3] !== '') {
    $written = file_put_contents($argv[3], $patchedConfig, LOCK_EX);

    if ($written !== strlen($patchedConfig)) {
        exit(7);
    }
}

echo hash('sha256', $currentOpsSection), "\t";
echo hash('sha256', $foreignProjection), "\t";
echo hash('sha256', $renderedOpsSection), "\t";
echo hash('sha256', $patchedConfig), "\n";
