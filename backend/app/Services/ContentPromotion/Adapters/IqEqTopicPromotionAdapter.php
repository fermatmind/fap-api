<?php

declare(strict_types=1);

namespace App\Services\ContentPromotion\Adapters;

use App\Http\Controllers\API\V0_5\Cms\TopicController;
use App\Models\ContentReleaseSnapshot;
use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Models\TopicProfileRevision;
use App\Models\TopicProfileSection;
use App\Models\TopicProfileSeoMeta;
use App\Services\ContentPromotion\Contracts\ExactPackagePromotionAdapter;
use App\Services\ContentPromotion\IqEqTopicPackage;
use App\Services\ContentPromotion\IqEqTopicPrerequisites;
use App\Services\ContentPromotion\IqEqTopicProjection;
use App\Services\ContentPromotion\PromotionAdapterResultFactory;
use App\Services\ContentPromotion\PromotionContext;
use App\Services\ContentPromotion\PromotionContextFactory;
use App\Services\ContentPromotion\PromotionReceiptStore;
use App\Services\ContentPromotion\PromotionRollbackSnapshotService;
use App\Services\ContentPromotion\PromotionTargetSet;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Fixed SH-01 candidate; no identity, qualification or operator hold changes. */
final class IqEqTopicPromotionAdapter implements ExactPackagePromotionAdapter
{
    public const SUBSCOPE = 'IQ-EQ-TOPIC';

    private const PACK = 'iq-eq-topic';

    private const PROFILE_FIELDS = ['title', 'subtitle', 'excerpt'];

    private const SECTION_FIELDS = ['render_variant', 'body_md', 'body_html', 'payload_json'];

    private const SEO_FIELDS = ['seo_title', 'seo_description', 'og_title', 'og_description', 'twitter_title', 'twitter_description', 'jsonld_overrides_json'];

    public function __construct(
        private readonly IqEqTopicPackage $package,
        private readonly IqEqTopicProjection $projection,
        private readonly IqEqTopicPrerequisites $prerequisites,
        private readonly PromotionReceiptStore $receipts,
        private readonly PromotionRollbackSnapshotService $snapshots,
        private readonly TopicController $publicApi,
    ) {}

    public function id(): string
    {
        return 'iq_eq_topic_v1';
    }

    public function capability(): string
    {
        return 'audit_compatible';
    }

    public function supports(string $lane, ?string $subscope): bool
    {
        return $lane === 'W3' && $subscope === self::SUBSCOPE;
    }

    public function preflight(PromotionContext $context): array
    {
        $this->prerequisites->assertPublished();
        $rows = $this->rows($context);
        $state = $this->state($rows, false);
        foreach ($rows as $index => $row) {
            $this->projection->candidate($row, $state[$index]);
        }
        $this->assertFailedShaCannotRetry($context, $rows, $state);

        return $this->result($context, 0, 0, null, $state);
    }

    public function draftImport(PromotionContext $context): array
    {
        $this->prerequisites->assertPublished();
        $rows = $this->rows($context);
        $previous = $this->previous($context, 'content_promotion_preflight_receipt');
        $written = 0;
        $state = DB::transaction(function () use ($rows, $previous, &$written): array {
            $state = $this->state($rows, true);
            $this->assertReceiptState($previous, $state);
            foreach ($rows as $index => $row) {
                $this->projection->candidate($row, $state[$index]);
                $profile = $state[$index]['profile'];
                $revision = $this->revision($row, (int) $profile['id'], true);
                if ($revision) {
                    $this->assertRevision($row, $revision);

                    continue;
                }
                $placeholderId = null;
                if (! in_array('faq', array_column($state[$index]['sections'], 'section_key'), true)) {
                    $placeholder = TopicProfileSection::query()->create([
                        'profile_id' => $profile['id'], 'section_key' => 'faq', 'render_variant' => 'faq',
                        'is_enabled' => false, 'sort_order' => max(array_column($state[$index]['sections'], 'sort_order')) + 10,
                    ]);
                    $placeholderId = (int) $placeholder->id;
                }
                TopicProfileRevision::query()->create([
                    'profile_id' => $profile['id'], 'revision_no' => (int) TopicProfileRevision::query()->where('profile_id', $profile['id'])->max('revision_no') + 1,
                    'authority_asset_key' => 'SH-01:'.$row['identity']['locale'], 'source_package' => IqEqTopicPackage::PACKAGE,
                    'authority_package_sha256' => IqEqTopicPackage::SHA256, 'source_hash' => $this->hash($row['snapshot']),
                    'snapshot_json' => $row['snapshot'], 'workflow_state' => 'approved_exact_package',
                    'public_runtime_fingerprint_before' => $this->hash($state[$index]),
                    'note' => json_encode(['schema' => 'iq.eq.topic.draft.v1', 'created_faq_section_id' => $placeholderId], JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
                // An unrelated operator working revision is never replaced.
                $written++;
            }

            return $this->state($rows, true);
        }, 3);

        return $this->result($context, $written, 0, null, $state);
    }

    public function publish(PromotionContext $context): array
    {
        $this->prerequisites->assertPublished();
        $rows = $this->rows($context);
        $previous = $this->previous($context, 'cms_draft_import_receipt');
        // Admission is committed separately, so a failed business transaction
        // cannot erase the original SHA's terminal publication authority.
        $admission = DB::transaction(function () use ($context, $rows, $previous): array {
            $this->prerequisites->assertPublished(true);
            $state = $this->state($rows, true);
            $this->assertFailedShaCannotRetry($context, $rows, $state);
            if ($this->isPublished($rows, $state)) {
                return ['state' => $state, 'reference' => null];
            }
            $this->assertReceiptState($previous, $state);
            foreach ($rows as $index => $row) {
                $this->projection->candidate($row, $state[$index]);
                $this->assertRevision($row, $this->revision($row, (int) $state[$index]['profile']['id'], true));
            }
            $reference = $this->snapshots->capture($context, $this->targets($rows), self::PACK, 'before_publication', $state, array_column($rows, 'identity'), [
                'workflow_run_id' => $context->workflowRunId, 'workflow_run_attempt' => $context->workflowRunAttempt,
                'executor_release_sha256' => $context->executorReleaseSha256,
            ]);

            return ['state' => $state, 'reference' => $reference];
        }, 3);
        if ($admission['reference'] === null) {
            return $this->result($context, 0, 2, null, $admission['state']);
        }
        $state = DB::transaction(function () use ($rows, $admission): array {
            $this->prerequisites->assertPublished(true);
            $state = $this->state($rows, true);
            if ($this->hash($state) !== $this->hash($admission['state'])) {
                throw new DomainException('iq_eq_topic_admission_state_drift');
            }
            foreach ($rows as $index => $row) {
                $before = $state[$index];
                $after = $this->projection->candidate($row, $before);
                $profile = TopicProfile::query()->withoutGlobalScopes()->findOrFail($before['profile']['id']);
                $revision = $this->revision($row, (int) $profile->id, true);
                $this->assertRevision($row, $revision);
                $profile->forceFill([...Arr::only($after['profile'], self::PROFILE_FIELDS), 'published_revision_id' => $revision->id])->saveQuietly();
                $draftMetadata = json_decode((string) $revision->note, true, 8, JSON_THROW_ON_ERROR);
                $revision->forceFill(['workflow_state' => 'published', 'note' => json_encode([...$draftMetadata, 'publication_reference' => $admission['reference']], JSON_THROW_ON_ERROR)])->saveQuietly();
                foreach ($after['sections'] as $section) {
                    if ($section['id'] === null) {
                        TopicProfileSection::query()->create(Arr::except($section, ['id']));

                        continue;
                    }
                    if (! in_array($section['section_key'], ['overview', 'faq'], true)) {
                        continue;
                    }
                    TopicProfileSection::query()->findOrFail($section['id'])->forceFill([...Arr::only($section, self::SECTION_FIELDS), ...(($before['owned_faq_placeholder_id'] ?? null) === $section['id'] ? ['is_enabled' => true] : [])])->saveQuietly();
                }
                foreach ($row['snapshot']['entry_excerpt_overrides'] as $candidate) {
                    foreach ($after['entries'] as $entry) {
                        if ($entry['entry_type'] === $candidate['entry_type'] && $entry['group_key'] === $candidate['group_key'] && $entry['target_key'] === $candidate['target_key']) {
                            TopicProfileEntry::query()->findOrFail($entry['id'])->forceFill(['excerpt_override' => $entry['excerpt_override']])->saveQuietly();
                        }
                    }
                }
                TopicProfileSeoMeta::query()->findOrFail($after['seo']['id'])->forceFill(Arr::only($after['seo'], self::SEO_FIELDS))->saveQuietly();
            }
            $after = $this->state($rows, true);
            $this->assertPublished($rows, $after);

            return $after;
        }, 3);

        return $this->result($context, 2, 2, $admission['reference'], $state);
    }

    public function liveQa(PromotionContext $context): array
    {
        $this->prerequisites->assertPublished();
        $this->previous($context, 'cms_publication_receipt');
        $rows = $this->rows($context);
        $state = $this->state($rows, false);
        $this->assertPublished($rows, $state);
        foreach ($rows as $row) {
            $response = $this->publicApi->show(Request::create('/api/v0.5/topics/iq-eq', 'GET', ['org_id' => 0, 'locale' => $row['identity']['locale']]), 'iq-eq');
            $payload = $response->getData(true);
            if ($response->getStatusCode() !== 200 || ($payload['ok'] ?? null) !== true) {
                throw new DomainException('iq_eq_topic_public_api_unavailable');
            }
            foreach ($row['snapshot']['profile_patch'] as $field => $value) {
                if (data_get($payload, 'profile.'.$field) !== $value) {
                    throw new DomainException('iq_eq_topic_public_profile_drift');
                }
            }
            $faqs = array_map(static fn (array $faq): array => Arr::only($faq, ['question', 'answer']), data_get($payload, 'answer_surface_v1.faq_blocks', []));
            if ($this->hash($faqs) !== $this->hash($row['snapshot']['section_candidates'][1]['payload_json']['items'])) {
                throw new DomainException('iq_eq_topic_public_faq_drift');
            }
            if (data_get($payload, 'seo_surface_v1.title') !== $row['snapshot']['seo_text_candidate']['title']
                || data_get($payload, 'seo_surface_v1.description') !== $row['snapshot']['seo_text_candidate']['description']) {
                throw new DomainException('iq_eq_topic_public_metadata_drift');
            }
        }

        return $this->result($context, 0, 2, null, $state);
    }

    public function rollback(PromotionContext $context, string $rollbackReference): void
    {
        $rows = $this->rows($context);
        $snapshot = $this->snapshots->resolve($context, $this->targets($rows), self::PACK, 'before_publication', $rollbackReference);
        if (data_get($snapshot->meta_json, 'workflow_run_id') !== $context->workflowRunId
            || data_get($snapshot->meta_json, 'workflow_run_attempt') !== 1 || data_get($snapshot->meta_json, 'executor_release_sha256') !== $context->executorReleaseSha256) {
            throw new DomainException('iq_eq_topic_recovery_owner_invalid');
        }
        DB::transaction(function () use ($rows, $snapshot, $rollbackReference): void {
            $state = $this->state($rows, true);
            $saved = data_get($snapshot->meta_json, 'rows', []);
            if (count($saved) !== 2) {
                throw new DomainException('iq_eq_topic_recovery_rows_invalid');
            }
            foreach ($rows as $index => $row) {
                $before = $saved[$index];
                $actual = $state[$index];
                if ($actual['profile']['id'] !== $before['profile']['id']) {
                    throw new DomainException('iq_eq_topic_recovery_identity_drift');
                }
                if ($this->owned($actual) === $this->owned($before)) {
                    continue;
                }
                $revision = $this->revision($row, (int) $actual['profile']['id'], true);
                $this->assertRevision($row, $revision);
                if ((int) $actual['profile']['published_revision_id'] !== (int) $revision->id || $revision->workflow_state !== 'published'
                    || data_get(json_decode((string) $revision->note, true), 'publication_reference') !== $rollbackReference) {
                    throw new DomainException('iq_eq_topic_recovery_owner_drift');
                }
                // Build the expected owned copy using saved eligibility, so an
                // operator withdrawal is preserved while copy is restored.
                $expected = $this->projection->candidate($row, $before);
                if ($this->owned($actual, false) !== $this->owned($expected, false)) {
                    throw new DomainException('iq_eq_topic_recovery_copy_drift');
                }
                TopicProfile::query()->withoutGlobalScopes()->findOrFail($actual['profile']['id'])->forceFill([
                    ...Arr::only($before['profile'], self::PROFILE_FIELDS), 'published_revision_id' => $before['profile']['published_revision_id'],
                ])->saveQuietly();
                foreach ($before['sections'] as $section) {
                    if (in_array($section['section_key'], ['overview', 'faq'], true)) {
                        TopicProfileSection::query()->findOrFail($section['id'])->forceFill(Arr::only($section, self::SECTION_FIELDS))->saveQuietly();
                    }
                }
                if (! in_array('faq', array_column($before['sections'], 'section_key'), true)) {
                    // Retain the newly created identity as a disabled empty row;
                    // never delete a production CMS row during recovery.
                    $faq = TopicProfileSection::query()->where('profile_id', $actual['profile']['id'])->where('section_key', 'faq')->firstOrFail();
                    $faq->forceFill(['body_md' => null, 'body_html' => null, 'payload_json' => null, 'is_enabled' => false])->saveQuietly();
                }
                foreach ($before['entries'] as $entry) {
                    if (self::ownedEntry($entry)) {
                        TopicProfileEntry::query()->findOrFail($entry['id'])->forceFill(['excerpt_override' => $entry['excerpt_override'] ?? null])->saveQuietly();
                    }
                }
                $restoreSeo = Arr::except(Arr::only($before['seo'], self::SEO_FIELDS), ['jsonld_overrides_json']);
                $currentJsonLd = $actual['seo']['jsonld_overrides_json'];
                foreach (['name', 'description'] as $field) {
                    if (is_array($before['seo']['jsonld_overrides_json']) && array_key_exists($field, $before['seo']['jsonld_overrides_json'])) {
                        $currentJsonLd[$field] = $before['seo']['jsonld_overrides_json'][$field];
                    }
                }
                $restoreSeo['jsonld_overrides_json'] = $currentJsonLd;
                TopicProfileSeoMeta::query()->findOrFail($before['seo']['id'])->forceFill($restoreSeo)->saveQuietly();
                if (($before['owned_faq_placeholder_id'] ?? null) !== null) {
                    TopicProfileSection::query()->findOrFail($before['owned_faq_placeholder_id'])->forceFill(['is_enabled' => false])->saveQuietly();
                }
                $savedRevision = array_values(array_filter($before['revisions'], static fn (array $saved): bool => (int) $saved['id'] === (int) $revision->id));
                if (count($savedRevision) !== 1) {
                    throw new DomainException('iq_eq_topic_recovery_revision_invalid');
                }
                $revision->forceFill(['workflow_state' => 'approved_exact_package', 'note' => $savedRevision[0]['note']])->saveQuietly();
            }
        }, 3);
    }

    /** Restores only this exact workflow execution's admitted publication. */
    public function recoverFailedPublication(PromotionContext $context): bool
    {
        $this->rows($context);
        $matches = ContentReleaseSnapshot::query()->where('pack_id', self::PACK)->get()->filter(static fn (ContentReleaseSnapshot $snapshot): bool => data_get($snapshot->meta_json, 'phase') === 'before_publication'
            && data_get($snapshot->meta_json, 'package_sha256') === $context->packageSha256
            && data_get($snapshot->meta_json, 'source_commit') === $context->sourceCommit
            && data_get($snapshot->meta_json, 'workflow_run_id') === $context->workflowRunId
            && data_get($snapshot->meta_json, 'workflow_run_attempt') === 1
            && data_get($snapshot->meta_json, 'executor_release_sha256') === $context->executorReleaseSha256);
        if ($matches->isEmpty()) {
            return false;
        }
        if ($matches->count() !== 1) {
            throw new DomainException('iq_eq_topic_recovery_snapshot_collision');
        }
        $this->rollback($context, 'content-release-snapshot:'.$matches->first()->id);

        return true;
    }

    private function rows(PromotionContext $context): array
    {
        if (! $this->supports($context->lane, $context->subscope) || $context->expectedRowCount !== 2 || $context->workflowRunAttempt !== 1
            || realpath($context->packageDirectory) !== realpath(base_path(IqEqTopicPackage::PACKAGE))) {
            throw new DomainException('iq_eq_topic_context_invalid');
        }

        return $this->package->read(base_path(), $context->packageSha256);
    }

    private function state(array $rows, bool $lock): array
    {
        $state = [];
        foreach ($rows as $row) {
            $query = TopicProfile::query()->withoutGlobalScopes()->where($row['identity']);
            $profiles = ($lock ? $query->lockForUpdate() : $query)->get();
            if ($profiles->count() !== 1) {
                throw new DomainException('iq_eq_topic_identity_collision');
            }
            $profile = $profiles->first();
            $related = function (string $class) use ($profile, $lock): array {
                $query = $class::query()->where('profile_id', $profile->id)->orderBy('id');

                return ($lock ? $query->lockForUpdate() : $query)->get()->map(static fn ($model): array => $model->attributesToArray())->all();
            };
            $seo = $related(TopicProfileSeoMeta::class);
            if (count($seo) !== 1) {
                throw new DomainException('iq_eq_topic_seo_collision');
            }
            $revision = $this->revision($row, (int) $profile->id, $lock);
            $placeholderId = null;
            if ($revision && $revision->workflow_state === 'approved_exact_package') {
                $this->assertRevision($row, $revision);
                $metadata = json_decode((string) $revision->note, true);
                if (($metadata['schema'] ?? null) === 'iq.eq.topic.draft.v1' && is_int($metadata['created_faq_section_id'] ?? null)) {
                    $placeholderId = $metadata['created_faq_section_id'];
                }
            }
            $state[] = ['profile' => $profile->attributesToArray(), 'sections' => $related(TopicProfileSection::class),
                'entries' => $related(TopicProfileEntry::class), 'seo' => $seo[0], 'revisions' => $related(TopicProfileRevision::class),
                'owned_faq_placeholder_id' => $placeholderId];
        }

        return $state;
    }

    private function revision(array $row, int $profileId, bool $lock): ?TopicProfileRevision
    {
        $query = TopicProfileRevision::query()->where('authority_package_sha256', IqEqTopicPackage::SHA256)->where('authority_asset_key', 'SH-01:'.$row['identity']['locale']);
        $matches = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($matches->count() > 1 || ($matches->first() && (int) $matches->first()->profile_id !== $profileId)) {
            throw new DomainException('iq_eq_topic_revision_collision');
        }

        return $matches->first();
    }

    private function assertRevision(array $row, ?TopicProfileRevision $revision): void
    {
        if (! $revision || $revision->source_package !== IqEqTopicPackage::PACKAGE || ! in_array($revision->workflow_state, ['approved_exact_package', 'published'], true)
            || $revision->source_hash !== $this->hash($row['snapshot']) || $this->hash($revision->snapshot_json) !== $this->hash($row['snapshot'])) {
            throw new DomainException('iq_eq_topic_revision_drift');
        }
    }

    private static function ownedEntry(array $entry): bool
    {
        return $entry['entry_type'] === 'scale' && (($entry['group_key'] === 'featured' && $entry['target_key'] === 'IQ_RAVEN')
            || ($entry['group_key'] === 'tests' && $entry['target_key'] === 'EQ_60'));
    }

    private function owned(array $state, bool $pointer = true): string
    {
        $profileFields = [...self::PROFILE_FIELDS, ...($pointer ? ['published_revision_id'] : [])];
        $sections = array_values(array_filter($state['sections'], static fn (array $section): bool => in_array($section['section_key'], ['overview', 'faq'], true)));

        return $this->hash(['profile' => Arr::only($state['profile'], $profileFields),
            'sections' => array_map(static fn (array $section): array => Arr::only($section, ['section_key', ...self::SECTION_FIELDS]), $sections),
            'entries' => array_map(static fn (array $entry): array => Arr::only($entry, ['id', 'excerpt_override']), array_values(array_filter($state['entries'], static fn (array $entry): bool => self::ownedEntry($entry)))),
            'seo' => [...Arr::except(Arr::only($state['seo'], self::SEO_FIELDS), ['jsonld_overrides_json']),
                'jsonld_text' => Arr::only((array) ($state['seo']['jsonld_overrides_json'] ?? []), ['name', 'description'])]]);
    }

    private function assertPublished(array $rows, array $state): void
    {
        $ownerReference = null;
        foreach ($rows as $index => $row) {
            $after = $this->projection->candidate($row, $state[$index]);
            if ($this->owned($state[$index], false) !== $this->owned($after, false)) {
                throw new DomainException('iq_eq_topic_published_copy_drift');
            }
            $revision = $this->revision($row, (int) $state[$index]['profile']['id'], false);
            $this->assertRevision($row, $revision);
            if ((int) $state[$index]['profile']['published_revision_id'] !== (int) $revision->id || $revision->workflow_state !== 'published') {
                throw new DomainException('iq_eq_topic_published_revision_drift');
            }
            $reference = data_get(json_decode((string) $revision->note, true), 'publication_reference');
            if (! is_string($reference) || preg_match('/\Acontent-release-snapshot:([1-9][0-9]*)\z/', $reference, $match) !== 1
                || ($ownerReference !== null && $ownerReference !== $reference)) {
                throw new DomainException('iq_eq_topic_published_owner_invalid');
            }
            $ownerReference = $reference;
            $snapshot = ContentReleaseSnapshot::query()->find((int) $match[1]);
            if (! $snapshot || $snapshot->pack_id !== self::PACK || data_get($snapshot->meta_json, 'phase') !== 'before_publication'
                || data_get($snapshot->meta_json, 'lane') !== 'W3' || data_get($snapshot->meta_json, 'subscope') !== self::SUBSCOPE
                || data_get($snapshot->meta_json, 'package_sha256') !== IqEqTopicPackage::SHA256
                || data_get($snapshot->meta_json, 'target_fingerprint') !== $this->targets($rows)->fingerprint()) {
                throw new DomainException('iq_eq_topic_published_owner_invalid');
            }
            $saved = data_get($snapshot->meta_json, 'rows.'.$index);
            $savedRevision = array_values(array_filter((array) ($saved['revisions'] ?? []), static fn (array $saved): bool => (int) $saved['id'] === (int) $revision->id));
            if (($saved['profile']['id'] ?? null) !== $state[$index]['profile']['id'] || count($savedRevision) !== 1
                || ($savedRevision[0]['source_hash'] ?? null) !== $revision->source_hash) {
                throw new DomainException('iq_eq_topic_published_owner_invalid');
            }
        }
    }

    private function isPublished(array $rows, array $state): bool
    {
        try {
            $this->assertPublished($rows, $state);

            return true;
        } catch (DomainException) {
            return false;
        }
    }

    private function assertFailedShaCannotRetry(PromotionContext $context, array $rows, array $state): void
    {
        foreach (ContentReleaseSnapshot::query()->where('pack_id', self::PACK)->get() as $snapshot) {
            if (data_get($snapshot->meta_json, 'source_commit') === $context->sourceCommit && data_get($snapshot->meta_json, 'phase') === 'before_publication'
                && (! $this->isPublished($rows, $state) || ! $this->publishedBySha($rows, $state, $context->sourceCommit))) {
                throw new DomainException('iq_eq_topic_failed_sha_terminal');
            }
        }
    }

    private function publishedBySha(array $rows, array $state, string $sha): bool
    {
        $revision = $this->revision($rows[0], (int) $state[0]['profile']['id'], false);
        $reference = data_get(json_decode((string) $revision?->note, true), 'publication_reference');
        if (! is_string($reference) || preg_match('/\Acontent-release-snapshot:([1-9][0-9]*)\z/', $reference, $match) !== 1) {
            return false;
        }

        return data_get(ContentReleaseSnapshot::query()->find((int) $match[1])?->meta_json, 'source_commit') === $sha;
    }

    private function previous(PromotionContext $context, string $kind): array
    {
        $result = $this->receipts->readPrevious($kind, $context);
        $receipt = $result['receipt'];
        $digest = $receipt['receipt_content_sha256'] ?? null;
        unset($receipt['receipt_content_sha256']);
        if ($digest !== $this->hash($receipt) || ($receipt['adapter'] ?? null) !== $this->id()
            || ($receipt['source_repository'] ?? null) !== 'fermatmind/fap-api'
            || ($receipt['workflow_run_id'] ?? null) !== $context->workflowRunId
            || ($receipt['workflow_run_attempt'] ?? null) !== 1
            || ($receipt['executor_release_sha256'] ?? null) !== $context->executorReleaseSha256
            || ($receipt['idempotency_key'] ?? null) !== $context->idempotencyKey
            || ($receipt['phase'] ?? null) !== match ($kind) {
                'content_promotion_preflight_receipt' => 'preflight', 'cms_draft_import_receipt' => 'draft-import',
                'cms_publication_receipt' => 'publish', default => null,
            }) {
            throw new DomainException('iq_eq_topic_previous_receipt_binding_invalid');
        }

        return $result;
    }

    private function assertReceiptState(array $receipt, array $state): void
    {
        if (data_get($receipt, 'receipt.target_state_sha256') !== $this->hash($state)) {
            throw new DomainException('iq_eq_topic_receipt_state_drift');
        }
    }

    private function targets(array $rows): PromotionTargetSet
    {
        return PromotionTargetSet::fromIdentities(array_column($rows, 'identity'));
    }

    private function hash(array $value): string
    {
        return hash('sha256', PromotionContextFactory::canonicalJson($value));
    }

    private function result(PromotionContext $context, int $written, int $published, ?string $reference, array $state): array
    {
        return [...PromotionAdapterResultFactory::make($context, $written, 2, $published, $reference, array_fill_keys([
            'indexability_mutation_count', 'sitemap_mutation_count', 'llms_mutation_count', 'search_mutation_count', 'deploy_mutation_count',
        ], 0)), 'target_state_sha256' => $this->hash($state)];
    }
}
