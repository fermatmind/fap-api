<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Tool;

use App\Services\SeoCouncil\Platform12\Platform12ContractRegistry;
use InvalidArgumentException;

final class Platform12ContractVerificationTool implements Platform12ReadOnlyTool
{
    public function __construct(private readonly Platform12ContractRegistry $contracts) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function invoke(array $input): array
    {
        if (array_keys($input) !== ['catalog_ref'] || ! is_array($input['catalog_ref'])) {
            throw new InvalidArgumentException('TOOL_INPUT_SCHEMA_INVALID');
        }
        $catalog = $this->contracts->missionCatalog();
        $expected = [
            'id' => $catalog['catalog_id'],
            'version' => $catalog['catalog_version'],
            'hash' => $catalog['catalog_hash'],
        ];

        return [
            'catalog_ref_matches' => $input['catalog_ref'] === $expected,
            'generated_contracts_valid' => $this->contracts->verifyGenerated(),
            'catalog_ref' => $expected,
        ];
    }
}
