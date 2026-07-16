<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV222ReleaseGate;
use App\Services\Enneagram\AuthorityV2\EnneagramPublicAuthorityV2IntegrityGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use JsonException;
use RuntimeException;
use Throwable;

final class PersonalityEnneagramAuthorityV2IntegrityGate extends Command
{
    protected $signature = 'personality:enneagram-authority-v2-integrity-gate
        {--source=docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-benchmark-01/production-scorecard.json : Frozen PR01 production scorecard}
        {--editorial-source= : Aggregate 116-asset editorial candidate JSON}
        {--source-registry=docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07/source-registry.json : Frozen source registry}
        {--page-claim-maps=docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-source-ledger-07/page-claim-maps.json : Frozen 116-page claim maps}
        {--release-gate : Aggregate the frozen 116-asset PR22 release package without writes}
        {--manual-reviews=docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-release-gate-22/manual-review-register.json : Named human review evidence bound to exact asset hashes}
        {--json : Emit JSON output}';

    protected $description = 'Validate frozen Enneagram Authority V2 target or editorial truth without writing CMS or runtime state.';

    public function handle(EnneagramPublicAuthorityV2IntegrityGate $gate): int
    {
        $editorialSource = trim((string) $this->option('editorial-source'));
        $releaseGate = (bool) $this->option('release-gate');
        try {
            if ($releaseGate && $editorialSource !== '') {
                throw new RuntimeException('--release-gate and --editorial-source are mutually exclusive.');
            }
            $summary = match (true) {
                $releaseGate => (new EnneagramPublicAuthorityV222ReleaseGate($gate))->evaluate(
                    base_path(),
                    (string) $this->option('manual-reviews'),
                ),
                $editorialSource !== '' => $gate->validateEditorial(
                    $this->loadJson($editorialSource),
                    $this->loadJson((string) $this->option('source-registry')),
                    $this->loadJson((string) $this->option('page-claim-maps')),
                ),
                default => $gate->validate($this->loadScorecard((string) $this->option('source'))),
            };
        } catch (Throwable $exception) {
            $summary = match (true) {
                $releaseGate => $this->releaseGateCommandError($exception),
                $editorialSource !== '' => $this->editorialCommandError($exception),
                default => $this->integrityCommandError($exception),
            };
        }

        $this->emit($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> */
    private function loadScorecard(string $path): array
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('--source is required.');
        }

        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('Enneagram Authority V2 scorecard not found: '.$resolved);
        }

        try {
            $decoded = json_decode(File::get($resolved), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Enneagram Authority V2 scorecard is not valid JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($decoded) || ! is_array($decoded['rows'] ?? null)) {
            throw new RuntimeException('Enneagram Authority V2 scorecard must contain a rows array.');
        }

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function loadJson(string $path): array
    {
        $path = trim($path);
        $resolved = str_starts_with($path, '/') ? $path : base_path($path);
        if ($path === '' || ! File::isFile($resolved)) {
            throw new RuntimeException('Enneagram Authority V2 editorial input not found: '.$resolved);
        }

        try {
            $decoded = json_decode(File::get($resolved), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Enneagram Authority V2 editorial input is not valid JSON: '.$exception->getMessage(), 0, $exception);
        }
        if (! is_array($decoded)) {
            throw new RuntimeException('Enneagram Authority V2 editorial input must be a JSON object.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $summary */
    private function emit(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ));

            return;
        }

        if (array_key_exists('qa_row_count', $summary)) {
            foreach (['ok', 'status', 'target_count', 'qa_row_count', 'automated_gate_passed', 'human_review_completed', 'publish_eligible', 'writes_committed'] as $field) {
                $value = is_bool($summary[$field] ?? null) ? ($summary[$field] ? '1' : '0') : (string) ($summary[$field] ?? '');
                $this->line($field.'='.$value);
            }

            return;
        }

        if (array_key_exists('release_eligible', $summary)) {
            foreach (['status', 'decision', 'automated_gate_passed', 'human_review_passed', 'media_boundary_passed', 'release_eligible', 'package_sha256'] as $field) {
                $value = $summary[$field] ?? null;
                $this->line($field.'='.(is_bool($value) ? ($value ? '1' : '0') : (string) $value));
            }
            $this->line('asset_count='.(string) ($summary['counts']['assets'] ?? 0));
            $this->line('missing_human_review_count='.(string) ($summary['counts']['missing_human_reviews'] ?? 0));
            $this->line('writes_committed=0');

            return;
        }

        $this->line('status='.(string) ($summary['status'] ?? 'fail'));
        $this->line('source_page_count='.(string) ($summary['source_page_count'] ?? 0));
        $this->line('unique_identity_locale_count='.(string) ($summary['unique_identity_locale_count'] ?? 0));
        $this->line('errors_count='.(string) ($summary['error_count'] ?? 0));
        $this->line('writes_committed=0');
    }

    /** @return array<string, mixed> */
    private function integrityCommandError(Throwable $exception): array
    {
        return [
            'artifact' => EnneagramPublicAuthorityV2IntegrityGate::ARTIFACT,
            'status' => 'fail',
            'ok' => false,
            'error_count' => 1,
            'error_codes' => ['command_error'],
            'errors' => [[
                'code' => 'command_error',
                'row_key' => 'command',
                'message' => $exception->getMessage(),
            ]],
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'database_mutation_attempted' => false,
            'indexability_mutation_attempted' => false,
            'search_submission_attempted' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function editorialCommandError(Throwable $exception): array
    {
        return [
            'artifact' => EnneagramPublicAuthorityV2IntegrityGate::EDITORIAL_ARTIFACT,
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
                'message' => $exception->getMessage(),
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function releaseGateCommandError(Throwable $exception): array
    {
        return [
            'artifact' => EnneagramPublicAuthorityV222ReleaseGate::ARTIFACT,
            'status' => 'fail_closed',
            'decision' => 'HOLD',
            'ok' => false,
            'automated_gate_passed' => false,
            'human_review_passed' => false,
            'media_boundary_passed' => false,
            'release_eligible' => false,
            'errors' => [['code' => 'command_error', 'subject' => $exception->getMessage()]],
            'execution_boundaries' => [
                'production_write_executed' => false,
                'database_mutated' => false,
                'cms_mutated' => false,
                'revision_pointer_changed' => false,
                'media_uploaded' => false,
                'cache_revalidated' => false,
                'indexability_changed' => false,
                'sitemap_changed' => false,
                'llms_changed' => false,
                'search_submitted' => false,
                'deployed' => false,
            ],
        ];
    }
}
