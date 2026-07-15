<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV2IntegrityGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use Throwable;

final class PersonalityEnneagramAuthorityV2IntegrityGate extends Command
{
    protected $signature = 'personality:enneagram-authority-v2-integrity-gate
        {--source=docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-benchmark-01/production-scorecard.json : Frozen PR01 production scorecard}
        {--json : Emit JSON output}';

    protected $description = 'Validate the frozen 116-page Enneagram public authority truth without writing CMS or runtime state.';

    public function handle(EnneagramPublicAuthorityV2IntegrityGate $gate): int
    {
        try {
            $summary = $gate->validate($this->loadScorecard((string) $this->option('source')));
        } catch (Throwable $exception) {
            $summary = [
                'artifact' => EnneagramPublicAuthorityV2IntegrityGate::ARTIFACT,
                'status' => 'fail',
                'ok' => false,
                'error_count' => 1,
                'error_codes' => ['command_error'],
                'errors' => [[
                    'code' => 'command_error',
                    'row_key' => 'command',
                    'message' => $exception->getMessage(),
                ]],
                'writes_committed' => false,
                'cms_write_attempted' => false,
                'database_mutation_attempted' => false,
                'indexability_mutation_attempted' => false,
                'search_submission_attempted' => false,
            ];
        }

        $this->emit($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function loadScorecard(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('--source is required.');
        }

        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('Enneagram Authority V2 scorecard not found: '.$resolved);
        }

        try {
            $decoded = json_decode(File::get($resolved), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Enneagram Authority V2 scorecard is not valid JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($decoded) || ! is_array($decoded['rows'] ?? null)) {
            throw new RuntimeException('Enneagram Authority V2 scorecard must contain a rows array.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $summary */
    private function emit(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ));

            return;
        }

        $this->line('status='.(string) ($summary['status'] ?? 'fail'));
        $this->line('source_page_count='.(string) ($summary['source_page_count'] ?? 0));
        $this->line('unique_identity_locale_count='.(string) ($summary['unique_identity_locale_count'] ?? 0));
        $this->line('errors_count='.(string) ($summary['error_count'] ?? 0));
        $this->line('writes_committed=0');
    }
}
