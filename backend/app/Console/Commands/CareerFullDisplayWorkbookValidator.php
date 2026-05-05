<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class CareerFullDisplayWorkbookValidator extends Command
{
    protected $signature = 'career:validate-full-display-workbook
        {--file= : Absolute path to a v4.2 career asset workbook}
        {--slugs= : Optional comma-separated explicit slug allowlist}
        {--json : Emit JSON report}
        {--output= : Optional report output path}
        {--plan-output= : Optional full-upload plan JSON output path for full workbook scans}
        {--plan-md-output= : Optional full-upload plan Markdown summary output path for full workbook scans}
        {--strict-authority : Fail when local authority DB validation is unavailable}';

    protected $description = 'Read-only full-workbook career display intake validator.';

    public function handle(): int
    {
        return $this->call('career:validate-display-batch', array_filter([
            '--file' => $this->option('file'),
            '--slugs' => $this->option('slugs'),
            '--json' => (bool) $this->option('json'),
            '--output' => $this->option('output'),
            '--plan-output' => $this->option('plan-output'),
            '--plan-md-output' => $this->option('plan-md-output'),
            '--strict-authority' => (bool) $this->option('strict-authority'),
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }
}
