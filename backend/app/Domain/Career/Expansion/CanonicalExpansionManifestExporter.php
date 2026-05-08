<?php

declare(strict_types=1);

namespace App\Domain\Career\Expansion;

final class CanonicalExpansionManifestExporter
{
    public const MANIFEST_FILENAME = 'career-canonical-expansion-manifest.json';

    public function __construct(
        private readonly CanonicalExpansionManifestService $service,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?string $truthPath = null, ?string $projectionPath = null, ?string $ledgerPath = null, ?int $batchSize = null, ?string $batchId = null): array
    {
        return $this->service->build(
            truthPath: $truthPath,
            projectionPath: $projectionPath,
            ledgerPath: $ledgerPath,
            batchSize: $batchSize,
            batchId: $batchId,
        );
    }
}
