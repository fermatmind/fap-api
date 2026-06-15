<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cms\ArticleSeoGateRollout as RolloutService;
use Illuminate\Console\Command;

final class ArticleSeoGateRollout extends Command
{
    protected $signature = 'articles:seo-gate-rollout
        {--article-ids= : Comma-separated article ids to lock}
        {--translation-group-id= : Expected current translation_group_id for the locked articles}
        {--expected-slugs= : Comma-separated expected slugs in article-id order}
        {--set-translation-group-id= : Optional replacement translation_group_id for identity cleanup}
        {--enable-article-schema : Enable the Article JSON-LD render gate}
        {--enable-breadcrumb-schema : Enable the Breadcrumb JSON-LD render gate}
        {--enable-faq-schema : Enable FAQ schema gate after explicit review}
        {--hold-faq-schema : Explicitly hold FAQ schema gate}
        {--enable-hreflang : Enable hreflang gate after reciprocal validation}
        {--no-hreflang-policy : Record an explicit no-hreflang policy}
        {--hreflang-policy-reason= : Reason for no-hreflang policy}
        {--dry-run : Force dry-run mode}
        {--execute : Execute the bounded write}
        {--json : Emit JSON}
        {--no-publish : Required for execute; confirms no publish action}
        {--no-search : Required for execute; confirms no search submission}
        {--no-sitemap-llms-change : Required for execute; confirms no sitemap/llms mutation}
        {--no-content-change : Required for execute; confirms no editorial content mutation}
        {--no-revalidation : Required for execute; confirms no cache revalidation}';

    protected $description = 'Controlled rollout for article SEO gates with identity locks, JSON-LD guards, hreflang validation, and audit logging.';

    public function handle(RolloutService $rollout): int
    {
        $payload = $rollout->run([
            'article_ids' => $this->option('article-ids'),
            'translation_group_id' => $this->option('translation-group-id'),
            'expected_slugs' => $this->option('expected-slugs'),
            'set_translation_group_id' => $this->option('set-translation-group-id'),
            'enable_article_schema' => (bool) $this->option('enable-article-schema'),
            'enable_breadcrumb_schema' => (bool) $this->option('enable-breadcrumb-schema'),
            'enable_faq_schema' => (bool) $this->option('enable-faq-schema'),
            'hold_faq_schema' => (bool) $this->option('hold-faq-schema'),
            'enable_hreflang' => (bool) $this->option('enable-hreflang'),
            'no_hreflang_policy' => (bool) $this->option('no-hreflang-policy'),
            'hreflang_policy_reason' => $this->option('hreflang-policy-reason'),
            'dry_run' => (bool) $this->option('dry-run'),
            'execute' => (bool) $this->option('execute'),
            'json' => (bool) $this->option('json'),
            'no_publish' => (bool) $this->option('no-publish'),
            'no_search' => (bool) $this->option('no-search'),
            'no_sitemap_llms_change' => (bool) $this->option('no-sitemap-llms-change'),
            'no_content_change' => (bool) $this->option('no-content-change'),
            'no_revalidation' => (bool) $this->option('no-revalidation'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif ((bool) ($payload['ok'] ?? false)) {
            $this->info((string) ($payload['action'] ?? 'ok'));
        } else {
            $this->error((string) ($payload['action'] ?? 'failed'));
        }

        return (bool) ($payload['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
