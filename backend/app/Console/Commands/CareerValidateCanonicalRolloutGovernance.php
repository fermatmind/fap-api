<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Expansion\CanonicalExpansionManifestExporter;
use App\Domain\Career\Expansion\CanonicalRolloutGovernanceValidator;
use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use Illuminate\Console\Command;

final class CareerValidateCanonicalRolloutGovernance extends Command
{
    protected $signature = 'career:validate-canonical-rollout-governance
        {--manifest= : Optional canonical expansion manifest JSON artifact}
        {--truth= : Optional canonical runtime truth JSON artifact}
        {--projection= : Optional runtime publish projection JSON artifact}
        {--ledger= : Optional Career full release ledger JSON artifact}
        {--batch-size=50 : Batch size when building manifest from truth}
        {--json : Emit JSON output}';

    protected $description = 'Validate canonical rollout governance across manifest, projection, and canonical runtime truth.';

    public function __construct(
        private readonly CanonicalExpansionManifestExporter $manifestExporter,
        private readonly CareerCanonicalRuntimeTruthExporter $truthExporter,
        private readonly CareerRuntimePublishProjectionExporter $projectionExporter,
        private readonly CanonicalRolloutGovernanceValidator $validator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $projection = $this->pathOption('projection') !== null
                ? $this->readJsonPath((string) $this->pathOption('projection'), 'projection')
                : $this->projectionExporter->build($this->pathOption('ledger'));
            $truth = $this->pathOption('truth') !== null
                ? $this->readJsonPath((string) $this->pathOption('truth'), 'truth')
                : $this->truthExporter->buildFromProjectionArray($projection);
            $manifest = $this->pathOption('manifest') !== null
                ? $this->readJsonPath((string) $this->pathOption('manifest'), 'manifest')
                : $this->manifestExporter->build(
                    truthPath: $this->pathOption('truth'),
                    projectionPath: $this->pathOption('projection'),
                    ledgerPath: $this->pathOption('ledger'),
                    batchSize: (int) $this->option('batch-size'),
                    batchId: null,
                );

            $result = $this->validator->validate($manifest, $truth, $projection);
            $payload = [
                'status' => $result['status'],
                'validator_version' => 'career.canonical_rollout_governance.v1',
                'read_only' => true,
                'writes_database' => false,
                'counts' => $result['counts'],
                'failures' => $result['failures'],
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->line('status='.$payload['status']);
                $this->line('failures='.(string) data_get($payload, 'counts.failures', 0));
            }

            return $result['status'] === 'pass' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function pathOption(string $name): ?string
    {
        $value = $this->option($name);
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonPath(string $path, string $innerKey): array
    {
        if (! is_file($path)) {
            throw new \RuntimeException('canonical rollout governance input file not found: '.$path);
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('canonical rollout governance input file is not valid JSON: '.$path);
        }

        return is_array($payload[$innerKey] ?? null) ? $payload[$innerKey] : $payload;
    }
}
