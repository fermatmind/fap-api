<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SEO\BigFivePublicIntegrityGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use Throwable;

final class PersonalityBigFivePublicIntegrityGate extends Command
{
    protected $signature = 'personality-big-five:public-integrity-gate
        {--source=../generated/big-five-authority-v2-integrity-gate-02/big_five_124_integrity_candidate_v2.json : Big Five V1 asset package}
        {--base-url=https://fermatmind.com : Declared public authority base URL}
        {--require-reviewed-aliases : Require all twenty reviewed en/zh-CN legacy aliases to resolve by exact single-hop 301}
        {--json : Emit JSON output}';

    protected $description = 'Resolve Big Five public internal links and fail closed on invalid targets without writing CMS state.';

    public function handle(BigFivePublicIntegrityGate $gate): int
    {
        try {
            $package = $this->loadPackage((string) $this->option('source'));
            $summary = $gate->validate(
                $package,
                (string) $this->option('base-url'),
                (bool) $this->option('require-reviewed-aliases'),
            );
        } catch (Throwable $exception) {
            $summary = [
                'artifact' => 'BIG5-AUTHORITY-V2-INTEGRITY-GATE-02',
                'status' => 'fail',
                'ok' => false,
                'errors' => [[
                    'target' => 'command',
                    'code' => 'command_error',
                    'message' => $exception->getMessage(),
                ]],
                'writes_committed' => false,
                'cms_write_attempted' => false,
                'indexability_mutation_attempted' => false,
                'search_submission_attempted' => false,
            ];
        }

        $this->emit($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function loadPackage(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('--source is required.');
        }

        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('Big Five integrity package not found: '.$resolved);
        }

        try {
            $decoded = json_decode(File::get($resolved), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Big Five integrity package is not valid JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($decoded) || ! is_array($decoded['assets'] ?? null)) {
            throw new RuntimeException('Big Five integrity package must contain an assets array.');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $summary */
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
        $this->line('target_count='.(string) ($summary['target_count'] ?? 0));
        $this->line('canonical_200_count='.(string) ($summary['canonical_200_count'] ?? 0));
        $this->line('reviewed_301_alias_count='.(string) ($summary['reviewed_301_alias_count'] ?? 0));
        $this->line('reviewed_301_alias_expected_count='.(string) ($summary['reviewed_301_alias_expected_count'] ?? 0));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
        $this->line('writes_committed=0');
    }
}
