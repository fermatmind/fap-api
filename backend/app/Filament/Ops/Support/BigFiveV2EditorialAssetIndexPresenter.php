<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Services\BigFive\Cms\BigFiveV2EditorialAssetIndex;

final class BigFiveV2EditorialAssetIndexPresenter
{
    public function __construct(
        private readonly BigFiveV2EditorialAssetIndex $index,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        return $this->index->summary();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function tableRows(): array
    {
        return array_map(
            static fn (array $row): array => [
                'asset_key' => $row['asset_key'],
                'asset_type' => $row['asset_type'],
                'relative_path' => $row['relative_path'],
                'version' => $row['version'],
                'runtime_use' => $row['runtime_use'],
                'release_snapshot_ids' => implode(',', (array) $row['linked_release_snapshot_ids']),
                'read_only' => true,
            ],
            $this->index->rows()
        );
    }

    /**
     * @return list<string>
     */
    public function availableActions(): array
    {
        return [];
    }
}
