<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Publish\CareerRuntimePublishProjectionExporter;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionValidator;
use Illuminate\Console\Command;

final class CareerValidateRuntimePublishProjection extends Command
{
    protected $signature = 'career:validate-runtime-publish-projection
        {--ledger= : Optional Career full release ledger JSON artifact}
        {--projection= : Optional Career runtime publish projection JSON artifact}
        {--json : Emit JSON output}';

    protected $description = 'Validate Career runtime publish projection invariants against the full release ledger authority.';

    public function __construct(
        private readonly CareerRuntimePublishProjectionExporter $exporter,
        private readonly CareerRuntimePublishProjectionValidator $validator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $projection = $this->projectionPathOption() !== null
                ? $this->readProjectionPath((string) $this->projectionPathOption())
                : $this->exporter->build($this->ledgerPathOption());
            $result = $this->validator->validate($projection);

            $payload = [
                'status' => $result['status'],
                'projection_kind' => $projection['projection_kind'] ?? null,
                'projection_version' => $projection['projection_version'] ?? null,
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

    /**
     * @return array<string, mixed>
     */
    private function readProjectionPath(string $projectionPath): array
    {
        if (! is_file($projectionPath)) {
            throw new \RuntimeException('Career runtime publish projection file not found: '.$projectionPath);
        }

        $payload = json_decode((string) file_get_contents($projectionPath), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('Career runtime publish projection file is not valid JSON: '.$projectionPath);
        }

        return is_array($payload['projection'] ?? null) ? $payload['projection'] : $payload;
    }
}
