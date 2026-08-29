<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoCouncil\Entrypoints\CliMissionAdapter;
use Illuminate\Console\Command;
use Throwable;

final class SeoCouncilSubmitCommand extends Command
{
    protected $signature = 'seo:council-submit {file? : MissionRequest JSON file, or - for stdin} {--json}';

    protected $description = 'Submit one deterministic zero-budget SEO Council MissionRequest';

    public function handle(CliMissionAdapter $adapter): int
    {
        try {
            $file = $this->argument('file');
            $bytes = $file === null || $file === '-'
                ? stream_get_contents(STDIN)
                : file_get_contents((string) $file);
            $input = json_decode((string) $bytes, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($input)) {
                throw new \InvalidArgumentException('MISSION_REQUEST_INVALID');
            }
            $this->line(json_encode($adapter->submit($input), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->line(json_encode([
                'status' => 'REQUEST_INVALID',
                'safe_error_code' => preg_match('/^[A-Z0-9_]+$/D', $exception->getMessage()) === 1
                    ? $exception->getMessage()
                    : 'MISSION_SUBMISSION_FAILED',
                'execution_allowed' => false,
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
