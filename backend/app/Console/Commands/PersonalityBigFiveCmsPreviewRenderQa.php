<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\BigFiveCmsPreviewRenderQaValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class PersonalityBigFiveCmsPreviewRenderQa extends Command
{
    protected $signature = 'personality:big-five-cms-preview-render-qa
        {--source-hash= : Required source package SHA-256 from the staging write evidence}
        {--target-env= : Required target environment, staging or dev}
        {--expected-row-count=42 : Expected number of imported Big Five CMS draft rows}
        {--allow-testing : Permit APP_ENV=testing with sqlite for automated tests only}
        {--json : Emit the full JSON QA report}
        {--output= : Optional path to write the JSON QA report}';

    protected $description = 'Read-only Big Five CMS preview/readback QA for staging/dev draft rows.';

    public function handle(BigFiveCmsPreviewRenderQaValidator $validator): int
    {
        try {
            $summary = $this->guardedRun($validator);
        } catch (RuntimeException $exception) {
            $summary = $this->failureSummary($exception->getMessage());
        } catch (Throwable $exception) {
            $summary = $this->failureSummary($exception->getMessage(), 'unexpected_error');
        }

        $this->writeOutputFile($summary);
        $this->emitSummary($summary);

        return ($summary['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function guardedRun(BigFiveCmsPreviewRenderQaValidator $validator): array
    {
        $targetEnvironment = strtolower(trim((string) $this->option('target-env')));
        if (! in_array($targetEnvironment, ['staging', 'dev'], true)) {
            throw new RuntimeException('--target-env must be staging or dev.');
        }

        $this->guardRuntimeEnvironment($targetEnvironment);

        $sourceHash = strtolower(trim((string) $this->option('source-hash')));
        if (! preg_match('/^[a-f0-9]{64}$/', $sourceHash)) {
            throw new RuntimeException('--source-hash must be a 64-character SHA-256 hex string.');
        }

        $expectedRowCount = max(1, (int) $this->option('expected-row-count'));

        return $validator->validate($sourceHash, $targetEnvironment, $expectedRowCount);
    }

    private function guardRuntimeEnvironment(string $targetEnvironment): void
    {
        $appEnvironment = app()->environment();
        if (in_array($appEnvironment, ['production', 'prod'], true) || $targetEnvironment === 'production') {
            throw new RuntimeException('Production environment is not authorized for this read-only QA command.');
        }

        if ((bool) $this->option('allow-testing')) {
            if ($appEnvironment !== 'testing' || config('database.default') !== 'sqlite') {
                throw new RuntimeException('--allow-testing is only valid for APP_ENV=testing with sqlite.');
            }

            return;
        }

        if ($targetEnvironment === 'staging' && $appEnvironment !== 'staging') {
            throw new RuntimeException('Target staging requires APP_ENV=staging.');
        }

        if ($targetEnvironment === 'dev' && ! in_array($appEnvironment, ['dev', 'local'], true)) {
            throw new RuntimeException('Target dev requires APP_ENV=dev or APP_ENV=local.');
        }
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emitSummary(array $summary): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            ));

            return;
        }

        $this->line('ok='.(($summary['ok'] ?? false) ? '1' : '0'));
        $this->line('status='.(string) ($summary['status'] ?? 'fail'));
        $this->line('target_environment='.(string) ($summary['target_environment'] ?? ''));
        $this->line('row_count='.(string) ($summary['row_count'] ?? 0));
        $this->line('preview_payload_count='.(string) ($summary['preview_payload_count'] ?? 0));
        $this->line('public_api_readback_visible_count='.(string) ($summary['public_api_readback_visible_count'] ?? 0));
        $this->line('faq_duplicate_render_risk_count='.(string) ($summary['faq_duplicate_render_risk_count'] ?? 0));
        $this->line('runtime_jsonld_enabled_count='.(string) ($summary['runtime_jsonld_enabled_count'] ?? 0));
        $this->line('errors_count='.(string) count((array) ($summary['errors'] ?? [])));
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function writeOutputFile(array $summary): void
    {
        $output = trim((string) $this->option('output'));
        if ($output === '') {
            return;
        }

        $resolved = str_starts_with($output, '/')
            ? $output
            : base_path($output);
        File::ensureDirectoryExists(dirname($resolved));
        File::put($resolved, ((string) json_encode(
            $summary,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        )).PHP_EOL);
    }

    /**
     * @return array<string,mixed>
     */
    private function failureSummary(string $message, string $code = 'runtime_error'): array
    {
        return [
            'artifact' => 'BIG5-CMS-PREVIEW-RENDER-QA-11',
            'status' => 'fail',
            'ok' => false,
            'writes_committed' => false,
            'cms_write_attempted' => false,
            'publish_attempted' => false,
            'index_attempted' => false,
            'search_release_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'jsonld_runtime_release_attempted' => false,
            'errors' => [[
                'field' => 'command',
                'code' => $code,
                'message' => $message,
            ]],
            'warnings' => [],
        ];
    }
}
