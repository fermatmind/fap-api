<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Bridge\BigFiveCareerBridgeAuditor;
use App\Services\Career\CareerCliArtifactPathGuard;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

final class CareerAuditBigFiveBridge extends Command
{
    protected $signature = 'career:audit-big-five-bridge
        {--big-five-projection= : Big Five published/public projection JSON artifact}
        {--career-projection= : Career runtime publish projection JSON artifact}
        {--candidates= : Big Five Career bridge candidate JSON artifact}
        {--format=json : Output format: json or markdown}
        {--output= : Optional local audit artifact output path}';

    protected $description = 'Read-only, fail-closed Big Five Career bridge authority audit.';

    public function handle(BigFiveCareerBridgeAuditor $auditor): int
    {
        try {
            $bigFive = $this->readJsonOption('big-five-projection');
            $career = $this->readJsonOption('career-projection');
            $candidates = $this->readJsonOption('candidates');
            $format = strtolower(trim((string) $this->option('format')));
            if (! in_array($format, ['json', 'markdown'], true)) {
                throw new RuntimeException('--format must be json or markdown.');
            }

            $report = $auditor->audit($bigFive, $career, $candidates);
            $contents = $format === 'markdown'
                ? $auditor->markdown($report)
                : $this->encode($report).PHP_EOL;
            CareerCliArtifactPathGuard::writeTextOutput($this->option('output'), $contents);
            $this->line(rtrim($contents));

            return ($report['status'] ?? null) === 'pass' ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function readJsonOption(string $option): array
    {
        $path = trim((string) ($this->option($option) ?? ''));
        if ($path === '' || ! is_file($path) || is_link($path)) {
            throw new RuntimeException('--'.$option.' must point to a readable local JSON file.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read --'.$option.' artifact.');
        }
        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('--'.$option.' is not valid JSON.', previous: $exception);
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new RuntimeException('--'.$option.' must decode to a JSON object.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode audit report.', previous: $exception);
        }
    }
}
