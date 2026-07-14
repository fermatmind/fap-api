<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV2\LinkGraph\BigFiveAuthorityV2LinkGraphValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;

final class PersonalityBigFiveAuthorityV235ValidateLinkGraph extends Command
{
    protected $signature = 'personality:big-five-authority-v2:validate-link-graph
        {--source=../generated/big-five-authority-v2/big5-authority-v2-link-graph-35/link-graph.json : Link graph JSON path relative to backend}
        {--json : Emit the full JSON validation report}';

    protected $description = 'Validate the Big Five Authority V2 candidate link graph without writes or SEO exposure changes.';

    public function handle(BigFiveAuthorityV2LinkGraphValidator $validator): int
    {
        try {
            $graph = $this->readGraph((string) $this->option('source'));
            $report = $validator->validate($graph);
        } catch (JsonException $exception) {
            $report = $this->failureReport('invalid_json', $exception->getMessage());
        } catch (\Throwable $exception) {
            $report = $this->failureReport('read_failure', $exception->getMessage());
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('ok='.(($report['ok'] ?? false) ? '1' : '0'));
            $this->line('status='.(string) ($report['status'] ?? 'fail'));
            foreach ((array) ($report['counts'] ?? []) as $key => $value) {
                $this->line((string) $key.'='.(string) $value);
            }
            $this->line('writes_committed=0');
            $this->line('sitemap_llms_schema_release_attempted=0');
        }

        return ($report['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function readGraph(string $source): array
    {
        $path = str_starts_with($source, '/') ? $source : base_path($source);
        $contents = File::get($path);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function failureReport(string $code, string $message): array
    {
        return [
            'ok' => false,
            'status' => 'fail',
            'artifact' => 'BIG5-AUTHORITY-V2-LINK-GRAPH-35',
            'counts' => ['errors' => 1],
            'errors' => [['field' => 'source', 'code' => $code, 'message' => $message]],
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'indexability_attempted' => false,
            'sitemap_llms_schema_release_attempted' => false,
        ];
    }
}
