<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\CareerGuide;
use App\Models\ContentPage;
use App\Models\TopicProfile;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class TraditionalCmsIndexRelease
{
    public const FILE = 'traditional-cms-index.json';

    public const SCIENCE_FILE = 'science-cms-index.json';

    private const SCIENCE_SLUGS = ['common-misconceptions', 'item-design-notes', 'science'];

    public const SCHEMA = 'fermatmind.traditional_cms_index.v1';

    private const LOCALES = ['en', 'zh-CN', 'zh-TW'];

    private const MODELS = [
        'guide' => CareerGuide::class,
        'page' => ContentPage::class,
        'profile' => TopicProfile::class,
    ];

    public static function packageDigest(string $bytes, string $file = self::FILE): string
    {
        return hash('sha256', self::SCHEMA."\n".$file."\n".hash('sha256', $bytes)."\n");
    }

    public static function mutationCounts(PromotionContext $context): ?array
    {
        if ($context->lane !== 'W3' || $context->subscope !== Adapters\PublicContent53PromotionAdapter::SUBSCOPE
            || realpath($context->packageDirectory) !== realpath(base_path(PublicContent53Package::PACKAGE))) {
            return null;
        }
        foreach ([self::FILE, self::SCIENCE_FILE] as $name) {
            $file = $context->packageDirectory.'/'.$name;
            if (! is_file($file) || is_link($file)) {
                continue;
            }
            $bytes = file_get_contents($file);
            if ($bytes === false || ! hash_equals($context->packageSha256, self::packageDigest($bytes, $name))) {
                continue;
            }
            $count = count(self::readPackage($bytes, PublicContent53Package::environment(), $name));
            if ($count !== $context->expectedRowCount) {
                return null;
            }

            return [
                'indexability_mutation_count' => $count,
                'sitemap_mutation_count' => $count + ($name === self::SCIENCE_FILE ? 0 : 2),
            ];
        }

        return null;
    }

    public static function readPackage(string $bytes, string $environment, string $file = self::FILE): array
    {
        $package = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        $rows = $package['candidates'] ?? null;
        $science = $file === self::SCIENCE_FILE;
        if (($package['schema'] ?? null) !== self::SCHEMA || ! is_array($rows) || ! array_is_list($rows)
            || ! preg_match('/^[a-f0-9]{40}$/D', $package['frontend_revision'] ?? '')
            || count($rows) !== ($science ? 3 : 33) || ! in_array($environment, ['staging', 'production'], true)) {
            throw new DomainException('public_content_53_traditional_cms_manifest_invalid');
        }
        $candidates = [];
        foreach ($rows as $row) {
            $kind = $row['kind'] ?? '';
            $slug = $row['slug'] ?? '';
            $id = 'TRADITIONAL-INDEX-'.$kind.'-'.$slug;
            if ($science && ($kind !== 'page' || ! in_array($slug, self::SCIENCE_SLUGS, true))) {
                throw new DomainException('public_content_53_science_cms_index_scope');
            }
            if (! isset(self::MODELS[$kind]) || ! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)
                || isset($candidates[$id]) || array_keys($row['before'] ?? []) !== ['staging', 'production']) {
                throw new DomainException('public_content_53_traditional_cms_identity_invalid');
            }
            foreach ($row['before'] as $scope) {
                if (array_keys($scope) !== self::LOCALES || array_filter($scope, static fn ($hash) => ! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/D', $hash))) {
                    throw new DomainException('public_content_53_traditional_cms_hash_invalid');
                }
            }
            $candidates[$id] = [
                'page_id' => $id,
                'kind' => $kind,
                'identity' => ['org_id' => 0, 'slug' => $slug, 'locale' => 'zh-TW'],
                'traditional_cms_index' => $row['before'][$environment],
                'frontend_revision' => $package['frontend_revision'],
            ];
            if ($science) {
                $candidates[$id]['science_cms_index'] = true;
                $source = $candidates[$id];
                $source['page_id'] = $id.'-zh-CN';
                $source['identity']['locale'] = 'zh-CN';
                $candidates[$source['page_id']] = $source;
            }
        }

        return array_values($candidates);
    }

    public function read(array $row, bool $lock): array
    {
        if ($lock && DB::transactionLevel() === 0) {
            throw new DomainException('public_content_53_traditional_cms_lock_required');
        }
        $kind = $row['kind'];
        $modelClass = self::MODELS[$kind];
        $query = $modelClass::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('slug', $row['identity']['slug'])
            ->whereIn('locale', self::LOCALES)
            ->orderBy('locale');
        if ($kind === 'page') {
            $query->publiclyReadable();
        } else {
            $query->publishedPublic()->whereNull('scheduled_at');
        }
        $members = ($lock ? $query->lockForUpdate() : $query)->get();
        $byLocale = $members->keyBy('locale');
        if ($members->count() !== 3 || $byLocale->count() !== 3) {
            throw new DomainException('public_content_53_traditional_cms_peers_missing');
        }
        $traditional = $byLocale->get('zh-TW');
        $source = $byLocale->get('zh-CN');
        $target = $byLocale->get($row['identity']['locale']);
        $science = $row['science_cms_index'] ?? false;
        foreach ($byLocale as $locale => $member) {
            if ($member->published_at?->isFuture() || (($locale === 'en' || (! $science && $locale === 'zh-CN')) && ! $member->is_indexable)
                || ! hash_equals($row['traditional_cms_index'][$locale], $this->fingerprint($member, $lock))) {
                throw new DomainException('public_content_53_traditional_cms_prestate_drift');
            }
        }
        if ($kind === 'page') {
            if ($traditional->review_state !== 'approved' || ! $traditional->published_revision_id
                || $traditional->canonical_path !== '/zh-TW/'.$row['identity']['slug']
                || $traditional->translation_group_id !== $source->translation_group_id || (int) $traditional->source_content_id !== (int) $source->id
                || $traditional->legal_review_required || $traditional->science_review_required) {
                throw new DomainException('public_content_53_traditional_cms_page_authority');
            }
        } else {
            $key = $kind === 'guide' ? 'guide_code' : 'topic_code';
            if (! $target->$key || $byLocale->pluck($key)->unique()->count() !== 1) {
                throw new DomainException('public_content_53_traditional_cms_translation_mismatch');
            }
        }
        $seoQuery = $kind === 'page' ? null : $target->seoMeta();
        $seo = $seoQuery === null ? null : ($lock ? $seoQuery->lockForUpdate() : $seoQuery)->firstOrFail();

        return [
            'table' => $target->getTable(),
            'id' => (int) $target->id,
            // The reviewed native source for legacy Science pages can predate CMS revisions.
            'revision' => $kind === 'guide' ? (int) $target->revisions()->value('id') : (int) $target->published_revision_id,
            ...($science && $target === $source ? ['reviewed_legacy_source' => true] : []),
            'indexed' => (bool) $target->is_indexable,
            'seo_id' => $seo?->id,
            'robots' => $seo?->robots,
        ];
    }

    private function fingerprint(Model $member, bool $lock): string
    {
        $body = match (true) {
            $member instanceof CareerGuide => (string) $member->body_md,
            $member instanceof ContentPage => (string) $member->content_md,
            default => $this->topicSections($member, $lock),
        };

        return hash('sha256', json_encode([(string) $member->title, $body], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function topicSections(TopicProfile $profile, bool $lock): array
    {
        // The reviewed fingerprint covers the sections exposed by the public Topic API.
        $sections = $profile->sections()->where('is_enabled', true)->reorder('section_key');
        if ($lock) {
            $sections->lockForUpdate();
        }

        return $sections->get()->map(static fn ($section): array => [$section->section_key, $section->title, $section->body_md])->all();
    }

    public function assertHeld(array $state): void
    {
        if ($state['indexed'] || ($state['revision'] < 1 && ! ($state['reviewed_legacy_source'] ?? false))
            || ($state['seo_id'] !== null && ! in_array($state['robots'], ['noindex,follow', null], true))) {
            throw new DomainException('public_content_53_traditional_cms_hold_drift');
        }
    }

    public function assertActive(array $state): void
    {
        if (! $state['indexed'] || ($state['seo_id'] !== null && $state['robots'] !== 'index,follow')) {
            throw new DomainException('public_content_53_traditional_cms_readback_drift');
        }
    }

    public function write(array $row, array $state, bool $activate): void
    {
        $class = self::MODELS[$row['kind']];
        $target = $class::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($state['id']);
        if ($state['seo_id'] !== null) {
            $target->seoMeta()->whereKey($state['seo_id'])->firstOrFail()
                ->forceFill(['robots' => $activate ? 'index,follow' : $state['robots']])->saveQuietly();
        }
        $target->forceFill(['is_indexable' => $activate])->save();
    }
}
