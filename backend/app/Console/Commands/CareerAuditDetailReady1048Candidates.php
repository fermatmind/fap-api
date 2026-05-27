<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Career\Audit\CareerDetailReadyPublicationCandidateScanner;
use Illuminate\Console\Command;

final class CareerAuditDetailReady1048Candidates extends Command
{
    protected $signature = 'career:audit-detail-ready-1048-candidates
        {--json : Emit JSON output}
        {--output= : Optional output path for the scan artifact}';

    protected $description = 'Read-only scan for Career detail-ready 1048 publication candidates.';

    public function handle(CareerDetailReadyPublicationCandidateScanner $scanner): int
    {
        $payload = $scanner->scan();

        $output = $this->option('output');
        if (is_string($output) && trim($output) !== '') {
            $path = trim($output);
            $dir = dirname($path);
            if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new \RuntimeException('Unable to create output directory: '.$dir);
            }
            file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
            $payload['output_path'] = $path;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('schema_version='.$payload['schema_version']);
            $this->line('target_key='.$payload['target_key']);
            $this->line('current_public_detail='.$payload['counts']['current_public_detail']);
            $this->line('union_detail_ready='.$payload['counts']['union_detail_ready']);
            $this->line('ready_not_currently_public='.$payload['counts']['ready_not_currently_public']);
            $this->line('writes_database=false');
            $this->line('next_required_action='.$payload['next_required_action']);
        }

        return self::SUCCESS;
    }
}
