<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Article;
use App\Services\Cms\ArticlePublishService;
use App\Services\SeoIntel\Sources\CurrentPublicUrlAuthoritySource;
use App\Services\SeoIntel\UrlTruth\EffectivePublicUrlEvaluator;
use App\Services\SeoIntel\UrlTruthInventoryRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SeoPlatformUrlTruthCmsCanaryCommand extends Command
{
    protected $signature = 'seo-intel:url-truth-cms-canary {--timeout=45} {--json}';

    protected $description = 'Republish one unchanged public CMS article and prove post-commit URL Truth incremental readback.';

    public function handle(
        ArticlePublishService $publisher,
        CurrentPublicUrlAuthoritySource $authority,
        EffectivePublicUrlEvaluator $evaluator,
    ): int {
        try {
            $article = Article::query()
                ->withoutGlobalScopes()
                ->where('org_id', 0)
                ->publiclySitemapEligible()
                ->where('llms_eligible', true)
                ->whereIn('locale', ['en', 'zh-CN'])
                ->whereNotNull('working_revision_id')
                ->whereNotNull('published_revision_id')
                ->orderBy('id')
                ->firstOrFail();
            $published = $publisher->publishArticle((int) $article->id, 'seo_platform_05_incremental_canary');
            $record = collect($authority->candidates())->first(
                static fn (UrlTruthInventoryRecord $candidate): bool => $candidate->pageEntityType === 'article'
                    && (string) $candidate->entityIdOrSlug === (string) $published->id
                    && $candidate->locale === (string) $published->locale,
            );
            if (! $record instanceof UrlTruthInventoryRecord) {
                throw new \RuntimeException('canary article is absent from effective public authority.');
            }
            $expectedRevision = (string) ($evaluator->evaluate($record)['authority_revision'] ?? '');
            $deadline = microtime(true) + min(55, max(1, (int) $this->option('timeout')));
            $readback = false;
            do {
                $readback = DB::connection((string) config('seo_intel.connection', 'seo_intel'))
                    ->table('seo_url_entities')
                    ->where('page_entity_type', 'article')
                    ->where('entity_id_or_slug', (string) $published->id)
                    ->where('locale', (string) $published->locale)
                    ->where('authority_revision', $expectedRevision)
                    ->where('binding_status', 'current')
                    ->whereNotNull('current_binding_key')
                    ->count() === 1;
                if (! $readback) {
                    usleep(500000);
                }
            } while (! $readback && microtime(true) < $deadline);

            $receipt = [
                'schema_version' => 'seo-platform-url-truth-cms-canary.v1',
                'status' => $readback ? 'success' : 'blocked',
                'change' => 'authority_revision',
                'cms_publish_service_used' => true,
                'post_commit_event_path' => true,
                'url_truth_readback' => $readback,
                'identity_hash' => hash('sha256', 'article|'.$published->locale.'|'.$published->id),
                'revision_hash' => $expectedRevision,
                'boundaries' => [
                    'content_body_changed' => false,
                    'sitemap_authority_mutation_attempted' => false,
                    'search_submission_allowed' => false,
                    'raw_url_output' => false,
                ],
            ];
        } catch (Throwable) {
            $receipt = [
                'schema_version' => 'seo-platform-url-truth-cms-canary.v1',
                'status' => 'blocked',
                'url_truth_readback' => false,
                'boundaries' => ['search_submission_allowed' => false, 'raw_error_output' => false],
            ];
        }

        $this->line((string) json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $receipt['status'] === 'success' ? self::SUCCESS : self::FAILURE;
    }
}
