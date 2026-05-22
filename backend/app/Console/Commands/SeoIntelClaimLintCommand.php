<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\ClaimLint\ChineseClaimLinter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class SeoIntelClaimLintCommand extends Command
{
    protected $signature = 'seo-intel:claim-lint
        {--fixture : Required. Scan bundled CI fixtures only}
        {--json : Required. Output safe machine-readable JSON}';

    protected $description = 'Run the Chinese claim linter against bundled non-production fixtures.';

    public function handle(ChineseClaimLinter $linter): int
    {
        if (! (bool) $this->option('fixture') || ! (bool) $this->option('json')) {
            $this->emit([
                'runtime' => ChineseClaimLinter::RUNTIME,
                'status' => 'blocked',
                'lint_state' => 'blocked',
                'severity' => 'P2',
                'fixture_mode' => (bool) $this->option('fixture'),
                'auto_rewrite_attempted' => false,
                'cms_mutation_attempted' => false,
                'production_scan_attempted' => false,
                'blockers' => [
                    [
                        'field' => 'command.options',
                        'code' => 'fixture_json_required',
                        'message' => '--fixture and --json are required.',
                    ],
                ],
            ]);

            return self::FAILURE;
        }

        $report = $linter->lint($this->fixtureCandidates());
        $report['fixture_mode'] = true;
        $report['fixture_source'] = 'backend/tests/Fixtures/SeoIntel/claim_lint';

        $this->emit($report);

        return ($report['lint_state'] ?? null) === 'blocked' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtureCandidates(): array
    {
        $candidates = [];

        foreach (File::glob(base_path('tests/Fixtures/SeoIntel/claim_lint/*.json')) ?: [] as $path) {
            $decoded = json_decode((string) file_get_contents($path), true);

            if (! is_array($decoded)) {
                continue;
            }

            foreach ((array) ($decoded['cases'] ?? []) as $case) {
                if (is_array($case)) {
                    $candidates[] = $case;
                }
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->line('status='.(string) ($payload['status'] ?? 'blocked'));
        $this->line('lint_state='.(string) ($payload['lint_state'] ?? 'blocked'));
        $this->line('severity='.(string) ($payload['severity'] ?? 'P2'));
        $this->line('auto_rewrite_attempted='.(($payload['auto_rewrite_attempted'] ?? true) ? '1' : '0'));
        $this->line('cms_mutation_attempted='.(($payload['cms_mutation_attempted'] ?? true) ? '1' : '0'));
        $this->line('production_scan_attempted='.(($payload['production_scan_attempted'] ?? true) ? '1' : '0'));
    }
}
