<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
$scan = require __DIR__.'/php_source_calls.php';
$offenders = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    foreach ($scan(file_get_contents($file->getPathname()), ['env']) as $match) {
        $offenders[] = $file->getPathname().':'.$match['line'].' => '.$match['name'].'()';
    }
}
sort($offenders);
if ($offenders !== []) {
    echo implode(PHP_EOL, $offenders).PHP_EOL;
    exit(1);
}
