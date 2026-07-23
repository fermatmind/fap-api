<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV3\Release\BigFiveEn52ProductionEvidence;
use Illuminate\Console\Command;
use Throwable;

final class PersonalityBigFiveEn52ProductionEvidence extends Command
{
    protected $signature = 'personality:big-five-en52-production-evidence
        {--package=../generated/big-five-en52-release/release-package.json : Locked EN52 release package}
        {--json : Emit sanitized JSON}';

    protected $description = 'Inspect the exact EN52 production cohort and emit read-only backup/runtime baseline evidence.';

    public function handle(BigFiveEn52ProductionEvidence $evidence): int
    {
        try {
            $result = $evidence->inspect((string) $this->option('package'));
        } catch (Throwable $throwable) {
            $result = [
                'schema_version' => BigFiveEn52ProductionEvidence::SCHEMA_VERSION,
                'ok' => false,
                'status' => 'FAIL_CLOSED_BIG_FIVE_EN52_PRODUCTION_EVIDENCE',
                'writes_committed' => false,
                'error' => $throwable->getMessage(),
            ];
        }

        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ((bool) $this->option('json') ? JSON_PRETTY_PRINT : 0)));

        return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
