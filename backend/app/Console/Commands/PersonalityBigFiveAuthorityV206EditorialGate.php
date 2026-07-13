<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV2\EditorialGate\BigFiveEditorialGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveAuthorityV206EditorialGate extends Command
{
    protected $signature = 'personality-big-five:authority-v2-editorial-gate
        {--source=../generated/big-five-authority-v2/big5-authority-v2-editorial-gate-06/final-package.json : Editorial candidate package}
        {--source-ledger=../generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json : Shared source authority}
        {--json : Emit JSON output}';

    protected $description = 'Run the Big Five Authority V2 fail-closed editorial QA gate without writing CMS or release state.';

    public function handle(BigFiveEditorialGate $gate): int
    {
        try {
            $summary = $gate->validate(
                $this->readJson((string) $this->option('source')),
                $this->readJson((string) $this->option('source-ledger')),
            );
        } catch (Throwable $exception) {
            $summary = [
                'artifact' => 'BIG5-AUTHORITY-V2-EDITORIAL-GATE-06',
                'status' => 'fail',
                'ok' => false,
                'issues' => [[
                    'gate' => 'command',
                    'code' => 'command_error',
                    'path' => 'command',
                    'message' => $exception->getMessage(),
                ]],
                'human_review_passed' => false,
                'publish_allowed' => false,
                'schema_eligible' => false,
                'ai_detector_used' => false,
                'writes_committed' => false,
                'cms_write_attempted' => false,
                'indexability_mutation_attempted' => false,
                'search_submission_attempted' => false,
                'deploy_attempted' => false,
            ];
        }

        $encoded = (string) json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ((bool) $this->option('json')) {
            $this->line($encoded);
        } else {
            $this->line('status='.(string) ($summary['status'] ?? 'fail'));
            $this->line('issues_count='.(string) count((array) ($summary['issues'] ?? [])));
            $this->line('human_review_passed=0');
            $this->line('publish_allowed=0');
            $this->line('writes_committed=0');
        }

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        $path = trim($path);
        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if ($path === '' || ! File::isFile($resolved)) {
            throw new RuntimeException('Editorial gate JSON file not found: '.$resolved);
        }

        try {
            $decoded = json_decode(File::get($resolved), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Editorial gate file is not valid JSON: '.$exception->getMessage(), 0, $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Editorial gate file must decode to an object.');
        }

        return $decoded;
    }
}
