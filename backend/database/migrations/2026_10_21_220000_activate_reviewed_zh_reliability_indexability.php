<?php

declare(strict_types=1);

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Enable discovery of the existing reviewed methodology page; no content publication. @review-surface content_page */
return new class extends Migration
{
    private const BODY_SHA256 = 'b9a0e3532f5f48f905cf470ed136294f2a59d01d30aba5ecc86d1740ab190e0f';

    public function up(): void
    {
        if (! Schema::hasTable('content_pages')) {
            return;
        }

        $found = DB::transaction(function (): bool {
            $rows = ContentPage::withoutGlobalScopes()->where('org_id', 0)
                ->where('slug', 'reliability-validity')->where('locale', 'zh-CN')->lockForUpdate()->get();
            if ($rows->isEmpty()) {
                return false; // Do not create a page in fresh environments.
            }
            if ($rows->count() !== 1) {
                throw new RuntimeException('Reviewed Chinese reliability page identity is ambiguous.');
            }
            $page = $rows->sole();
            $revision = CmsTranslationRevision::withoutGlobalScopes()->whereKey($page->published_revision_id)->lockForUpdate()->first();
            if ($page->path !== '/reliability-validity' || $page->canonical_path !== '/reliability-validity'
                || $page->page_type !== 'methodology' || ! $page->passesPublicReadinessGate()
                || ! $page->published_at || $page->published_at->isFuture()
                || ! hash_equals(self::BODY_SHA256, hash('sha256', (string) $page->content_md))
                || ! $page->published_revision_id || $page->working_revision_id !== $page->published_revision_id
                || ! $revision || (int) $revision->org_id !== 0 || $revision->content_type !== 'content_page'
                || (int) $revision->content_id !== (int) $page->id || $revision->locale !== 'zh-CN'
                || $revision->revision_status !== 'published' || ! $revision->approved_at || ! $revision->published_at) {
                throw new RuntimeException('Chinese reliability page is not the reviewed current published version.');
            }

            if (! $page->is_indexable) {
                // Indexability is not part of the ContentPage copy/version hash.
                // Preserve copy, approvals, draft/published pointers and timestamps.
                DB::table('content_pages')->where('id', $page->id)->where('org_id', 0)->where('locale', 'zh-CN')
                    ->update(['is_indexable' => true]);
                app(AuditLogger::class)->log(
                    Request::create('/ops/content-pages/reliability-validity/indexability', 'POST'),
                    'reviewed_zh_reliability_indexability_activated', 'content_page', (string) $page->id,
                    meta: ['locale' => 'zh-CN', 'slug' => 'reliability-validity',
                        'published_revision_id' => $page->published_revision_id,
                        'before' => ['is_indexable' => false], 'changed_fields' => ['is_indexable']],
                    reason: 'activate_existing_reviewed_zh_reliability_page',
                );
            }

            return true;
        });

        // Invalidate just this public API projection after commit; an idempotent
        // invocation also repairs a prior post-commit cache failure.
        if ($found) {
            Cache::forget('content_page:v1:0:reliability-validity:zh-CN');
        }
    }

    public function down(): void
    {
        // Old application code supports this flag; rollback must not withdraw reviewed content.
    }
};
