<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (array_diff(array_slice($argv, 1), ['--write']) !== [] || ! in_array($app->environment(), ['local', 'testing'], true)) {
    fwrite(STDERR, "Use local/testing and optionally --write. This command never publishes.\n");
    exit(1);
}
$result = (new App\Domain\Career\Display\CareerAuthoringMigration)->run(base_path(), in_array('--write', $argv, true));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
