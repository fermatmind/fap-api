<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\StorageCostAnalyzerService;
use Illuminate\Console\Command;

final class StorageCostAnalyzer extends Command
{
    protected $signature = 'storage:analyze-cost
        {--json : Emit the full storage cost analysis payload as JSON}';

    protected $description = 'Read-only storage cost analyzer for the backend storage tree.';

    public function __construct(
        private readonly StorageCostAnalyzerService $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = $this->service->analyze();

        if ((bool) $this->option('json')) {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            if (! is_string($encoded)) {
                $this->error('failed to encode storage cost analysis json.');

                return self::FAILURE;
            }

            $this->line($encoded);

            return self::SUCCESS;
        }

        $topDirectory = is_array($payload['top_directories'][0] ?? null) ? $payload['top_directories'][0] : [];
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];

        $this->line('status='.(string) ($payload['status'] ?? 'ok'));
        $this->line('root='.(string) ($payload['root_path'] ?? ''));
        $this->line('total_bytes='.(int) ($summary['total_bytes'] ?? 0));
        $this->line('total_files='.(int) ($summary['total_files'] ?? 0));
        $this->line('total_directories='.(int) ($summary['total_directories'] ?? 0));
        $this->line('largest_category='.(string) ($summary['largest_category'] ?? ''));
        $this->line('largest_category_bytes='.(int) ($summary['largest_category_bytes'] ?? 0));
        $this->line('top_directory='.(string) ($topDirectory['path'] ?? ''));
        $this->line('top_directory_bytes='.(int) ($topDirectory['bytes'] ?? 0));

        return self::SUCCESS;
    }
}
