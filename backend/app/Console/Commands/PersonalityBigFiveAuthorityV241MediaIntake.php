<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BigFive\AuthorityV2\MediaAuthority\BigFiveAuthorityV2MediaMappingPreflight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveAuthorityV241MediaIntake extends Command
{
    protected $signature = 'personality:big-five-authority-v2-media-intake
        {--intake=../generated/big-five-authority-v2/big5-authority-v2-media-authority-41/approved-media-intake.json : Approved Media Library intake JSON}
        {--candidate-map=../generated/big-five-authority-v2/big5-authority-v2-media-og-34/candidate-media-map.json : Locked PR34 231-page candidate map}
        {--requirements=../generated/big-five-authority-v2/big5-authority-v2-media-og-34/upload-mapping-manifest.json : Locked PR34 18-group requirements}
        {--preflight : Required; validate and build the mapping plan with zero database writes}
        {--json : Emit the full JSON preflight result}
        {--output= : Optional local JSON report path; never writes Media Library or CMS records}';

    protected $description = 'Fail-closed approved Big Five media intake and zero-write mapping preflight.';

    public function handle(BigFiveAuthorityV2MediaMappingPreflight $preflight): int
    {
        try {
            if (! (bool) $this->option('preflight')) {
                throw new RuntimeException('--preflight is required; PR41 intentionally implements no write or upload mode.');
            }

            $result = $preflight->preflight(
                (string) $this->option('intake'),
                (string) $this->option('candidate-map'),
                (string) $this->option('requirements'),
            );
        } catch (Throwable $throwable) {
            $result = [
                'ok' => false,
                'status' => 'FAIL_CLOSED',
                'mode' => 'preflight_only_zero_write',
                'actions' => [
                    'database_writes' => 0,
                    'media_uploads' => 0,
                    'media_library_writes' => 0,
                    'cms_mapping_writes' => 0,
                    'publish_state_changes' => 0,
                    'indexability_changes' => 0,
                    'deployments' => 0,
                ],
                'error' => $throwable->getMessage(),
            ];
        }

        $this->writeOutput($result);
        $this->emitResult($result);

        return ($result['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $result */
    private function writeOutput(array $result): void
    {
        $output = trim((string) $this->option('output'));
        if ($output === '') {
            return;
        }
        $resolved = str_starts_with($output, DIRECTORY_SEPARATOR) ? $output : base_path($output);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, json_encode(
            $result,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL);
    }

    /** @param array<string, mixed> $result */
    private function emitResult(array $result): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $result,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return;
        }

        $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
        $actions = is_array($result['actions'] ?? null) ? $result['actions'] : [];
        $this->line('ok='.(($result['ok'] ?? false) ? '1' : '0'));
        $this->line('status='.(string) ($result['status'] ?? 'FAIL_CLOSED'));
        $this->line('mode='.(string) ($result['mode'] ?? 'preflight_only_zero_write'));
        $this->line('candidate_pages='.(string) ($counts['candidate_pages'] ?? 0));
        $this->line('approved_grouped_slot_requirements='.(string) ($counts['approved_grouped_slot_requirements'] ?? 0));
        $this->line('mapped_page_slots='.(string) ($counts['mapped_page_slots'] ?? 0));
        $this->line('missing_pending_page_slots='.(string) ($counts['missing_pending_page_slots'] ?? 0));
        $this->line('database_writes='.(string) ($actions['database_writes'] ?? 0));
        $this->line('media_uploads='.(string) ($actions['media_uploads'] ?? 0));
        $this->line('cms_mapping_writes='.(string) ($actions['cms_mapping_writes'] ?? 0));
        $this->line('mapping_package_sha256='.(string) ($result['mapping_package_sha256'] ?? ''));
        if (isset($result['error'])) {
            $this->line('error='.(string) $result['error']);
        }
    }
}
