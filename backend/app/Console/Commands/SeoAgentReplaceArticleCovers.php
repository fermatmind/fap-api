<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\ArticleCoverBatchReplacer;
use RuntimeException;
use Throwable;

final class SeoAgentReplaceArticleCovers extends RetiredSeoAgentCommand
{
    protected $signature = 'seo-agent:replace-article-covers
        {--manifest= : Absolute path to article-cover-replacement.v1 JSON manifest}
        {--dry-run : Validate the complete batch without writes; this is the default mode}
        {--execute : Import media, update exact articles, then run bounded public verification}
        {--receipt= : Optional absolute JSON receipt output path}
        {--actor= : Production actor identifier required for execute}
        {--reason= : Production reason required for execute}
        {--confirm-manifest-sha256= : Exact manifest SHA-256 required for execute}
        {--confirm-execution= : Exact execution phrase emitted by dry-run}
        {--allow-ensure-seo-meta-baseline : Allow only manifest-approved missing SEO baseline creation}
        {--no-publish : Required execute hold}
        {--no-schema : Required execute hold}
        {--no-hreflang : Required execute hold}
        {--no-search : Required execute hold}
        {--no-sitemap-llms-change : Required execute hold}
        {--no-revalidation : Required execute hold}
        {--api-base-url= : Public API origin used by post-write verification}
        {--frontend-base-url= : Frontend origin used by post-write HTML verification}
        {--verify-attempts=6 : Bounded cache convergence attempts, 1..20}
        {--verify-delay-ms=60000 : Delay between attempts, 0..300000 ms}';

    protected $description = 'Fail-closed bilingual article cover batch replacement through Media Library with one receipt.';

    public function handle(ArticleCoverBatchReplacer $replacer): int
    {
        try {
            $receipt = $replacer->run($this->optionsPayload());
        } catch (Throwable $exception) {
            $receipt = [
                'schema_version' => 'article-cover-replacement-receipt.v1',
                'ok' => false,
                'overall_status' => 'failed',
                'writes_attempted' => false,
                'writes_committed' => false,
                'errors' => [[
                    'field' => 'command',
                    'code' => 'unexpected_error',
                    'message' => $exception->getMessage(),
                ]],
            ];
        }

        try {
            $this->writeReceipt($receipt);
        } catch (Throwable $exception) {
            $receipt['ok'] = false;
            $receipt['overall_status'] = (bool) ($receipt['writes_committed'] ?? false) ? 'partial' : 'failed';
            $receipt['errors'][] = [
                'field' => 'receipt',
                'code' => 'receipt_write_failed',
                'message' => $exception->getMessage(),
            ];
        }

        $this->line((string) json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        return (bool) ($receipt['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function optionsPayload(): array
    {
        return [
            'manifest' => (string) $this->option('manifest'),
            'dry_run' => (bool) $this->option('dry-run'),
            'execute' => (bool) $this->option('execute'),
            'actor' => (string) $this->option('actor'),
            'reason' => (string) $this->option('reason'),
            'confirm_manifest_sha256' => (string) $this->option('confirm-manifest-sha256'),
            'confirm_execution' => (string) $this->option('confirm-execution'),
            'allow_ensure_seo_meta_baseline' => (bool) $this->option('allow-ensure-seo-meta-baseline'),
            'no_publish' => (bool) $this->option('no-publish'),
            'no_schema' => (bool) $this->option('no-schema'),
            'no_hreflang' => (bool) $this->option('no-hreflang'),
            'no_search' => (bool) $this->option('no-search'),
            'no_sitemap_llms_change' => (bool) $this->option('no-sitemap-llms-change'),
            'no_revalidation' => (bool) $this->option('no-revalidation'),
            'api_base_url' => (string) ($this->option('api-base-url') ?: config('app.url')),
            'frontend_base_url' => (string) ($this->option('frontend-base-url') ?: config('app.frontend_url')),
            'verify_attempts' => (int) $this->option('verify-attempts'),
            'verify_delay_ms' => (int) $this->option('verify-delay-ms'),
        ];
    }

    /** @param array<string,mixed> $receipt */
    private function writeReceipt(array $receipt): void
    {
        $path = trim((string) $this->option('receipt'));
        if ($path === '') {
            return;
        }
        if (! str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new RuntimeException('--receipt must be an absolute safe path.');
        }
        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new RuntimeException('Receipt output directory must already exist and be writable.');
        }
        $json = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";
        if (file_put_contents($path, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write receipt file.');
        }
    }
}
