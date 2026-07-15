<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV208EditorialGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityEnneagramAuthorityV2EditorialGate extends Command
{
    protected $signature = 'personality:enneagram-authority-v2-editorial-gate
        {--source= : Aggregate 116-asset editorial candidate JSON}
        {--source-registry=docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07/source-registry.json : Frozen source registry}
        {--page-claim-maps=docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07/page-claim-maps.json : Frozen 116-page claim maps}
        {--json : Emit the full JSON report}';

    protected $description = 'Run the read-only fail-closed Enneagram Authority V2 bilingual editorial gate.';

    public function handle(EnneagramPublicAuthorityV208EditorialGate $gate): int
    {
        try {
            $source = trim((string) $this->option('source'));
            if ($source === '') {
                throw new RuntimeException('--source is required.');
            }
            $result = $gate->validate(
                $this->readJson($source),
                $this->readJson((string) $this->option('source-registry')),
                $this->readJson((string) $this->option('page-claim-maps')),
            );
        } catch (Throwable $throwable) {
            $result = [
                'artifact' => EnneagramPublicAuthorityV208EditorialGate::ARTIFACT,
                'status' => 'fail_closed',
                'ok' => false,
                'automated_gate_passed' => false,
                'human_review_completed' => false,
                'human_review_passed' => false,
                'publish_eligible' => false,
                'writes_committed' => false,
                'cms_write_attempted' => false,
                'database_mutation_attempted' => false,
                'indexability_mutation_attempted' => false,
                'sitemap_mutation_attempted' => false,
                'llms_mutation_attempted' => false,
                'search_submission_attempted' => false,
                'deploy_attempted' => false,
                'issues' => [[
                    'gate' => 'command',
                    'code' => 'command_error',
                    'asset_key' => null,
                    'path' => 'command',
                    'message' => $throwable->getMessage(),
                ]],
            ];
        }

        $this->emit($result);

        return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException("Editorial gate input not found: {$path}");
        }
        $decoded = json_decode(File::get($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("Editorial gate input must be a JSON object: {$path}");
        }

        return $decoded;
    }

    /** @param array<string, mixed> $result */
    private function emit(array $result): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return;
        }

        foreach (['ok', 'status', 'target_count', 'qa_row_count', 'automated_gate_passed', 'human_review_completed', 'publish_eligible', 'writes_committed'] as $field) {
            if (! array_key_exists($field, $result)) {
                continue;
            }
            $value = is_bool($result[$field]) ? ($result[$field] ? '1' : '0') : (string) $result[$field];
            $this->line("{$field}={$value}");
        }
        if (! empty($result['issues'])) {
            $this->line('issue_count='.count($result['issues']));
        }
    }
}
