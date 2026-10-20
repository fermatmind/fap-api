<?php

declare(strict_types=1);

namespace App\Filament\Ops\Support;

use App\Filament\Ops\Resources\ArticleResource\Support\ArticleWorkspace;
use App\Models\Article;
use App\Support\ArticleSourceTargetSnapshot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Output\BufferedOutput;

/** @review-surface article */
final class ArticleControlledPublicationWorkspace
{
    public function prepare(Article $selected): array
    {
        $article = $this->selected($selected);
        $hash = ArticleSourceTargetSnapshot::publicationHash($article);
        $plan = $this->run($article, $hash, true, false, true);

        return [
            'target_hash' => $hash,
            'preflight' => json_encode([
                'article_id' => (int) $article->id,
                'locale' => $article->locale,
                'working_revision_id' => $article->working_revision_id,
                'published_revision_id' => $article->published_revision_id,
                'preview_url' => ArticleWorkspace::previewUrl($article, (int) $article->working_revision_id),
                'ok' => $plan['ok'],
                'errors' => $plan['errors'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'preview_approved' => false,
            'acknowledge_claims' => false,
            'make_indexable' => false,
        ];
    }

    public function publish(Article $selected, array $data): array
    {
        $article = $this->selected($selected);
        if (($data['preview_approved'] ?? false) !== true) {
            $this->reject('preview_approval_required');
        }
        $hash = (string) ($data['target_hash'] ?? '');
        try {
            ArticleSourceTargetSnapshot::assertPublicationHash($article, $hash);
        } catch (\RuntimeException) {
            $this->reject('article_publication_state_changed');
        }
        $summary = $this->run($article, $hash, (bool) ($data['make_indexable'] ?? false), (bool) ($data['acknowledge_claims'] ?? false), false);
        // A committed promotion with failed cache closeout must never be offered
        // as an uncommitted retry. The native command owns this distinction.
        if (! ($summary['ok'] ?? false) && ($summary['publication'] ?? '') !== 'committed') {
            $codes = array_column((array) ($summary['errors'] ?? []), 'code');
            $this->reject(implode(', ', $codes) ?: 'native_publication_rejected');
        }

        return $summary;
    }

    private function selected(Article $selected): Article
    {
        abort_unless(ContentAccess::canRelease(), 403);
        $guard = (string) config('admin.guard', 'admin');
        abort_if((bool) config('admin.totp.enabled', true)
            && (int) session('ops_admin_totp_verified_user_id', 0) !== (int) auth($guard)->id(), 403);
        $article = Article::withoutGlobalScopes()->whereKey($selected->getKey())->firstOrFail();
        if ((int) $article->org_id !== 0 || ! in_array($article->locale, ['en', 'zh-CN'], true)
            || $article->deleted_at !== null || $article->working_revision_id === null) {
            $this->reject('selected_article_not_supported');
        }
        $article->load(['workingRevision', 'seoMeta']);

        return $article;
    }

    private function run(Article $article, string $hash, bool $makeIndexable, bool $acknowledgeClaims, bool $dryRun): array
    {
        $options = ['--expected-article-state-sha256' => $hash, '--json' => true];
        if ($dryRun) {
            $options['--dry-run'] = true;
        }
        if ($article->published_revision_id === null) {
            $command = 'articles:publish-controlled';
            $options['--article'] = [(string) $article->id];
            $options['--revalidate-after-commit'] = true;
            if ($makeIndexable) {
                $options['--make-indexable'] = true;
            }
            if ($acknowledgeClaims) {
                $options['--ack-claim-warning'] = [(string) $article->id];
            }
            if (! $dryRun) {
                $options['--confirm'] = "I explicitly approve Codex to publish article id {$article->id} after preflight passes.";
            }
        } else {
            $command = 'articles:promote-existing-working-revision';
            $options += [
                '--article-id' => (string) $article->id,
                '--working-revision-id' => (string) $article->working_revision_id,
                '--current-published-revision-id' => (string) $article->published_revision_id,
                '--translation-group-id' => (string) $article->translation_group_id,
                '--expected-slug' => (string) $article->slug,
                '--expected-canonical' => (string) $article->seoMeta?->canonical_url,
                '--revalidate-after-commit' => true,
            ];
            if ($acknowledgeClaims) {
                $options['--ack-claim-warning'] = (string) $article->id;
            }
            if (! $dryRun) {
                $options += [
                    '--execute' => true, '--preview-approved' => true,
                    '--schema-hold' => true, '--hreflang-hold' => true,
                    '--search-hold' => true, '--no-sitemap' => true, '--no-llms' => true,
                    '--confirm' => "I explicitly approve Codex to promote article id {$article->id} working revision {$article->working_revision_id} after preflight passes.",
                ];
            }
        }
        $output = new BufferedOutput;
        Artisan::call($command, $options, $output);

        return json_decode(trim($output->fetch()), true, 64, JSON_THROW_ON_ERROR);
    }

    private function reject(string $code): never
    {
        throw ValidationException::withMessages(['preview_approved' => __('ops.resources.articles.controlled_publish.rejected', ['code' => $code])]);
    }
}
