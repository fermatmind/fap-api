<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SeoIntel\RiasecMajorGraphAuthorityValidator;
use Illuminate\Console\Command;
use Throwable;

final class RiasecMajorGraphAuthorityAuditCommand extends Command
{
    protected $signature = 'riasec:major-graph-authority-audit
        {--source=docs/seo/import-packages/riasec-major-graph-authority/riasec_major_graph_authority.v1.json : Source package path relative to backend base path}
        {--strict : Return non-zero when authority validation fails}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only RIASEC major-cluster graph authority validator.';

    public function handle(RiasecMajorGraphAuthorityValidator $validator): int
    {
        try {
            $source = ltrim((string) $this->option('source'), '/');
            $path = base_path($source);

            if (! is_file($path)) {
                $this->emit([
                    'task' => 'FA30-API-09',
                    'status' => 'fail',
                    'ok' => false,
                    'source_package' => $source,
                    'issues' => ['source_package_missing'],
                    'issue_count' => 1,
                    'boundary' => $this->boundary(),
                ]);

                return self::FAILURE;
            }

            $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $result = $validator->validate(is_array($payload) ? $payload : []);
            $summary = [
                'task' => 'FA30-API-09',
                'runtime' => 'riasec_major_graph_authority_audit',
                'status' => $result['status'],
                'ok' => $result['ok'],
                'source_package' => $source,
                'cluster_count' => data_get($result, 'summary.cluster_count', 0),
                'indexable_cluster_count' => data_get($result, 'summary.indexable_cluster_count', 0),
                'reviewed_cluster_count' => data_get($result, 'summary.reviewed_cluster_count', 0),
                'issue_count' => $result['issue_count'],
                'issues' => $result['issues'],
                'boundary' => $this->boundary(),
            ];

            $this->emit($summary);

            return ((bool) $result['ok'] || ! (bool) $this->option('strict')) ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $throwable) {
            $this->emit([
                'task' => 'FA30-API-09',
                'status' => 'fail',
                'ok' => false,
                'issues' => ['exception:'.$throwable->getMessage()],
                'issue_count' => 1,
                'boundary' => $this->boundary(),
            ]);

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line('status='.(string) ($payload['status'] ?? 'fail'));
        $this->line('ok='.(($payload['ok'] ?? false) ? '1' : '0'));
        $this->line('source_package='.(string) ($payload['source_package'] ?? ''));
        $this->line('cluster_count='.(string) ($payload['cluster_count'] ?? 0));
        $this->line('indexable_cluster_count='.(string) ($payload['indexable_cluster_count'] ?? 0));
        $this->line('reviewed_cluster_count='.(string) ($payload['reviewed_cluster_count'] ?? 0));
        $this->line('issue_count='.(string) ($payload['issue_count'] ?? 0));

        foreach ((array) ($payload['issues'] ?? []) as $issue) {
            $this->line('issue='.(string) $issue);
        }
    }

    /**
     * @return array<string, bool>
     */
    private function boundary(): array
    {
        return [
            'db_write_performed' => false,
            'cms_write_performed' => false,
            'public_api_route_created' => false,
            'frontend_changed' => false,
            'publish_performed' => false,
            'search_submission_performed' => false,
            'sitemap_or_llms_changed' => false,
            'production_deploy_performed' => false,
        ];
    }
}
