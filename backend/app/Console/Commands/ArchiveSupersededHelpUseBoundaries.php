<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentPage;
use App\Services\Audit\AuditLogger;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** @review-surface content_page */
final class ArchiveSupersededHelpUseBoundaries extends Command
{
    protected $signature = 'content-pages:archive-superseded-use-boundaries
        {--execute : Archive the two exact, already unavailable records}';

    protected $description = 'Archive the historical bilingual use-boundaries pages superseded by the public method-boundaries pages.';

    private const ROWS = [
        39 => ['zh-CN', 33, 'dc71d8d6df55857b336e9d205cc5f5792a8c2365696125424c704c1fd81fa373'],
        40 => ['en', 39, '72e1844568517ffe125cbbf1ab61008297970db5b1449a499e3f933fe8dd2aef'],
    ];

    public function handle(AuditLogger $audit): int
    {
        try {
            $result = DB::transaction(fn (): string => $this->archive($audit, (bool) $this->option('execute')));
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line($result);

        return self::SUCCESS;
    }

    private function archive(AuditLogger $audit, bool $execute): string
    {
        $rows = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)
            ->where('translation_group_id', 'content-page-help-use-boundaries')
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $replacements = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)
            ->where('slug', 'method-boundaries')->whereIn('locale', ['en', 'zh-CN'])
            ->orderBy('id')->lockForUpdate()->get()->keyBy('locale');
        if ($rows->keys()->all() !== array_keys(self::ROWS) || $replacements->count() !== 2) {
            throw new DomainException('Use-boundaries group or its replacements changed.');
        }

        foreach (self::ROWS as $id => [$locale, $revisionId, $hash]) {
            $page = $rows[$id];
            $replacement = $replacements[$locale];
            if ($page->slug !== 'help-use-boundaries' || $page->path !== '/help/use-boundaries' || $page->locale !== $locale) {
                throw new DomainException('Use-boundaries identity changed for '.$locale.'.');
            }
            if ($page->working_revision_id !== $revisionId || $page->published_revision_id !== $revisionId
                || $page->source_version_hash !== $hash) {
                throw new DomainException('Use-boundaries revision changed for '.$locale.'.');
            }
            if ($page->is_indexable || $page->passesPublicReadinessGate()
                || $replacement->path !== '/method-boundaries' || ! $replacement->passesPublicReadinessGate()) {
                throw new DomainException('Use-boundaries public availability changed for '.$locale.'.');
            }
        }

        if ($rows->every(fn (ContentPage $page): bool => $page->status === ContentPage::STATUS_ARCHIVED && ! $page->is_public)) {
            return 'already_archived';
        }
        if (! $rows->every(fn (ContentPage $page): bool => $page->status === ContentPage::STATUS_PUBLISHED && $page->is_public)) {
            throw new DomainException('Use-boundaries archive state changed.');
        }
        if (! $execute) {
            return 'ready';
        }

        foreach ($rows as $page) {
            $page->forceFill(['status' => ContentPage::STATUS_ARCHIVED, 'is_public' => false])->saveQuietly();
            $audit->log(
                Request::create('/ops/content-pages/archive-superseded-use-boundaries', 'POST'),
                'content_page_superseded_use_boundaries_archived',
                'content_page',
                (string) $page->id,
                meta: [
                    'locale' => $page->locale,
                    'preserved_published_revision_id' => $page->published_revision_id,
                    'replacement_id' => $replacements[$page->locale]->id,
                ],
                reason: 'superseded_unavailable_help_copy',
            );
        }

        return 'archived';
    }
}
