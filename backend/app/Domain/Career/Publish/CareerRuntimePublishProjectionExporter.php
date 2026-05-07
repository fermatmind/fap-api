<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

final class CareerRuntimePublishProjectionExporter
{
    public const PROJECTION_FILENAME = 'career-runtime-publish-projection.json';

    public function __construct(
        private readonly CareerFullReleaseLedgerProjectionService $ledgerProjectionService,
        private readonly CareerRuntimePublishProjectionService $projectionService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?string $ledgerPath = null): array
    {
        $ledger = $ledgerPath !== null
            ? $this->readLedgerPath($ledgerPath)
            : (array) ($this->ledgerProjectionService->build()[CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME] ?? []);

        if ($ledger === []) {
            throw new \RuntimeException('Career runtime publish projection cannot build from an empty full release ledger.');
        }

        return $this->projectionService->buildFromLedgerArray($ledger);
    }

    /**
     * @return array<string, mixed>
     */
    private function readLedgerPath(string $ledgerPath): array
    {
        $path = trim($ledgerPath);
        if ($path === '' || ! is_file($path)) {
            throw new \RuntimeException('Career full release ledger file not found: '.$ledgerPath);
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('Career full release ledger file is not valid JSON: '.$ledgerPath);
        }

        if (is_array($payload['ledger'] ?? null)) {
            return $payload['ledger'];
        }

        return $payload;
    }
}
