<?php

declare(strict_types=1);

namespace App\Services\ContentPages;

use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Models\ContentPage;
use App\Models\ContentReleaseSnapshot;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\Storage\ContentReleaseSnapshotCatalogService;
use DomainException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Fixed bilingual item-design-notes delivery; not a general publication API.
 * @review-surface content_page
 * @review-surface cms_translation_revision
 */
final readonly class ReviewedSciencePageDelivery
{
    public const PACKAGE = 'content_assets/science_pages/reviewed-existing/20261108-item-design-notes.json';

    public const SHA = 'ca8194ac16d6adf795fedee16444fff637bd846b448b90053ab8fc5280edb88b';

    private const PACK = 'reviewed-science-item-design-notes-20261108';

    public function __construct(private ReviewedSciencePageRevision $revisions, private ContentReleaseSnapshotCatalogService $snapshots) {}

    /** Metadata-only observation after a failed operation; never a publication preflight or authority. */
    public function bindingDiagnostics(): array
    {
        $result = [];
        $pair = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)->where('slug', 'item-design-notes')
            ->whereIn('locale', ['zh-CN', 'en'])->get()->keyBy('locale');
        $zh = $pair->get('zh-CN');
        $en = $pair->get('en');
        $sameGroup = $zh && $en ? filled($zh->translation_group_id) && $zh->translation_group_id === $en->translation_group_id : 'not_evaluated';
        $exactPair = 'not_evaluated';
        if ($zh && $en) {
            $ids = $sameGroup === true ? ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)
                ->where('translation_group_id', $zh->translation_group_id)->orderBy('id')->limit(3)->pluck('id')->all() : [];
            $expected = [$zh->id, $en->id];
            sort($expected);
            $exactPair = $ids === $expected;
        }
        $status = static fn (?string $value): string => in_array($value, ['source', 'published', 'draft', 'machine_draft', 'human_review', 'approved', 'stale', 'archived'], true) ? $value : 'other';
        $topology = static function (ContentPage $page): string {
            if (! filled($page->source_locale)) {
                return 'invalid';
            }
            if ($page->source_content_id === null) {
                return $page->source_locale === $page->locale ? 'source' : 'unlinked_translation';
            }

            return 'linked_translation';
        };
        foreach (['zh-CN', 'en'] as $locale) {
            $page = $pair->get($locale);
            if (! $page instanceof ContentPage) {
                $result[$locale] = ['page_present' => false];

                continue;
            }
            $revision = $page->workingRevision;
            $source = $page->source_content_id === null ? null : ContentPage::query()->withoutGlobalScopes()
                ->where('org_id', 0)->whereKey($page->source_content_id)->first();
            $identity = $revision !== null && $revision->content_type === 'content_page'
                && (int) $revision->content_id === (int) $page->id && (int) $revision->org_id === 0
                && $revision->locale === $page->locale && $revision->translation_group_id === $page->translation_group_id;
            $result[$locale] = [
                'page_present' => true,
                'topology' => $topology($page),
                'translation_status' => $status($page->translation_status),
                'revision_status' => $status($revision?->revision_status),
                'source_locale_is_zh_cn' => $page->source_locale === 'zh-CN',
                'pair_group_matches' => $sameGroup,
                'pair_group_exact' => $exactPair,
                'row_source_hash_valid' => preg_match('/\A[a-f0-9]{64}\z/', (string) $page->source_version_hash) === 1,
                'row_provenance_absent' => ! filled($page->translated_from_version_hash),
                'revision_provenance_absent' => $revision === null ? 'not_evaluated' : ! filled($revision->translated_from_version_hash),
                'working_equals_published' => filled($page->working_revision_id) && $page->working_revision_id === $page->published_revision_id,
                'revision_identity_valid' => $identity,
                'source_id_present' => $page->source_content_id !== null,
                'source_found_in_org0' => $source !== null,
                'source_topology_valid' => $source === null ? 'not_evaluated' : $topology($source) === 'source',
                'source_status_is_source' => $source === null ? 'not_evaluated' : $source->translation_status === 'source',
                'source_published' => $source === null ? 'not_evaluated' : $source->status === 'published',
                'source_public' => $source === null ? 'not_evaluated' : (bool) $source->is_public,
                'source_slug_matches' => $source === null ? 'not_evaluated' : $source->slug === $page->slug,
                'source_locale_matches' => $source === null ? 'not_evaluated' : $source->locale === $page->source_locale,
                'source_group_matches' => $source === null ? 'not_evaluated' : $source->translation_group_id === $page->translation_group_id,
                'source_hash_present' => $source === null ? 'not_evaluated' : filled($source->source_version_hash),
                'revision_source_id_matches' => $revision === null ? 'not_evaluated' : $revision->source_content_id === $page->source_content_id,
                'revision_source_locale_matches' => $revision === null ? 'not_evaluated' : $revision->source_locale === $page->source_locale,
                'provenance_hash_matches' => $source === null || $revision === null ? 'not_evaluated'
                    : filled($source->source_version_hash) && $revision->translated_from_version_hash === $source->source_version_hash,
            ];
        }

        return ['capture' => 'after_failure_recovery', 'pages' => $result];
    }

    public function publish(array $binding): array
    {
        $this->assertBinding($binding);
        if ($this->snapshot($binding) !== null) {
            throw new DomainException('science_delivery_already_claimed');
        }
        $path = base_path(self::PACKAGE);
        $actor = (int) config('review_governance.solo_owner_admin_user_id');
        $snapshot = DB::transaction(function () use ($binding, $path, $actor): ContentReleaseSnapshot {
            $before = $this->pages(true);
            // Both rows are locked and the whole operation is atomic. Validate the
            // English candidate against the newly published source, not a legacy hash.
            foreach (['zh-CN', 'en'] as $locale) {
                if ($locale === 'en') {
                    $this->linkUnambiguousLegacyTarget();
                }
                foreach (['stage', 'review', 'publish'] as $phase) {
                    $preflight = $this->operation($locale, $phase, $path, false, '', $actor, $binding['environment']);
                    $this->operation($locale, $phase, $path, true, $preflight['state_sha256'], $actor, $binding['environment']);
                }
            }
            $after = $this->pages(true);

            return $this->snapshots->recordSnapshot([
                'pack_id' => self::PACK, 'pack_version' => self::SHA,
                'reason' => 'reviewed_science_bilingual_revision', 'created_by' => 'existing-deploy-workflow',
                'meta_json' => ['binding' => $binding, 'before' => $before, 'after' => $after],
            ]);
        });
        $this->clearCache();

        return ['published_count' => 2, 'snapshot_id' => $snapshot->id];
    }

    private function linkUnambiguousLegacyTarget(): void
    {
        $source = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)->where('slug', 'item-design-notes')->where('locale', 'zh-CN')->firstOrFail();
        $target = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)->where('slug', 'item-design-notes')->where('locale', 'en')->firstOrFail();
        if ($target->source_content_id !== null || $target->source_locale !== 'zh-CN') {
            return;
        }
        // The existing native command owns eligibility, exact row/hash locks,
        // revision identity, audit and the refusal to attest unknown provenance.
        $options = ['--source-id' => $source->id, '--target-id' => $target->id,
            '--group-id' => $source->translation_group_id, '--slug' => 'item-design-notes',
            '--source-hash' => $source->source_version_hash, '--target-hash' => $target->source_version_hash,
            '--revision-id' => $target->published_revision_id,
            '--source-updated-at' => $source->updated_at?->toDateTimeString(),
            '--target-updated-at' => $target->updated_at?->toDateTimeString(), '--json' => true];
        foreach ([false, true] as $execute) {
            $mode = $execute ? ['--execute' => true, '--confirm' => sprintf('Link content page target %d to source %d in group %s.', $target->id, $source->id, $source->translation_group_id)] : ['--dry-run' => true];
            $exit = Artisan::call('translation:link-content-page-source', [...$options, ...$mode]);
            $result = json_decode(Artisan::output(), true);
            if ($exit !== 0 || ! is_array($result) || ($result['ok'] ?? false) !== true) {
                $code = $result['errors'][0] ?? '';
                throw new DomainException('science_initial_link_refused'.(is_string($code) && preg_match('/\A[a-z_]{1,80}\z/', $code) === 1 ? ':'.$code : ''));
            }
        }
    }

    public function recover(array $binding): array
    {
        $this->assertBinding($binding);
        $snapshot = $this->snapshot($binding);
        if ($snapshot === null) {
            return ['restored' => false, 'recovery_status' => 'not_required'];
        }
        $restored = DB::transaction(function () use ($snapshot): bool {
            $meta = $snapshot->meta_json;
            $current = $this->pages(true);
            if ($this->hash($current) === $this->hash($meta['before'])) {
                return false;
            }
            if ($this->hash($current) !== $this->hash($meta['after'])) {
                throw new DomainException('science_delivery_restore_foreign_state');
            }
            foreach ($meta['before'] as $attributes) {
                $page = ContentPage::query()->withoutGlobalScopes()->findOrFail($attributes['id']);
                $page->timestamps = false;
                $page->setRawAttributes($attributes);
                $page->saveQuietly();
                ContentReleaseAudit::log('content_page', $page, 'reviewed_science_revision_restored', false);
            }
            if ($this->hash($this->pages(true)) !== $this->hash($meta['before'])) {
                throw new DomainException('science_delivery_restore_readback_failed');
            }

            return true;
        });
        $this->clearCache();

        return ['restored' => $restored, 'recovery_status' => $restored ? 'restored' : 'not_required'];
    }

    private function operation(string $locale, string $phase, string $path, bool $execute, string $state, int $actor, string $environment): array
    {
        $result = $this->revisions->run($locale, ['item-design-notes'], $phase, $path, self::SHA, $execute, $state, $actor, $environment, false);
        if (($result['ok'] ?? false) !== true) {
            throw new DomainException($result['errors'][0]['code'] ?? 'science_delivery_phase_failed');
        }

        return $result;
    }

    private function pages(bool $lock): array
    {
        $pages = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)->where('slug', 'item-design-notes')
            ->whereIn('locale', ['zh-CN', 'en'])->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        if ($pages->count() !== 2 || $pages->pluck('locale')->sort()->values()->all() !== ['en', 'zh-CN']) {
            throw new DomainException('science_delivery_pair_missing');
        }

        return $pages->map(fn (ContentPage $page): array => $page->getAttributes())->all();
    }

    private function snapshot(array $binding): ?ContentReleaseSnapshot
    {
        return ContentReleaseSnapshot::query()->where('pack_id', self::PACK)->orderByDesc('id')->get()
            ->first(fn ($row): bool => $this->hash($row->meta_json['binding'] ?? []) === $this->hash($binding));
    }

    private function clearCache(): void
    {
        foreach (['zh-CN', 'en'] as $locale) {
            $key = 'content_page:v1:0:item-design-notes:'.$locale;
            Cache::forget($key);
        }
    }

    private function assertBinding(array $binding): void
    {
        if (array_keys($binding) !== ['source_commit', 'workflow_run_id', 'workflow_run_attempt', 'environment', 'package_sha256']
            || ! preg_match('/\A[a-f0-9]{40}\z/', $binding['source_commit'])
            || ! preg_match('/\A[1-9][0-9]{0,19}\z/', $binding['workflow_run_id'])
            || $binding['workflow_run_attempt'] !== 1 || ! in_array($binding['environment'], ['staging', 'production'], true)
            || $binding['package_sha256'] !== self::SHA) {
            throw new DomainException('science_delivery_binding_invalid');
        }
    }

    private function hash(array $value): string
    {
        return hash('sha256', PromotionContextFactory::canonicalJson($value));
    }
}
