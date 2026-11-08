<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion;

use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Services\Cms\ContentPageTranslationAdapter;
use App\Services\Cms\SiblingTranslationWorkflowService;
use App\Support\CanonicalTranslationPayloadHash;
use DomainException;
use Illuminate\Support\Facades\DB;

/** Native ContentPage creation inside the existing exact-package transaction. @review-surface content_page */
final class TraditionalContentPagePublication
{
    public const SCHEMA = 'fermatmind.traditional_content_page.v1';

    public const AUTHORITY = PublicContent53Package::PACKAGE.'/traditional-pages.json';

    public const FIELDS = ['title', 'kicker', 'summary', 'seo_title', 'meta_description', 'seo_description', 'content_md', 'headings', 'faq_items'];

    private const SLUGS = ['about', 'brand', 'charter', 'careers', 'privacy', 'terms', 'method-boundaries', 'science'];

    /** Fixed classifications only: never return SQL, payloads or exception text. */
    public static function failureCode(\Throwable $error): string
    {
        $nativeCodes = [
            'traditional_page_package_invalid', 'traditional_page_identity_or_fields_invalid',
            'traditional_page_text_invalid', 'traditional_page_format_invalid', 'traditional_page_link_targets_changed',
            'traditional_page_protected_tokens_changed', 'traditional_page_structure_changed', 'traditional_page_review_binding_invalid',
            'traditional_page_lock_requires_transaction', 'traditional_page_identity_collision', 'traditional_page_source_drift_or_hold',
            'traditional_page_target_not_owned', 'traditional_page_retained_draft_changed', 'traditional_page_published_target_changed',
            'traditional_page_transaction_required', 'traditional_page_restore_transaction_required',
        ];
        if ($error instanceof DomainException && (in_array($error->getMessage(), $nativeCodes, true)
            || preg_match('/^traditional_page_source_(?:identity|readiness|review|hold|html|version|copy_(?:'.implode('|', self::FIELDS).'))_(?:'.implode('|', self::SLUGS).')$/D', $error->getMessage()))) {
            return 'public_content_53_'.$error->getMessage();
        }
        if ($error instanceof \App\Services\Cms\CmsTranslationWorkflowException) {
            $blockers = [
                'source identity invalid' => 'source_identity_invalid', 'source version mismatch' => 'source_version_mismatch',
                'target identity collision' => 'target_identity_collision', 'source path invalid' => 'source_path_invalid',
                'prepared payload incomplete' => 'payload_incomplete', 'prepared payload invalid' => 'payload_invalid',
                'prepared text invalid' => 'text_invalid', 'prepared structure invalid' => 'structure_invalid',
                'prepared FAQ invalid' => 'faq_invalid', 'source row org mismatch' => 'source_org_mismatch',
                'source row not published' => 'source_not_published', 'source row not approved' => 'source_not_approved',
                'source row not public' => 'source_not_public', 'source row adapter publication check failed' => 'source_not_publishable',
            ];
            foreach ($blockers as $blocker => $code) {
                if (in_array($blocker, $error->blockers(), true)) {
                    return 'public_content_53_prepared_'.$code;
                }
            }
        }
        if ($error instanceof \PDOException) {
            $code = match ((int) ($error->errorInfo[1] ?? 0)) {
                1048, 1364 => 'required_value_missing',
                1062 => 'duplicate_identity',
                1265, 1366 => 'value_invalid',
                1406 => 'value_too_long',
                default => null,
            };
            if ($code !== null) {
                return 'public_content_53_storage_'.$code;
            }
        }

        return 'public_content_53_publication_failed';
    }

    public function __construct(
        private readonly ContentPageTranslationAdapter $adapter,
        private readonly SiblingTranslationWorkflowService $workflow,
    ) {}

    public static function package(string $bytes, string $review, string $sha): array
    {
        $package = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        $proof = json_decode($review, true, 64, JSON_THROW_ON_ERROR);
        $rows = $package['candidates'] ?? [];
        if (($package['schema'] ?? null) !== self::SCHEMA || count($rows) < 1 || count($rows) > count(self::SLUGS)
            || ($proof['schema'] ?? null) !== self::SCHEMA.'.review'
            || ($proof['candidate_sha256'] ?? null) !== hash('sha256', $bytes)
            || ($proof['verdict'] ?? null) !== 'PASS' || count($proof['pages'] ?? []) !== count($rows)) {
            throw new DomainException('traditional_page_package_invalid');
        }
        $seen = [];
        foreach ($rows as $row) {
            $slug = $row['identity']['slug'] ?? null;
            $keys = array_keys($row);
            sort($keys);
            if ($keys !== ['before', 'identity', 'kind', 'new_traditional_page', 'page_id', 'snapshot', 'source_snapshot', 'staging_before']
                || ! in_array($slug, self::SLUGS, true) || isset($seen[$slug])
                || $row['kind'] !== 'page' || $row['new_traditional_page'] !== true || $row['page_id'] !== 'TRADITIONAL-'.$slug
                || $row['identity'] !== ['org_id' => 0, 'slug' => $slug, 'locale' => 'zh-TW']
                || $row['before'] !== [] || $row['staging_before'] !== []
                || array_diff(array_keys($row['source_snapshot']), self::FIELDS) !== []
                || array_diff(self::FIELDS, array_keys($row['source_snapshot'])) !== []
                || array_diff(array_keys($row['snapshot']), [...self::FIELDS, 'status', 'is_public', 'is_indexable']) !== []
                || array_diff([...self::FIELDS, 'status', 'is_public', 'is_indexable'], array_keys($row['snapshot'])) !== []
                || $row['snapshot']['status'] !== 'published' || $row['snapshot']['is_public'] !== true
                || $row['snapshot']['is_indexable'] !== false) {
                throw new DomainException('traditional_page_identity_or_fields_invalid');
            }
            $seen[$slug] = true;
            foreach (['source_snapshot', 'snapshot'] as $field) {
                $copy = $row[$field];
                foreach (array_diff(self::FIELDS, ['headings', 'faq_items']) as $text) {
                    if (! is_string($copy[$text]) && $copy[$text] !== null) {
                        throw new DomainException('traditional_page_text_invalid');
                    }
                }
                if (trim((string) $copy['title']) === '' || trim((string) $copy['content_md']) === ''
                    || trim((string) $copy['seo_title']) === '' || trim((string) $copy['meta_description']) === ''
                    || ! is_array($copy['headings']) || ! array_is_list($copy['headings'])
                    || array_filter($copy['headings'], static fn ($heading): bool => ! is_string($heading)) !== [] || $copy['faq_items'] !== []
                    || preg_match('/[<>`]|^\s*~{3,}|^\s*\|/m', $copy['content_md']) === 1) {
                    throw new DomainException('traditional_page_format_invalid');
                }
            }
            if (self::markdownLinkTargets($row['source_snapshot']['content_md']) !== self::markdownLinkTargets($row['snapshot']['content_md'])) {
                throw new DomainException('traditional_page_link_targets_changed');
            }
            foreach (array_diff(self::FIELDS, ['faq_items']) as $field) {
                $sourceText = is_array($row['source_snapshot'][$field]) ? implode("\n", $row['source_snapshot'][$field]) : (string) $row['source_snapshot'][$field];
                $targetText = is_array($row['snapshot'][$field]) ? implode("\n", $row['snapshot'][$field]) : (string) $row['snapshot'][$field];
                $tokens = '~https?://[^\s<>）)]+|(?<![A-Za-z0-9_./:@?=&%#-])/[A-Za-z][A-Za-z0-9-]*(?:[/?#][A-Za-z0-9._\x7e:/?#@!$&\x27*+,;=%-]*)?|\{\{[^}]*\}\}|\$?\{[^}]*\}|%\d*\$?[sdif]|\d+(?:[.,:/-]\d+)*~u';
                preg_match_all($tokens, $sourceText, $sourceTokens);
                preg_match_all($tokens, $targetText, $targetTokens);
                if ($sourceTokens[0] !== $targetTokens[0]) {
                    throw new DomainException('traditional_page_protected_tokens_changed');
                }
            }
            preg_match_all('/^\s*(?:#{1,6}\s+|[-*+]\s+|\d+[.)]\s+)/m', $row['source_snapshot']['content_md'], $sourceStructure);
            preg_match_all('/^\s*(?:#{1,6}\s+|[-*+]\s+|\d+[.)]\s+)/m', $row['snapshot']['content_md'], $targetStructure);
            if ($sourceStructure[0] !== $targetStructure[0] || count($row['source_snapshot']['headings']) !== count($row['snapshot']['headings'])) {
                throw new DomainException('traditional_page_structure_changed');
            }
            $pageProof = $proof['pages'][$row['page_id']] ?? [];
            if (($pageProof['source_sha256'] ?? null) !== self::hash($row['source_snapshot'])
                || ($pageProof['target_sha256'] ?? null) !== self::hash($row['snapshot'])) {
                throw new DomainException('traditional_page_review_binding_invalid');
            }
        }

        return ['candidates' => $rows, 'package_sha256' => $sha];
    }

    private static function markdownLinkTargets(string $text): array
    {
        $targets = [];
        $remaining = preg_replace_callback('~\[([^\[\]\r\n]+)\]\(([^()\s]+)\)~u', static function (array $match) use (&$targets): string {
            $href = $match[2];
            if (! preg_match('~^(?:/(?!/)|\#|https?://|mailto:|tel:)~i', $href)
                || preg_match('/[<>`\\\\\x00-\x1f\x7f]/', $href)) {
                throw new DomainException('traditional_page_format_invalid');
            }
            $targets[] = $href;

            return "\u{E010}";
        }, $text);
        if ($remaining === null || preg_match('/[\[\]]|!\x{E010}/u', $remaining)) {
            throw new DomainException('traditional_page_format_invalid');
        }

        return $targets;
    }

    public function read(array $row, bool $lock): array
    {
        if ($lock && DB::transactionLevel() < 1) {
            throw new DomainException('traditional_page_lock_requires_transaction');
        }
        $source = $this->query(['org_id' => 0, 'slug' => $row['identity']['slug'], 'locale' => 'zh-CN'], $lock)->firstOrFail();
        $targets = ContentPage::query()->withoutGlobalScopes()->where('org_id', 0)->where('locale', 'zh-TW')
            ->where(static function ($query) use ($source): void {
                $query->where('slug', $source->slug)->orWhere('translation_group_id', $source->translation_group_id);
            });
        $targets = ($lock ? $targets->lockForUpdate() : $targets)->get();
        if ($targets->count() > 1) {
            throw new DomainException('traditional_page_identity_collision');
        }
        $target = $targets->first();
        $revisions = $target ? $this->revisions($target->id, $lock) : [];

        return ['table' => 'content_pages', 'traditional_page' => true, 'missing' => $target === null,
            'retained_draft' => $target !== null && $target->status === 'draft' && ! $target->is_public && $target->published_revision_id === null,
            'values' => $target?->getRawOriginal() ?? [], 'revisions' => $revisions, 'relations' => [],
            'source' => $source->getRawOriginal(), 'source_revisions' => $this->revisions($source->id, $lock)];
    }

    public function assertBaseline(array $row, array $saved): void
    {
        $source = $this->model($saved['source']);
        $checks = [
            'identity' => $source->isSourceContent() && $source->locale === 'zh-CN' && (int) $source->org_id === 0,
            'readiness' => $source->passesPublicReadinessGate(),
            'review' => $source->review_state === 'approved',
            'hold' => ! $source->legal_review_required && ! $source->science_review_required,
            'html' => trim((string) $source->content_html) === '',
            'version' => (bool) preg_match('/^[a-f0-9]{64}$/', (string) $source->source_version_hash),
        ];
        $copy = $this->copy($source);
        foreach (self::FIELDS as $field) {
            $checks['copy_'.$field] = self::hash([$copy[$field]]) === self::hash([$row['source_snapshot'][$field]]);
        }
        foreach ($checks as $check => $passed) {
            if (! $passed) {
                throw new DomainException('traditional_page_source_'.$check.'_'.$row['identity']['slug']);
            }
        }
        if ($saved['missing']) {
            return;
        }
        $target = $this->model($saved['values']);
        $revisionId = $target->published_revision_id ?? $target->working_revision_id;
        $revisions = array_values(array_filter($saved['revisions'], static fn ($revision): bool => (int) $revision['id'] === (int) $revisionId));
        if (count($revisions) !== 1 || $target->slug !== $source->slug || $target->locale !== 'zh-TW'
            || (int) $target->source_content_id !== (int) $source->id || $target->source_locale !== 'zh-CN'
            || $target->translation_group_id !== $source->translation_group_id
            || $target->path !== '/zh-TW/'.$source->slug || $target->canonical_path !== '/zh-TW/'.$source->slug
            || $revisions[0]['authority_source_package'] !== self::AUTHORITY
            || (int) $revisions[0]['org_id'] !== 0 || $revisions[0]['locale'] !== 'zh-TW'
            || (int) $revisions[0]['source_content_id'] !== (int) $source->id
            || $revisions[0]['revision_status'] !== CmsTranslationRevision::STATUS_PUBLISHED) {
            throw new DomainException('traditional_page_target_not_owned');
        }
        if ($saved['retained_draft']) {
            $payload = $this->adapter->snapshotPayload($target);
            $publishedPayload = is_string($revisions[0]['payload_json']) ? json_decode($revisions[0]['payload_json'], true, 64, JSON_THROW_ON_ERROR) : $revisions[0]['payload_json'];
            foreach (['is_public', 'is_indexable'] as $field) {
                $payload[$field] = $publishedPayload[$field];
            }
            if ($target->is_indexable || self::hash($payload) !== self::hash($publishedPayload)) {
                throw new DomainException('traditional_page_retained_draft_changed');
            }

            return;
        }
        if (! $target->passesPublicReadinessGate() || $target->is_indexable || $target->review_state !== 'approved'
            || ! $target->publish_allowed || $target->claim_gate_status !== 'passed'
            || ($target->operator_approval_required && ! $target->operator_approved_at)
            || $target->translated_from_version_hash !== $source->source_version_hash
            || self::hash($this->copy($target)) !== self::hash(array_intersect_key($row['snapshot'], array_flip(self::FIELDS)))) {
            throw new DomainException('traditional_page_published_target_changed');
        }
    }

    public function publish(array $row, array $saved, PromotionContext $context): bool
    {
        if (DB::transactionLevel() < 1) {
            throw new DomainException('traditional_page_transaction_required');
        }
        $this->assertBaseline($row, $saved);
        if (! $saved['missing'] && ! $saved['retained_draft']) {
            return false;
        }
        $source = $this->model($saved['source']);
        $fields = array_intersect_key($row['snapshot'], array_flip(self::FIELDS));
        $target = $saved['missing']
            ? $this->workflow->createPreparedContentPageDraft($source->id, $source->source_version_hash, $fields,
                CanonicalTranslationPayloadHash::hash($this->adapter->snapshotPayload($source)))
            : $this->query(['id' => $saved['values']['id']], true)->firstOrFail();
        $payload = $this->adapter->snapshotPayload($target);
        foreach (['title', 'kicker', 'summary', 'seo_title', 'meta_description', 'seo_description', 'faq_items'] as $field) {
            $payload[$field] = $fields[$field];
        }
        $payload['body_md'] = $fields['content_md'];
        $payload['headings_json'] = $fields['headings'];
        $this->adapter->applyRevisionPayload($target, $payload);
        $target->forceFill(['status' => 'published', 'review_state' => 'approved', 'is_public' => true, 'is_indexable' => false,
            'publish_allowed' => true, 'operator_approval_required' => false, 'operator_approved_at' => now(),
            'claim_gate_status' => 'passed', 'translation_status' => ContentPage::TRANSLATION_STATUS_PUBLISHED,
            'published_at' => now(), 'source_updated_at' => $source->source_updated_at, 'effective_at' => $source->effective_at,
            'translated_from_version_hash' => $source->source_version_hash])->save();
        $revision = CmsTranslationRevision::query()->withoutGlobalScopes()->create([
            'org_id' => 0, 'content_type' => 'content_page', 'content_id' => $target->id,
            'source_content_id' => $source->id, 'translation_group_id' => $source->translation_group_id,
            'locale' => 'zh-TW', 'source_locale' => 'zh-CN',
            'revision_number' => ((int) CmsTranslationRevision::query()->withoutGlobalScopes()->where('org_id', 0)->where('content_type', 'content_page')->where('content_id', $target->id)->max('revision_number')) + 1,
            'revision_status' => CmsTranslationRevision::STATUS_PUBLISHED, 'source_version_hash' => $target->source_version_hash,
            'translated_from_version_hash' => $source->source_version_hash, 'payload_json' => $this->adapter->snapshotPayload($target),
            'supersedes_revision_id' => $target->working_revision_id, 'authority_asset_key' => $row['page_id'].':'.$context->sourceCommit,
            'authority_source_package' => self::AUTHORITY, 'authority_source_hash' => self::hash($row['snapshot']),
            'authority_package_sha256' => $context->packageSha256, 'approved_at' => now(), 'published_at' => now(),
        ]);
        $target->forceFill(['working_revision_id' => $revision->id, 'published_revision_id' => $revision->id])->saveQuietly();

        return true;
    }

    public function restore(array $before, array $after): void
    {
        if (DB::transactionLevel() < 1) {
            throw new DomainException('traditional_page_restore_transaction_required');
        }
        $values = $before['values'];
        if ($before['missing']) {
            DB::table('content_pages')->where('id', $after['values']['id'])->update([
                'status' => 'draft', 'is_public' => false, 'is_indexable' => false,
                'published_revision_id' => null, 'translation_status' => ContentPage::TRANSLATION_STATUS_DRAFT,
            ]);

            return;
        }
        unset($values['id']);
        DB::table('content_pages')->where('id', $before['values']['id'])->update($values);
    }

    public function copy(ContentPage $page): array
    {
        preg_match_all('/^##\s+(.+)$/m', (string) $page->content_md, $matches);

        return ['title' => (string) $page->title, 'kicker' => $page->kicker, 'summary' => $page->summary,
            'seo_title' => $page->seo_title, 'meta_description' => $page->meta_description,
            'seo_description' => $page->seo_description ?: $page->meta_description, 'content_md' => (string) $page->content_md,
            'headings' => is_array($page->headings_json) ? $page->headings_json : $matches[1],
            'faq_items' => is_array($page->faq_items) ? array_values($page->faq_items) : []];
    }

    private function model(array $values): ContentPage
    {
        $model = new ContentPage;
        $model->setRawAttributes($values, true);

        return $model;
    }

    private function query(array $where, bool $lock)
    {
        $query = ContentPage::query()->withoutGlobalScopes()->where($where);

        return $lock ? $query->lockForUpdate() : $query;
    }

    private function revisions(int $id, bool $lock): array
    {
        $query = CmsTranslationRevision::query()->withoutGlobalScopes()->where('content_type', 'content_page')->where('content_id', $id)->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get()->map->getRawOriginal()->all();
    }

    private static function hash(array $value): string
    {
        return hash('sha256', PromotionContextFactory::canonicalJson($value));
    }
}
