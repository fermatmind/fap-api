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

$lines = preg_split('/\r\n|\n|\r/', $source);

if ($lines === false) {
    exit(4);
}

if ($lines !== [] && $lines[array_key_last($lines)] === '') {
    array_pop($lines);
}

$sectionCount = 0;
$targetCount = 0;
$insideTarget = false;
$strippedSource = '';

foreach ($lines as $line) {
    if ($line === '[program:fap-queue-ops]') {
        $sectionCount++;
        $targetCount++;
        $insideTarget = true;

        continue;
    }

    if (preg_match('/^\[[^\]]+\][ \t]*$/', $line) === 1) {
        $sectionCount++;
        $insideTarget = false;
    }

    if (! $insideTarget) {
        $strippedSource .= $line."\n";
    }
}

if ($sectionCount !== 3 || $targetCount !== 1) {
    exit(5);
}
$renderedOpsSection = rtrim($candidate, "\r\n")."\n";

if (
    substr_count($strippedSource, '[program:fap-queue-ops]') !== 0
    || substr_count($renderedOpsSection, '[program:fap-queue-ops]') !== 1
) {
    exit(6);
}

if (isset($argv[3]) && $argv[3] !== '') {
    $written = file_put_contents($argv[3], $strippedSource, LOCK_EX);

    if ($written !== strlen($strippedSource)) {
        exit(7);
    }
}

echo hash('sha256', $strippedSource), "\t";
echo hash('sha256', $renderedOpsSection), "\n";
