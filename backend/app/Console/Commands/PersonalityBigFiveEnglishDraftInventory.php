<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV3\ReadOnly\BigFiveEnglishDraftInventory;
use Illuminate\Console\Command;
use Throwable;

final class PersonalityBigFiveEnglishDraftInventory extends Command
{
    protected $signature = 'personality:big-five-english-draft-inventory {--json : Emit pretty sanitized JSON}';

    protected $description = 'Emit a read-only sanitized inventory of the English Big Five revision cohort.';

    public function handle(BigFiveEnglishDraftInventory $inventory): int
    {
        try {
            $result = $inventory->inspect();
        } catch (Throwable) {
            $result = [
                'schema_version' => BigFiveEnglishDraftInventory::SCHEMA_VERSION,
                'ok' => false,
                'status' => 'FAIL_CLOSED_BIG_FIVE_ENGLISH_DRAFT_INVENTORY',
                'writes_committed' => false,
                'error_code' => 'authority_inventory_unavailable',
            ];
        }

        $this->line(json_encode(
            $result,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | ((bool) $this->option('json') ? JSON_PRETTY_PRINT : 0),
        ));

        return ($result['ok'] ?? true) === true ? self::SUCCESS : self::FAILURE;
    }
}
