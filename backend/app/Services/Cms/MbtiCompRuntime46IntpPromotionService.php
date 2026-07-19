<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileVariant;
use Illuminate\Support\Facades\DB;

final class MbtiCompRuntime46IntpPromotionService
{
    private const ARTIFACT = 'MBTI-COMP-RUNTIME-46-INTP-EXACT-1-RECORD-REVISION-PACKAGE';

    private const STATUS = 'approved_for_fail_closed_single_record_preflight';

    private const SCOPE = 'single_intp_at_content_revision_only';

    private const SNAPSHOT_KEY = 'mbti_comp_runtime_46_intp_revision_draft_v1';

    /** @param array<string,mixed> $package @param array<string,string> $options @return array<string,mixed> */
    public function plan(array $package, array $options): array
    {
        return $this->buildSummary($package, $options, false);
    }

    /** @param array<string,mixed> $package @param array<string,string> $options @return array<string,mixed> */
    public function promote(array $package, array $options): array
    {
        return DB::transaction(fn (): array => $this->buildSummary($package, $options, true));
    }

    /** @param array<string,mixed> $package @param array<string,string> $options @return array<string,mixed> */
    public function rollback(array $package, array $options): array
    {
        return DB::transaction(function () use ($package, $options): array {
            $plan = $this->buildSummary($package, $options, false);
            if (($plan['ok'] ?? false) !== true) {
                return array_merge($plan, ['dry_run' => false, 'write' => false, 'rollback' => true]);
            }
            $profile = PersonalityProfile::query()->withoutGlobalScopes()
                ->where('org_id', 0)->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
                ->where('locale', 'zh-CN')->where('canonical_type_code', 'INTP')->firstOrFail();
            $revision = PersonalityProfileRevision::query()->find((int) ($plan['revision_id'] ?? 0));
            $node = $revision instanceof PersonalityProfileRevision && is_array(data_get($revision->snapshot_json, self::SNAPSHOT_KEY))
                ? data_get($revision->snapshot_json, self::SNAPSHOT_KEY) : [];
            $receipt = is_array($node['promotion_receipt'] ?? null) ? $node['promotion_receipt'] : [];
            if (($node['public_projection_promoted'] ?? null) !== true
                || ! hash_equals((string) ($options['expected_promotion_package_sha256'] ?? ''), (string) ($receipt['promotion_package_sha256'] ?? ''))
                || ! hash_equals((string) ($options['expected_promotion_authorization_sha256'] ?? ''), (string) ($receipt['promotion_authorization_sha256'] ?? ''))
                || ! is_array($receipt['previous_public_section'] ?? null)) {
                throw new \RuntimeException('Exact promoted revision rollback receipt is missing or mismatched.');
            }
            $before = $this->profileState($profile);
            $previous = $receipt['previous_public_section'];
            PersonalityProfileSection::query()->withoutGlobalScopes()->updateOrCreate(
                ['profile_id' => (int) $profile->id, 'section_key' => 'mbti64_comparison_a_vs_t'],
                array_diff_key($previous, array_flip(['id', 'created_at', 'updated_at']))
            );
            $node['public_projection_promoted'] = false;
            $node['promotion_receipt']['rolled_back'] = true;
            $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];
            $snapshot[self::SNAPSHOT_KEY] = $node;
            $revision->forceFill(['snapshot_json' => $snapshot])->save();
            $profile->refresh();
            if ($this->profileState($profile) !== $before) {
                throw new \RuntimeException('Publication/indexability invariant changed during rollback.');
            }

            return array_merge($plan, [
                'dry_run' => false,
                'write' => false,
                'rollback' => true,
                'writes_committed' => true,
                'action' => 'rolled_back_exact_previous_public_section',
                'publication_changed' => false,
                'indexability_changed' => false,
            ]);
        });
    }

    /** @param array<string,mixed> $package @param array<string,string> $options @return array<string,mixed> */
    private function buildSummary(array $package, array $options, bool $write): array
    {
        $errors = $this->validateEnvelope($package, $options);
        $record = is_array(data_get($package, 'repair_records.0')) ? data_get($package, 'repair_records.0') : [];
        $payload = is_array($record['import_payload'] ?? null) ? $record['import_payload'] : [];
        $payloadSha256 = $this->hashJson($payload);
        $profile = $this->targetProfile($record, $payload, $errors);
        $revision = $profile instanceof PersonalityProfile
            ? $this->matchingRevision((int) $profile->id, $record, $payloadSha256)
            : null;

        if ($write && ! $revision instanceof PersonalityProfileRevision) {
            $errors[] = $this->issue('revision', 'exact_staged_revision_missing', 'Write requires the exact staged Runtime 46 INTP revision.');
        }

        $promotion = $this->promotionPayload($payload, $payloadSha256);
        $manifest = $this->promotionManifest($record, $payloadSha256, $options);
        $promotionSha256 = $this->hashJson($manifest);
        $authorization = $this->authorizationPayload($promotionSha256, $payloadSha256, $options);
        $authorizationSha256 = $this->hashJson($authorization);

        if ($write && ! hash_equals($promotionSha256, (string) ($options['expected_promotion_package_sha256'] ?? ''))) {
            $errors[] = $this->issue('promotion_package_sha256', 'promotion_package_hash_mismatch', 'The exact promotion package hash does not match.');
        }
        if ($write && ! hash_equals($authorizationSha256, (string) ($options['expected_promotion_authorization_sha256'] ?? ''))) {
            $errors[] = $this->issue('promotion_authorization_sha256', 'promotion_authorization_hash_mismatch', 'The exact promotion authorization hash does not match.');
        }

        $before = $profile instanceof PersonalityProfile ? $this->profileState($profile) : [];
        $liveMatches = $profile instanceof PersonalityProfile
            && $this->liveMatches((int) $profile->id, $promotion['comparison_section']);
        $base = [
            'artifact' => 'MBTI-COMP-RUNTIME-46-INTP-PUBLIC-CONTENT-PROMOTION',
            'ok' => $errors === [],
            'status' => $errors === [] ? 'pass' : 'fail',
            'dry_run' => ! $write,
            'write' => $write,
            'writes_committed' => false,
            'row_count' => $record === [] ? 0 : 1,
            'slug' => (string) ($record['slug'] ?? ''),
            'snapshot_key' => self::SNAPSHOT_KEY,
            'payload_sha256' => $payloadSha256,
            'source_package_sha256' => (string) ($options['expected_source_package_sha256'] ?? ''),
            'promotion_package' => $manifest,
            'promotion_package_sha256' => $promotionSha256,
            'promotion_authorization' => $authorization,
            'promotion_authorization_sha256' => $authorizationSha256,
            'revision_id' => $revision?->id,
            'revision_state' => $revision instanceof PersonalityProfileRevision ? 'exact_staged_revision_present' : 'would_require_exact_staged_revision',
            'expected_public_section_ids' => array_values(array_map(
                static fn (array $section): string => (string) ($section['id'] ?? $section['key'] ?? ''),
                array_filter((array) ($payload['content_sections'] ?? []), 'is_array')
            )),
            'publication_before' => $before,
            'publication_changed' => false,
            'indexability_changed' => false,
            'sitemap_mutated' => false,
            'llms_mutated' => false,
            'search_release_mutated' => false,
            'rollback_contract' => [
                'source' => 'revision_snapshot.'.self::SNAPSHOT_KEY.'.promotion_receipt.previous_public_section',
                'target' => 'personality_profile_sections.mbti64_comparison_a_vs_t',
                'scope' => 'intp-a-vs-intp-t only',
                'trigger' => 'exact public readback failure',
            ],
            'errors' => $errors,
        ];

        if ($errors !== []) {
            return $base;
        }
        if ($liveMatches) {
            return array_merge($base, ['action' => $write ? 'skipped_existing' : 'would_skip_existing']);
        }
        if (! $write) {
            return array_merge($base, ['action' => 'would_promote_single_public_content_record']);
        }

        $this->apply((int) $profile->id, $promotion['comparison_section'], $revision, $promotionSha256, $authorizationSha256);
        $profile->refresh();
        if ($this->profileState($profile) !== $before) {
            throw new \RuntimeException('Publication/indexability invariant changed during content promotion.');
        }

        return array_merge($base, [
            'writes_committed' => true,
            'action' => 'promoted_single_public_content_record',
            'revision_state' => 'exact_staged_revision_promoted',
        ]);
    }

    /** @param array<string,mixed> $package @param array<string,string> $options @return list<array<string,string>> */
    private function validateEnvelope(array $package, array $options): array
    {
        $errors = [];
        $exact = is_array($package['exact_package'] ?? null) ? $package['exact_package'] : [];
        $record = is_array(data_get($package, 'repair_records.0')) ? data_get($package, 'repair_records.0') : [];
        $payload = is_array($record['import_payload'] ?? null) ? $record['import_payload'] : [];

        if (($package['artifact'] ?? null) !== self::ARTIFACT || ($package['status'] ?? null) !== self::STATUS) {
            $errors[] = $this->issue('package', 'package_contract_mismatch', 'Only the exact Runtime 46 INTP revision package is accepted.');
        }
        if (count((array) ($package['repair_records'] ?? [])) !== 1
            || ($record['slug'] ?? null) !== 'intp-a-vs-intp-t'
            || ($record['target_path'] ?? null) !== '/zh/personality/intp-a-vs-intp-t'
            || ($record['entity_kind'] ?? null) !== 'at_comparison') {
            $errors[] = $this->issue('repair_records', 'single_record_scope_mismatch', 'Exactly intp-a-vs-intp-t is required.');
        }
        foreach ([
            'source_package_sha256' => 'expected_source_package_sha256',
            'authorization_payload_sha256' => 'expected_authorization_payload_sha256',
            'import_scope_mode' => 'expected_import_scope_mode',
            'record_count' => 'expected_record_count',
        ] as $field => $option) {
            if ((string) ($exact[$field] ?? '') !== (string) ($options[$option] ?? '')) {
                $errors[] = $this->issue('exact_package.'.$field, 'exact_authorization_mismatch', 'Exact package authorization does not match.');
            }
        }
        if (($exact['import_scope_mode'] ?? null) !== self::SCOPE
            || ($exact['record_count'] ?? null) !== 1
            || ($exact['production_write_authorized'] ?? null) !== false
            || ($exact['public_promotion_authorized'] ?? null) !== false) {
            $errors[] = $this->issue('exact_package', 'source_authorization_boundary_mismatch', 'The source asset must remain non-authorizing and single-record only.');
        }
        foreach ([
            [$this->hashJson((array) ($package['source_manifest'] ?? [])), (string) ($exact['source_package_sha256'] ?? '')],
            [$this->hashJson((array) ($package['authorization_payload'] ?? [])), (string) ($exact['authorization_payload_sha256'] ?? '')],
            [$this->hashJson($payload), (string) ($record['exact_payload_sha256'] ?? '')],
        ] as [$actual, $expected]) {
            if ($expected === '' || ! hash_equals($expected, $actual)) {
                $errors[] = $this->issue('hashes', 'canonical_json_hash_mismatch', 'An immutable package hash does not match.');
            }
        }
        $ids = array_values(array_map(static fn (array $section): string => (string) ($section['id'] ?? $section['key'] ?? ''), array_filter((array) ($payload['content_sections'] ?? []), 'is_array')));
        if ($ids !== ['biggest_difference', 'quick_judgment_table', 'easy_misread', 'work_scenarios', 'relationship_scenarios', 'stress_scenarios', 'do_not_misjudge', 'common_ground', 'usage_boundary']) {
            $errors[] = $this->issue('repair_records.0.import_payload.content_sections', 'exact_nine_sections_mismatch', 'The exact ordered nine-section contract is required.');
        }

        return $errors;
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $payload @param list<array<string,string>> $errors */
    private function targetProfile(array $record, array $payload, array &$errors): ?PersonalityProfile
    {
        $profile = PersonalityProfile::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('locale', 'zh-CN')->where('canonical_type_code', 'INTP')->first();
        $identity = is_array($payload['identity'] ?? null) ? $payload['identity'] : [];
        $valid = $profile instanceof PersonalityProfile
            && $profile->status === 'published' && $profile->is_public
            && ($identity['base_type_code'] ?? null) === 'INTP'
            && ($identity['left_type_code'] ?? null) === 'INTP-A'
            && ($identity['right_type_code'] ?? null) === 'INTP-T'
            && PersonalityProfileVariant::query()->withoutGlobalScopes()->where('personality_profile_id', $profile->id)->where('runtime_type_code', 'INTP-A')->where('is_published', true)->exists()
            && PersonalityProfileVariant::query()->withoutGlobalScopes()->where('personality_profile_id', $profile->id)->where('runtime_type_code', 'INTP-T')->where('is_published', true)->exists();
        if (! $valid) {
            $errors[] = $this->issue('target', 'intp_target_pre_state_mismatch', 'The exact published INTP A/T target is missing or changed.');

            return null;
        }

        return $profile;
    }

    /** @param array<string,mixed> $record */
    private function matchingRevision(int $profileId, array $record, string $payloadSha256): ?PersonalityProfileRevision
    {
        foreach (PersonalityProfileRevision::query()->where('profile_id', $profileId)->orderByDesc('revision_no')->orderByDesc('id')->get() as $revision) {
            $node = data_get($revision->snapshot_json, self::SNAPSHOT_KEY);
            if (is_array($node)
                && ($node['approval_record_id'] ?? null) === ($record['approval_record_id'] ?? null)
                && ($node['payload_sha256'] ?? null) === $payloadSha256
                && ($node['target_path'] ?? null) === '/zh/personality/intp-a-vs-intp-t') {
                return $revision;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $payload @return array{comparison_section:array<string,mixed>} */
    private function promotionPayload(array $payload, string $payloadSha256): array
    {
        $seo = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];

        return ['comparison_section' => [
            'section_key' => 'mbti64_comparison_a_vs_t',
            'title' => (string) ($seo['h1'] ?? $seo['seo_title'] ?? ''),
            'render_variant' => 'rich_text',
            'body_md' => (string) ($seo['quick_answer_summary'] ?? ''),
            'body_html' => null,
            'payload_json' => [
                'url' => (string) ($payload['canonical_target'] ?? ''),
                'canonical' => (string) ($payload['canonical'] ?? ''),
                'seo' => $seo,
                'content' => is_array($payload['content'] ?? null) ? $payload['content'] : [],
                'sections' => array_values((array) ($payload['content_sections'] ?? [])),
                'faq' => array_values((array) ($payload['faq'] ?? [])),
                'internal_links' => $this->normalizedLinks((array) ($payload['internal_links'] ?? [])),
                'structured_metadata' => is_array($payload['structured_metadata'] ?? null) ? $payload['structured_metadata'] : [],
                'identity' => is_array($payload['identity'] ?? null) ? $payload['identity'] : [],
                'payload_sha256' => $payloadSha256,
                'source' => 'mbti-comp-runtime-46-intp-revision',
                'snapshot_key' => self::SNAPSHOT_KEY,
                'indexability_held' => false,
            ],
            'sort_order' => 920,
            'is_enabled' => true,
        ]];
    }

    /** @param list<mixed> $links @return list<array<string,mixed>> */
    private function normalizedLinks(array $links): array
    {
        return array_values(array_map(static fn (array $link): array => [
            'href' => (string) ($link['href'] ?? ''),
            'anchor_text' => (string) ($link['anchor_text'] ?? $link['label'] ?? ''),
            'role' => (string) ($link['role'] ?? $link['purpose'] ?? ''),
            'safe_public_route' => ($link['safe_public_route'] ?? false) === true,
        ], array_filter($links, 'is_array')));
    }

    /** @param array<string,mixed> $record @param array<string,string> $options @return array<string,mixed> */
    private function promotionManifest(array $record, string $payloadSha256, array $options): array
    {
        return [
            'artifact' => 'MBTI-COMP-RUNTIME-46-INTP-PUBLIC-CONTENT-PROMOTION-PACKAGE-V1',
            'source_package_sha256' => (string) ($options['expected_source_package_sha256'] ?? ''),
            'source_authorization_payload_sha256' => (string) ($options['expected_authorization_payload_sha256'] ?? ''),
            'import_scope_mode' => self::SCOPE,
            'record_count' => 1,
            'record' => [
                'approval_record_id' => (string) ($record['approval_record_id'] ?? ''),
                'slug' => 'intp-a-vs-intp-t',
                'target_path' => '/zh/personality/intp-a-vs-intp-t',
                'payload_sha256' => $payloadSha256,
                'snapshot_key' => self::SNAPSHOT_KEY,
            ],
            'mutations' => ['public_content' => true, 'publication' => false, 'indexability' => false, 'sitemap' => false, 'llms' => false, 'search_release' => false],
        ];
    }

    /** @param array<string,string> $options @return array<string,mixed> */
    private function authorizationPayload(string $promotionSha256, string $payloadSha256, array $options): array
    {
        return [
            'artifact' => 'MBTI-COMP-RUNTIME-46-INTP-PUBLIC-CONTENT-PROMOTION-AUTHORIZATION-V1',
            'promotion_package_sha256' => $promotionSha256,
            'payload_sha256' => $payloadSha256,
            'source_package_sha256' => (string) ($options['expected_source_package_sha256'] ?? ''),
            'scope' => self::SCOPE,
            'record_count' => 1,
            'rollback_on_readback_failure' => true,
            'mutations' => ['public_content' => true, 'publication' => false, 'indexability' => false, 'sitemap' => false, 'llms' => false, 'search_release' => false],
        ];
    }

    /** @param array<string,mixed> $section */
    private function apply(int $profileId, array $section, PersonalityProfileRevision $revision, string $promotionSha256, string $authorizationSha256): void
    {
        $previous = PersonalityProfileSection::query()->withoutGlobalScopes()->where('profile_id', $profileId)->where('section_key', 'mbti64_comparison_a_vs_t')->first();
        $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];
        $node = is_array($snapshot[self::SNAPSHOT_KEY] ?? null) ? $snapshot[self::SNAPSHOT_KEY] : [];
        $node['public_projection_promoted'] = true;
        $node['promotion_receipt'] = [
            'promotion_package_sha256' => $promotionSha256,
            'promotion_authorization_sha256' => $authorizationSha256,
            'previous_public_section' => $previous?->attributesToArray(),
        ];
        $snapshot[self::SNAPSHOT_KEY] = $node;
        $revision->forceFill(['snapshot_json' => $snapshot])->save();

        PersonalityProfileSection::query()->withoutGlobalScopes()->updateOrCreate(
            ['profile_id' => $profileId, 'section_key' => 'mbti64_comparison_a_vs_t'],
            array_merge($section, ['profile_id' => $profileId])
        );
    }

    /** @param array<string,mixed> $expected */
    private function liveMatches(int $profileId, array $expected): bool
    {
        $section = PersonalityProfileSection::query()->withoutGlobalScopes()->where('profile_id', $profileId)->where('section_key', 'mbti64_comparison_a_vs_t')->first();
        if (! $section instanceof PersonalityProfileSection) {
            return false;
        }
        foreach ($expected as $key => $value) {
            $actual = $section->getAttribute($key);
            if (is_array($value) ? $this->canonicalize((array) $actual) !== $this->canonicalize($value) : (string) $actual !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,mixed> */
    private function profileState(PersonalityProfile $profile): array
    {
        return ['status' => $profile->status, 'is_public' => (bool) $profile->is_public, 'is_indexable' => (bool) $profile->is_indexable, 'published_at' => $profile->published_at?->toISOString()];
    }

    /** @param array<string,mixed> $value */
    private function hashJson(array $value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** @param array<string,mixed> $value @return array<string,mixed> */
    private function canonicalize(array $value): array
    {
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonicalize($item);
            }
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @return array<string,string> */
    private function issue(string $field, string $code, string $message): array
    {
        return compact('field', 'code', 'message');
    }
}
