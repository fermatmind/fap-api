<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\Audit\AuditLogger;
use App\Support\CanonicalTranslationPayloadHash;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** @review-surface content_page */
final class ArchiveSupersededEnglishContentPageDrafts extends Command
{
    protected $signature = 'translation:archive-reviewed-content-page-drafts
        {--execute : Apply only the ten exact reviewed archive transitions}
        {--json : Emit metadata-only JSON}';

    protected $description = 'Restore the live revision in the editor for the ten reviewed historical English content page drafts.';

    private const TARGETS = [
        9 => [15, '/help/faq', 106, 158,
            'eac9f56a372ecf1936aeb906475c8da5f422aa79ff2a958409992a632f40dd91',
            '178c9856700a2a43fa65d86afd72bfa174b67fc2c695953e075578fea870d5c0'],
        10 => [16, '/help/about', 104, 167,
            '32f1d539a5822de7771f2a5b45c614f37a1af654b2e87cb0fd138844c812100f',
            '54f294e1ebc8f4efd553d840be0d9c06e72a7568950a2ad55f91f239db654cf6'],
        11 => [17, '/help/team', 108, 168,
            '3ff8223f1ace69be4a55920b5e0948a0ee8ef6b451f57636410f9f24cea103e3',
            '77a79f6f1998a44007ecfbf21e627b38c6b4a9022e8bc30aa76c94b2ab0e23a4'],
        12 => [18, '/help/used-and-mentioned', 109, 169,
            '3cee948e629d04cfad96c22cb3e1737faa06b39efbc741f6688f2aeda5e833d6',
            '645c0d3034e34d6da0d48253464e3747592356bd3e7fbc1ab1492ef3206b048c'],
        13 => [19, '/help/for-business-and-research', 107, 159,
            'e610c02eb98d2dc54a24ff399eef1f8349b82a1157e8ebc220cf6fae1c4b86b7',
            '10ab24f320338c119a8a8cc28d34d0684ca4e87875d84d0d8f86fd732844699a'],
        21 => [1, '/about', 113, 150,
            '11701f84d2fc6c2f7dc7026b208b8bb499be21df97caaaf41c9af69692a2820c',
            '6fa5669b7a61b046a096196ca9faec998803c34c701a30b166459a87dc15b940'],
        22 => [7, '/privacy', 114, 163,
            'fb5ce5d2babbb2e0672c7b44e3691fb6fc1942a5e2f3613fe081cfde0e33bec3',
            'f9b1b9d92acb1250d0f44a68c7a706ea72530c9385ee40df353b7b43904c589e'],
        23 => [6, '/terms', 115, 166,
            '1276165a13f1b77505e543619a97e947c66e74ff3dd94624d248cac236d26de3',
            '85cdff2fae2e7e0b474c69a196fd50d6341ae3b95326dffdd3e2abb193799da8'],
        24 => [25, '/method-boundaries', 76, 161,
            '76f0e8a8a4511970f9d63afdea83a7331bc4159c4de8f3dce8458339ddcee935',
            'bd4c9928ef27e92230521e70475ccf6b6223cc72c71c9a9cf9bbf50d5cf12d4e'],
        29 => [4, '/careers', 78, 152,
            '011ca30a3a6f1b97181a89cbe9cac1b8930b16cee090efec559f57c39dd2d313',
            '3a0907f1c87a43dc8da0f5a3ace0aa8ea55848832707a85b9f8d038fa04f881c'],
    ];

    public function handle(AuditLogger $audit): int
    {
        $execute = (bool) $this->option('execute');
        $results = [];
        try {
            DB::transaction(function () use ($audit, $execute, &$results): void {
                foreach (self::TARGETS as $pageId => [$sourceId, $path, $draftId, $publishedId, $draftHash, $publishedHash]) {
                    $page = ContentPage::withoutGlobalScopes()->whereKey($pageId)
                        ->where('org_id', 0)->where('locale', 'en')->where('path', $path)
                        ->where('source_content_id', $sourceId)->where('status', ContentPage::STATUS_PUBLISHED)
                        ->where('is_public', true)->where('review_state', 'approved')->where('published_revision_id', $publishedId)
                        ->lockForUpdate()->first();
                    if (! $page || ! $page->passesPublicReadinessGate()) {
                        throw new RuntimeException('Target identity or publication changed: '.$pageId);
                    }

                    $revisions = CmsTranslationRevision::withoutGlobalScopes()
                        ->where('org_id', 0)->where('content_type', 'content_page')->where('content_id', $pageId)
                        ->where('locale', 'en')->where('source_content_id', $sourceId)
                        ->where('translation_group_id', $page->translation_group_id)
                        ->whereIn('id', [$draftId, $publishedId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    $draft = $revisions->get($draftId);
                    $published = $revisions->get($publishedId);
                    if (! $draft || ! $published || $draft->approved_at || $draft->published_at
                        || $published->revision_status !== CmsTranslationRevision::STATUS_PUBLISHED
                        || ! $published->published_at || $draft->revision_number >= $published->revision_number
                        || ! hash_equals($draftHash, CanonicalTranslationPayloadHash::hash($draft->payload_json))
                        || ! hash_equals($publishedHash, CanonicalTranslationPayloadHash::hash($published->payload_json))
                        || (string) $page->content_md !== (string) ($published->payload_json['body_md'] ?? '')
                        || ! filled($page->translated_from_version_hash)
                        || $page->translated_from_version_hash !== $published->translated_from_version_hash) {
                        throw new RuntimeException('Revision identity, content or provenance changed: '.$pageId);
                    }

                    if ((int) $page->working_revision_id === $publishedId
                        && $page->translation_status === ContentPage::TRANSLATION_STATUS_PUBLISHED
                        && $draft->revision_status === CmsTranslationRevision::STATUS_ARCHIVED && $draft->archived_at) {
                        $results[$pageId] = 'already_archived';

                        continue;
                    }

                    if ((int) $page->working_revision_id !== $draftId
                        || $page->translation_status !== ContentPage::TRANSLATION_STATUS_DRAFT
                        || $draft->revision_status !== CmsTranslationRevision::STATUS_DRAFT || $draft->archived_at) {
                        throw new RuntimeException('Working revision state changed: '.$pageId);
                    }
                    if ($execute) {
                        $this->archive($audit, $page, $draft, $publishedId);
                    }
                    $results[$pageId] = $execute ? 'archived' : 'ready';
                }
            });
        } catch (RuntimeException $exception) {
            return $this->report(false, $execute, [], $exception->getMessage());
        }

        return $this->report(true, $execute, $results);
    }

    private function archive(AuditLogger $audit, ContentPage $page, CmsTranslationRevision $draft, int $publishedId): void
    {
        $draft->forceFill([
            'revision_status' => CmsTranslationRevision::STATUS_ARCHIVED,
            'archived_at' => now(),
        ])->saveQuietly();
        ContentPage::withoutTimestamps(fn () => $page->forceFill([
            'working_revision_id' => $publishedId,
            'translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED,
        ])->saveQuietly());

        $audit->log(
            Request::create('/ops/content-pages/revisions/archive-superseded-draft', 'POST'),
            'content_page_superseded_translation_draft_archived',
            'content_page',
            (string) $page->id,
            meta: ['actor' => 'controlled_ops_command', 'archived_revision_id' => $draft->id, 'published_revision_id' => $publishedId],
            reason: 'exact_reviewed_historical_draft_cleanup',
        );
    }

    /** @param array<int,string> $results */
    private function report(bool $ok, bool $execute, array $results, ?string $error = null): int
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => $ok, 'mode' => $execute ? 'execute' : 'preflight',
                'results' => $results, 'error' => $error,
            ], JSON_THROW_ON_ERROR));
        } elseif ($ok) {
            $this->info(count($results).' exact historical working drafts verified.');
        } else {
            $this->error((string) $error);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
