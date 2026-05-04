<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Career\Governance\CareerDisplayAssetLineageReporter;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class CareerValidateDisplayAssetLineage extends Command
{
    protected $signature = 'career:validate-display-asset-lineage
        {--slugs= : Comma-separated career slugs}
        {--json : Emit JSON report}
        {--output= : Optional report output path}';

    protected $description = 'Read-only display asset lineage and rollback report for career display surfaces.';

    public function __construct(private readonly CareerDisplayAssetLineageReporter $reporter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $slugs = $this->requiredSlugs();
            $items = $this->reporter->reportForSlugs($slugs);
            $report = [
                'command' => 'career:validate-display-asset-lineage',
                'validator_version' => CareerDisplayAssetLineageReporter::VERSION,
                'read_only' => true,
                'writes_database' => false,
                'display_assets_changed' => false,
                'release_states_changed' => false,
                'sitemap_changed' => false,
                'llms_changed' => false,
                'requested_slugs' => $slugs,
                'validated_count' => count($items),
                'decision' => $this->allComplete($items) ? 'pass' : 'no_go',
                'summary' => $this->summary($items),
                'items' => $items,
            ];

            return $this->finish($report);
        } catch (Throwable $throwable) {
            return $this->finish([
                'command' => 'career:validate-display-asset-lineage',
                'validator_version' => CareerDisplayAssetLineageReporter::VERSION,
                'decision' => 'fail',
                'read_only' => true,
                'writes_database' => false,
                'errors' => [$throwable->getMessage()],
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function requiredSlugs(): array
    {
        $raw = trim((string) $this->option('slugs'));
        if ($raw === '') {
            throw new RuntimeException('--slugs is required.');
        }

        $slugs = array_values(array_unique(array_filter(array_map(
            static fn (string $slug): string => strtolower(trim($slug)),
            explode(',', $raw),
        ), static fn (string $slug): bool => $slug !== '')));

        if ($slugs === []) {
            throw new RuntimeException('--slugs must contain at least one slug.');
        }

        return $slugs;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function allComplete(array $items): bool
    {
        return $items !== [] && count(array_filter(
            $items,
            static fn (array $item): bool => ($item['lineage_complete'] ?? false) !== true,
        )) === 0;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function summary(array $items): array
    {
        return [
            'complete' => count(array_filter($items, static fn (array $item): bool => ($item['lineage_complete'] ?? false) === true)),
            'incomplete' => count(array_filter($items, static fn (array $item): bool => ($item['lineage_complete'] ?? false) !== true)),
            'missing_display_assets' => count(array_filter($items, static fn (array $item): bool => ($item['lineage_status'] ?? null) === 'missing_display_asset')),
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function finish(array $report): int
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($json)) {
            throw new RuntimeException('Unable to encode validation report.');
        }

        $output = trim((string) ($this->option('output') ?? ''));
        if ($output !== '') {
            $written = file_put_contents($output, $json.PHP_EOL);
            if ($written === false) {
                throw new RuntimeException('Unable to write report output: '.$output);
            }
        }

        if ((bool) $this->option('json')) {
            $this->output->write($json.PHP_EOL, false, OutputInterface::OUTPUT_RAW);
        } else {
            $this->line('validator_version='.$report['validator_version']);
            $this->line('decision='.$report['decision']);
            $this->line('validated_count='.$report['validated_count']);
        }

        return ($report['decision'] ?? 'fail') === 'fail' ? self::FAILURE : self::SUCCESS;
    }
}
