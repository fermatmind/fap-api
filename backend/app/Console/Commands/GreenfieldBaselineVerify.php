<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\GreenfieldBaseline\GreenfieldBaselineJson;
use App\Domain\GreenfieldBaseline\GreenfieldBaselinePackageVerifier;
use Illuminate\Console\Command;
use Throwable;

final class GreenfieldBaselineVerify extends Command
{
    protected $signature = 'greenfield:baseline:verify
        {--package= : Absolute package directory}
        {--expected-package-sha256= : Optional exact package SHA256 binding}
        {--json : Emit JSON output}';

    protected $description = 'Verify a Greenfield current-published package without database writes.';

    public function __construct(
        private readonly GreenfieldBaselinePackageVerifier $verifier,
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
            $expected = trim((string) $this->option('expected-package-sha256'));
            $payload = $this->verifier->verify($package, $expected !== '' ? $expected : null);
            $this->line(GreenfieldBaselineJson::encode($payload, (bool) $this->option('json')));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
