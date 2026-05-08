<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthExporter;
use App\Domain\Career\Publish\CareerCanonicalRuntimeTruthValidator;
use Illuminate\Console\Command;

final class CareerValidateCanonicalRuntimeTruth extends Command
{
    protected $signature = 'career:validate-canonical-runtime-truth
        {--ledger= : Optional Career full release ledger JSON artifact}
        {--projection= : Optional Career runtime publish projection JSON artifact}
        {--truth= : Optional Career canonical runtime truth JSON artifact}
        {--json : Emit JSON output}';

    protected $description = 'Validate Career canonical runtime truth equality across projection, dataset, route, sitemap, llms, and llms-full surfaces.';

    public function __construct(
        private readonly CareerCanonicalRuntimeTruthExporter $exporter,
        private readonly CareerCanonicalRuntimeTruthValidator $validator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $truth = $this->truthPathOption() !== null
                ? $this->readTruthPath((string) $this->truthPathOption())
                : $this->exporter->build($this->ledgerPathOption(), $this->projectionPathOption());
            $result = $this->validator->validate($truth);

            $payload = [
                'status' => $result['status'],
                'truth_kind' => $truth['truth_kind'] ?? null,
                'truth_version' => $truth['truth_version'] ?? null,
                'counts' => $result['counts'],
                'failures' => $result['failures'],
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            } else {
                $this->line('status='.$payload['status']);
                $this->line('items='.(string) data_get($payload, 'counts.items', 0));
                $this->line('failures='.(string) data_get($payload, 'counts.failures', 0));
            }

            return $result['status'] === 'pass' ? self::SUCCESS : self::FAILURE;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function ledgerPathOption(): ?string
    {
        $value = $this->option('ledger');
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function projectionPathOption(): ?string
    {
        $value = $this->option('projection');
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    private function truthPathOption(): ?string
    {
        $value = $this->option('truth');
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function readTruthPath(string $truthPath): array
    {
        if (! is_file($truthPath)) {
            throw new \RuntimeException('Career canonical runtime truth file not found: '.$truthPath);
        }

        $payload = json_decode((string) file_get_contents($truthPath), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('Career canonical runtime truth file is not valid JSON: '.$truthPath);
        }

        return is_array($payload['truth'] ?? null) ? $payload['truth'] : $payload;
    }
}
