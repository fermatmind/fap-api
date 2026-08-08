<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\GreenfieldBaseline\GreenfieldBaselineImporter;
use App\Domain\GreenfieldBaseline\GreenfieldBaselineJson;
use Illuminate\Console\Command;
use Throwable;

final class GreenfieldBaselineImport extends Command
{
    protected $signature = 'greenfield:baseline:import
        {--package= : Absolute verified package directory}
        {--apply : Import into the bound empty target database}
        {--confirm= : Exact IMPORT_GREENFIELD_BASELINE:<package_sha256> confirmation}
        {--expected-database-sha256= : Exact SHA256 of the target database name}
        {--json : Emit JSON output}';

    protected $description = 'Dry-run or explicitly apply a Greenfield baseline to a migrated empty database.';

    public function __construct(
        private readonly GreenfieldBaselineImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $package = trim((string) $this->option('package'));
            if ($package === '' || ! str_starts_with($package, '/')) {
                throw new \RuntimeException('--package must be an absolute path.');
            }
            $payload = $this->importer->run(
                $package,
                (bool) $this->option('apply'),
                $this->nullableOption('confirm'),
                $this->nullableOption('expected-database-sha256'),
            );
            $this->line(GreenfieldBaselineJson::encode($payload, (bool) $this->option('json')));

            return ($payload['status'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }

    private function nullableOption(string $name): ?string
    {
        $value = trim((string) $this->option($name));

        return $value !== '' ? $value : null;
    }
}
