<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ci\ScaleImpactResolver;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

final class CiScaleImpact extends Command
{
    protected $signature = 'ci:scale-impact
        {--base=origin/main : Base ref for diff in CI}
        {--head=HEAD : Head ref for diff in CI}
        {--format=json : Output format: json|env}
        {--write-github-output : Append key/value outputs to GITHUB_OUTPUT}';

    protected $description = 'Resolve scale regression scope from changed files.';

    public function handle(ScaleImpactResolver $resolver): int
    {
        $base = trim((string) $this->option('base'));
        $head = trim((string) $this->option('head'));
        $format = strtolower(trim((string) $this->option('format')));
        if (!in_array($format, ['json', 'env'], true)) {
            $format = 'json';
        }

        $paths = $this->collectChangedPaths($base !== '' ? $base : 'origin/main', $head !== '' ? $head : 'HEAD');
        $resolved = $resolver->resolve($paths);

        if ($format === 'env') {
            $this->line($this->toEnvOutput($resolved));
        } else {
            $this->line((string) json_encode($resolved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        if ((bool) $this->option('write-github-output')) {
            $this->writeGithubOutput($resolved);
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function collectChangedPaths(string $base, string $head): array
    {
        if (!$this->gitRefExists($base)) {
            $this->runGit(['fetch', '--no-tags', '--prune', '--depth=200', 'origin', 'main']);
        }

        if ($this->gitRefExists($base)) {
            $diff = $this->runGit(['diff', '--name-only', '--diff-filter=ACMR', "{$base}...{$head}"]);
            return $this->splitLines($diff);
        }

        $fallback = $this->runGit(['diff', '--name-only', '--diff-filter=ACMR', 'HEAD~1..HEAD']);

        return $this->splitLines($fallback);
    }

    private function gitRefExists(string $ref): bool
    {
        $process = new Process(['git', 'rev-parse', '--verify', '--quiet', $ref], base_path());
        $process->run();

        return $process->isSuccessful();
    }

    private function runGit(array $args): string
    {
        $process = new Process(array_merge(['git'], $args), base_path());
        $process->run();

        if (!$process->isSuccessful()) {
            return '';
        }

        return (string) $process->getOutput();
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $value): array
    {
        $out = [];
        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function toEnvOutput(array $payload): string
    {
        $bool = static fn (bool $v): string => $v ? '1' : '0';

        $lines = [
            'run_big5_ocean_gate=' . $bool((bool) ($payload['run_big5_ocean_gate'] ?? false)),
            'run_full_scale_regression=' . $bool((bool) ($payload['run_full_scale_regression'] ?? false)),
            'run_mbti_smoke=' . $bool((bool) ($payload['run_mbti_smoke'] ?? true)),
            'scale_scope=' . (string) ($payload['scale_scope'] ?? 'mbti_only'),
            'reason=' . (string) ($payload['reason'] ?? ''),
        ];

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeGithubOutput(array $payload): void
    {
        $target = trim((string) getenv('GITHUB_OUTPUT'));
        if ($target === '') {
            return;
        }

        $bool = static fn (bool $v): string => $v ? '1' : '0';
        $lines = [
            'run_big5_ocean_gate=' . $bool((bool) ($payload['run_big5_ocean_gate'] ?? false)),
            'run_full_scale_regression=' . $bool((bool) ($payload['run_full_scale_regression'] ?? false)),
            'run_mbti_smoke=' . $bool((bool) ($payload['run_mbti_smoke'] ?? true)),
            'scale_scope=' . (string) ($payload['scale_scope'] ?? 'mbti_only'),
            'reason=' . (string) ($payload['reason'] ?? ''),
        ];

        file_put_contents($target, implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND);
    }
}
