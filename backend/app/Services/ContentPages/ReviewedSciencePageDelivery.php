<?php

declare(strict_types=1);

namespace App\Services\ContentPages;

use App\Filament\Ops\Support\ContentReleaseAudit;
use App\Models\ContentPage;
use App\Models\ContentReleaseSnapshot;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\Storage\ContentReleaseSnapshotCatalogService;
use DomainException;
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
            foreach (['zh-CN', 'en'] as $locale) {
                $this->operation($locale, 'stage', $path, false, '', $actor, $binding['environment']);
            }
            // Publish the source first; the English draft then binds its actual new hash.
            foreach (['zh-CN', 'en'] as $locale) {
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
