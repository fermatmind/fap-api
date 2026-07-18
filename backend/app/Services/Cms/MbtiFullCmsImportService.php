<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use Illuminate\Support\Facades\DB;

final class MbtiFullCmsImportService
{
    private const ARTIFACT = 'MBTI-CMS-APPROVAL-39-EXACT-43-RECORD-REPAIR-APPROVAL-PACKAGE';

    private const SOURCE_STATUS = 'approved_for_fail_closed_importer_preflight';

    private const PROFILE_SNAPSHOT_KEY = 'mbti_cms_import_40_profile_draft_v1';

    private const AT_SNAPSHOT_KEY = 'mbti_cms_import_40_at_comparison_draft_v1';

    private const INTP_REVISION_ARTIFACT = 'MBTI-COMP-RUNTIME-46-INTP-EXACT-1-RECORD-REVISION-PACKAGE';

    private const INTP_REVISION_STATUS = 'approved_for_fail_closed_single_record_preflight';

    private const INTP_REVISION_SCOPE = 'single_intp_at_content_revision_only';

    private const INTP_REVISION_SNAPSHOT_KEY = 'mbti_comp_runtime_46_intp_revision_draft_v1';

    /** @param array<string,mixed> $package @param array<string,string> $options @return array<string,mixed> */
    public function plan(array $package, array $options): array
    {
        return $this->buildSummary($package, $options, false, false);
    }

    /** @param array<string,mixed> $package @param array<string,string> $options @return array<string,mixed> */
    public function stage(array $package, array $options): array
    {
        return DB::transaction(fn (): array => $this->buildSummary($package, $options, true, false));
    }

    /** @param array<string,mixed> $package @param array<string,string> $options @return array<string,mixed> */
    public function planIntpRevision(array $package, array $options): array
    {
        return $this->buildSummary($package, $options, false, true);
    }

    /** @param array<string,mixed> $package @param array<string,string> $options @return array<string,mixed> */
    public function stageIntpRevision(array $package, array $options): array
    {
        return DB::transaction(fn (): array => $this->buildSummary($package, $options, true, true));
    }

    /** @param array<string,mixed> $package @param array<string,string> $options @return array<string,mixed> */
    private function buildSummary(array $package, array $options, bool $write, bool $intpRevision): array
    {
        $base = $this->baseSummary($package, $options, $write, $intpRevision);
        $errors = $this->validatePackageEnvelope($package, $options, $intpRevision);
        $records = is_array($package['repair_records'] ?? null) ? array_values($package['repair_records']) : [];
        $preparedRows = [];
        $seenSlugs = [];

        foreach ($records as $index => $record) {
            if (! is_array($record)) {
                $errors[] = $this->issue('repair_records.'.$index, 'record_invalid', 'Each repair record must be an object.');

                continue;
            }
            $prepared = $this->prepareRecord($record, $index, $seenSlugs, $errors, $intpRevision);
            if ($prepared !== null) {
                $preparedRows[] = $prepared;
            }
        }

        if ($errors !== []) {
            return array_merge($base, [
                'ok' => false,
                'status' => 'fail',
                'row_count' => count($preparedRows),
                'rows' => $preparedRows,
                'errors' => $errors,
            ]);
        }

        $created = 0;
        $skipped = 0;
        if ($write) {
            foreach ($preparedRows as &$row) {
                if ($row['existing_revision_id'] !== null) {
                    $row['action'] = 'skipped_existing';
                    $skipped++;

                    continue;
                }

                $revision = $this->createRevision($row);
                $row['action'] = 'staged_draft_revision';
                $row['created_revision_id'] = (int) $revision->id;
                $row['created_revision_no'] = (int) $revision->revision_no;
                $created++;
            }
            unset($row);
        } else {
            foreach ($preparedRows as &$row) {
                $row['action'] = $row['existing_revision_id'] === null ? 'would_stage_draft_revision' : 'would_skip_existing';
                if ($row['existing_revision_id'] !== null) {
                    $skipped++;
                }
            }
            unset($row);
        }

        return array_merge($base, [
            'ok' => true,
            'status' => 'pass',
            'row_count' => count($preparedRows),
            'profile_row_count' => count(array_filter($preparedRows, static fn (array $row): bool => $row['entity_kind'] === 'profile')),
            'at_comparison_row_count' => count(array_filter($preparedRows, static fn (array $row): bool => $row['entity_kind'] === 'at_comparison')),
            'staged_draft_count' => $created,
            'skipped_existing_count' => $skipped,
            'writes_committed' => $write && $created > 0,
            'cms_write_attempted' => $write,
            'published_content_count' => 0,
            'readback_manifest' => $this->readbackManifest($preparedRows),
            'rows' => $preparedRows,
            'errors' => [],
        ]);
    }

    /** @param array<string,mixed> $package @param array<string,string> $options @return list<array<string,string>> */
    private function validatePackageEnvelope(array $package, array $options, bool $intpRevision): array
    {
        $errors = [];
        $artifact = $intpRevision ? self::INTP_REVISION_ARTIFACT : self::ARTIFACT;
        $status = $intpRevision ? self::INTP_REVISION_STATUS : self::SOURCE_STATUS;
        $recordCount = $intpRevision ? 1 : 43;
        if (($package['artifact'] ?? null) !== $artifact) {
            $errors[] = $this->issue('artifact', 'artifact_mismatch', 'The package artifact does not match the selected fail-closed import mode.');
        }
        if (($package['status'] ?? null) !== $status) {
            $errors[] = $this->issue('status', 'status_mismatch', 'The approval package is not approved for fail-closed importer preflight.');
        }
        $exact = is_array($package['exact_package'] ?? null) ? $package['exact_package'] : [];
        foreach ([
            'source_package_sha256' => 'expected_source_package_sha256',
            'authorization_payload_sha256' => 'expected_authorization_payload_sha256',
            'import_scope_mode' => 'expected_import_scope_mode',
            'record_count' => 'expected_record_count',
        ] as $field => $option) {
            if ((string) ($exact[$field] ?? '') !== (string) ($options[$option] ?? '')) {
                $errors[] = $this->issue('exact_package.'.$field, 'exact_authorization_mismatch', 'The provided exact authorization does not match the approval package.');
            }
        }
        $authorized = $intpRevision ? ($exact['production_write_authorized'] ?? null) : ($exact['production_import_authorized'] ?? null);
        $executed = $intpRevision ? ($exact['production_write_executed'] ?? null) : ($exact['production_import_executed'] ?? null);
        if ($executed !== false || $authorized !== false) {
            $errors[] = $this->issue('exact_package', 'package_execution_state_invalid', 'The source approval package must remain unexecuted and non-authorizing.');
        }
        if ((int) ($exact['record_count'] ?? 0) !== $recordCount || count((array) ($package['repair_records'] ?? [])) !== $recordCount) {
            $errors[] = $this->issue('repair_records', 'record_count_mismatch', 'The importer requires the exact record count for the selected mode.');
        }
        if ($intpRevision) {
            $errors = array_merge($errors, $this->validateIntpRevisionHashes($package));
        }

        return $errors;
    }

    /** @param array<string,mixed> $record @param array<string,bool> $seenSlugs @param list<array<string,string>> $errors @return array<string,mixed>|null */
    private function prepareRecord(array $record, int $index, array &$seenSlugs, array &$errors, bool $intpRevision): ?array
    {
        $path = 'repair_records.'.$index;
        $kind = (string) ($record['entity_kind'] ?? '');
        $slug = (string) ($record['slug'] ?? '');
        $locale = (string) ($record['locale'] ?? '');
        $payload = is_array($record['import_payload'] ?? null) ? $record['import_payload'] : [];
        $expectedPre = is_array($record['expected_pre_state'] ?? null) ? $record['expected_pre_state'] : [];
        $expectedPost = is_array($record['expected_post_state'] ?? null) ? $record['expected_post_state'] : [];

        if ($intpRevision && ($kind !== 'at_comparison' || $slug !== 'intp-a-vs-intp-t' || ($record['target_path'] ?? null) !== '/zh/personality/intp-a-vs-intp-t')) {
            $errors[] = $this->issue($path, 'intp_revision_scope_mismatch', 'The single-record revision accepts only intp-a-vs-intp-t.');

            return null;
        }

        if (! in_array($kind, ['profile', 'at_comparison'], true)) {
            $errors[] = $this->issue($path.'.entity_kind', 'unsupported_entity_kind', 'Only profile and A/T comparison records are supported by this importer.');

            return null;
        }
        if ($locale !== 'zh-CN' || ($payload['locale'] ?? null) !== 'zh-CN') {
            $errors[] = $this->issue($path.'.locale', 'locale_mismatch', 'Only zh-CN records are accepted.');
        }
        if ($slug === '' || isset($seenSlugs[$slug])) {
            $errors[] = $this->issue($path.'.slug', 'slug_invalid_or_duplicate', 'Every record must have a unique non-empty slug.');
        }
        $seenSlugs[$slug] = true;
        if (($expectedPre['record_must_exist'] ?? null) !== true || ($expectedPre['public_projection_must_remain_unchanged_by_import'] ?? null) !== true) {
            $errors[] = $this->issue($path.'.expected_pre_state', 'pre_state_contract_mismatch', 'The record must require an existing target and unchanged public projection.');
        }
        if (($expectedPre['locale'] ?? null) !== 'zh-CN' || ($expectedPre['framework'] ?? null) !== 'mbti64' || ($expectedPre['entity_kind'] ?? null) !== $kind) {
            $errors[] = $this->issue($path.'.expected_pre_state', 'pre_state_identity_mismatch', 'The pre-state locale, framework, and entity kind must match the approved target.');
        }
        if (($expectedPost['revision_staged'] ?? null) !== true || ($expectedPost['revision_visibility'] ?? null) !== 'draft_only' || ($expectedPost['public_projection_promoted'] ?? null) !== false || ($expectedPost['is_indexable_mutated'] ?? null) !== false || ($expectedPost['sitemap_eligibility_mutated'] ?? null) !== false || ($expectedPost['llms_eligibility_mutated'] ?? null) !== false) {
            $errors[] = $this->issue($path.'.expected_post_state', 'post_state_contract_mismatch', 'The record must stage a draft revision without public or discoverability mutation.');
        }
        if (! $this->payloadIsComplete($payload, $kind)) {
            $errors[] = $this->issue($path.'.import_payload', 'payload_incomplete', 'The payload must contain SEO, answer content, sections, FAQ, internal links, canonical, noindex robots, and draft-only visibility.');
        }
        $payloadHash = $this->hashJson($payload);
        if ($payloadHash !== (string) ($expectedPost['content_payload_sha256'] ?? '')) {
            $errors[] = $this->issue($path.'.expected_post_state.content_payload_sha256', 'content_payload_hash_mismatch', 'The payload does not match its approved content hash.');
        }

        $target = $kind === 'profile'
            ? $this->profileTarget($record, $payload, $path, $errors)
            : $this->atComparisonTarget($record, $payload, $path, $errors);
        if ($target === null) {
            return null;
        }

        $snapshotKey = $intpRevision ? self::INTP_REVISION_SNAPSHOT_KEY : ($kind === 'profile' ? self::PROFILE_SNAPSHOT_KEY : self::AT_SNAPSHOT_KEY);
        $existing = $this->existingRevision($target['model'], $target['id'], $snapshotKey, (string) ($record['approval_record_id'] ?? ''), $payloadHash);

        return [
            'approval_record_id' => (string) ($record['approval_record_id'] ?? ''),
            'entity_kind' => $kind,
            'slug' => $slug,
            'target_path' => (string) ($record['target_path'] ?? ''),
            'target_id' => $target['id'],
            'target_model' => $target['model'],
            'snapshot_key' => $snapshotKey,
            'payload_sha256' => $payloadHash,
            'source_package_sha256' => (string) data_get($record, 'source_package_sha256', ''),
            'existing_revision_id' => $existing?->id !== null ? (int) $existing->id : null,
            'existing_revision_no' => $existing?->revision_no !== null ? (int) $existing->revision_no : null,
            'next_revision_no' => $this->nextRevisionNo($target['model'], $target['id']),
            'snapshot' => $this->snapshot($snapshotKey, $record, $payload, $payloadHash),
        ];
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $payload @param list<array<string,string>> $errors @return array{model:string,id:int}|null */
    private function profileTarget(array $record, array $payload, string $path, array &$errors): ?array
    {
        $identity = is_array($payload['identity'] ?? null) ? $payload['identity'] : [];
        $runtimeType = (string) ($identity['runtime_type_code'] ?? '');
        $baseType = (string) ($identity['canonical_type_code'] ?? '');
        $profile = $this->publishedProfile($baseType);
        if (! $profile instanceof PersonalityProfile || (string) ($record['slug'] ?? '') !== strtolower($runtimeType)) {
            $errors[] = $this->issue($path, 'profile_target_pre_state_mismatch', 'The published profile target does not match the approved variant identity.');

            return null;
        }
        $variant = PersonalityProfileVariant::query()->withoutGlobalScopes()
            ->where('personality_profile_id', $profile->id)
            ->where('runtime_type_code', $runtimeType)
            ->where('is_published', true)
            ->first();
        if (! $variant instanceof PersonalityProfileVariant) {
            $errors[] = $this->issue($path.'.import_payload.identity', 'variant_target_missing', 'The approved published profile variant does not exist.');

            return null;
        }

        return ['model' => 'variant', 'id' => (int) $variant->id];
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $payload @param list<array<string,string>> $errors @return array{model:string,id:int}|null */
    private function atComparisonTarget(array $record, array $payload, string $path, array &$errors): ?array
    {
        $identity = is_array($payload['identity'] ?? null) ? $payload['identity'] : [];
        $baseType = (string) ($identity['base_type_code'] ?? '');
        $left = (string) ($identity['left_type_code'] ?? '');
        $right = (string) ($identity['right_type_code'] ?? '');
        if (($payload['page_type'] ?? null) !== 'comparison' || ($payload['comparison_kind'] ?? null) !== 'at' || ($identity['comparison_slug'] ?? null) !== ($record['slug'] ?? null) || $left !== $baseType.'-A' || $right !== $baseType.'-T') {
            $errors[] = $this->issue($path.'.import_payload.identity', 'at_comparison_identity_mismatch', 'The A/T comparison identity does not match its approved slug and pair.');

            return null;
        }
        $profile = $this->publishedProfile($baseType);
        if (! $profile instanceof PersonalityProfile || ! $this->publishedVariantExists($profile, $left) || ! $this->publishedVariantExists($profile, $right)) {
            $errors[] = $this->issue($path, 'at_comparison_target_pre_state_mismatch', 'The published A/T comparison target or its variants do not exist.');

            return null;
        }

        return ['model' => 'profile', 'id' => (int) $profile->id];
    }

    /** @param array<string,mixed> $payload */
    private function payloadIsComplete(array $payload, string $kind): bool
    {
        $seo = is_array($payload['seo'] ?? null) ? $payload['seo'] : [];
        $visibility = is_array($payload['import_visibility'] ?? null) ? $payload['import_visibility'] : [];

        return ($payload['page_type'] ?? null) === ($kind === 'profile' ? 'variant' : 'comparison')
            && is_array($payload['content'] ?? null)
            && is_array($payload['content_sections'] ?? null) && count($payload['content_sections']) > 0
            && is_array($payload['faq'] ?? null) && count($payload['faq']) > 0
            && is_array($payload['internal_links'] ?? null) && count($payload['internal_links']) > 0
            && is_array($payload['structured_metadata'] ?? null)
            && trim((string) ($payload['canonical'] ?? '')) !== ''
            && str_starts_with((string) ($payload['canonical'] ?? ''), 'https://fermatmind.com/zh/personality/')
            && ($payload['robots'] ?? null) === 'noindex,follow'
            && trim((string) ($seo['seo_title'] ?? '')) !== ''
            && trim((string) ($seo['seo_description'] ?? '')) !== ''
            && trim((string) ($seo['quick_answer_summary'] ?? '')) !== ''
            && ($visibility['draft_only'] ?? null) === true
            && ($visibility['no_public_promotion'] ?? null) === true
            && ($visibility['no_indexability_mutation'] ?? null) === true
            && ($visibility['no_sitemap_mutation'] ?? null) === true
            && ($visibility['no_llms_mutation'] ?? null) === true;
    }

    private function publishedProfile(string $baseType): ?PersonalityProfile
    {
        $profile = PersonalityProfile::query()->withoutGlobalScopes()
            ->where('org_id', 0)->where('scale_code', PersonalityProfile::SCALE_CODE_MBTI)
            ->where('locale', 'zh-CN')->where('canonical_type_code', $baseType)
            ->where('status', 'published')->where('is_public', true)->first();

        return $profile instanceof PersonalityProfile ? $profile : null;
    }

    private function publishedVariantExists(PersonalityProfile $profile, string $runtimeType): bool
    {
        return PersonalityProfileVariant::query()->withoutGlobalScopes()
            ->where('personality_profile_id', $profile->id)->where('runtime_type_code', $runtimeType)
            ->where('is_published', true)->exists();
    }

    private function existingRevision(string $model, int $targetId, string $snapshotKey, string $recordId, string $payloadHash): PersonalityProfileRevision|PersonalityProfileVariantRevision|null
    {
        $query = $model === 'profile'
            ? PersonalityProfileRevision::query()->where('profile_id', $targetId)
            : PersonalityProfileVariantRevision::query()->where('personality_profile_variant_id', $targetId);
        foreach ($query->orderByDesc('revision_no')->get() as $revision) {
            $snapshot = is_array($revision->snapshot_json) ? $revision->snapshot_json : [];
            if (($snapshot[$snapshotKey]['approval_record_id'] ?? null) === $recordId && ($snapshot[$snapshotKey]['payload_sha256'] ?? null) === $payloadHash) {
                return $revision;
            }
        }

        return null;
    }

    private function nextRevisionNo(string $model, int $targetId): int
    {
        $query = $model === 'profile'
            ? PersonalityProfileRevision::query()->where('profile_id', $targetId)
            : PersonalityProfileVariantRevision::query()->where('personality_profile_variant_id', $targetId);

        return ((int) $query->max('revision_no')) + 1;
    }

    /** @param array<string,mixed> $row */
    private function createRevision(array $row): PersonalityProfileRevision|PersonalityProfileVariantRevision
    {
        $attributes = [
            'revision_no' => $row['next_revision_no'],
            'snapshot_json' => $row['snapshot'],
            'note' => ($row['snapshot_key'] === self::INTP_REVISION_SNAPSHOT_KEY ? 'MBTI-COMP-RUNTIME-46 INTP draft-only revision ' : 'MBTI-CMS-40 draft-only import ').substr($row['payload_sha256'], 0, 12),
            'created_by_admin_user_id' => null,
            'created_at' => now(),
        ];

        if ($row['target_model'] === 'profile') {
            return PersonalityProfileRevision::query()->create(array_merge($attributes, ['profile_id' => $row['target_id']]));
        }

        return PersonalityProfileVariantRevision::query()->create(array_merge($attributes, ['personality_profile_variant_id' => $row['target_id']]));
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $payload @return array<string,mixed> */
    private function snapshot(string $key, array $record, array $payload, string $payloadHash): array
    {
        return [$key => [
            'approval_record_id' => (string) ($record['approval_record_id'] ?? ''),
            'source_asset_id' => (string) ($record['source_asset_id'] ?? ''),
            'target_path' => (string) ($record['target_path'] ?? ''),
            'entity_kind' => (string) ($record['entity_kind'] ?? ''),
            'payload_sha256' => $payloadHash,
            'visibility' => 'draft_only',
            'public_projection_promoted' => false,
            'indexability_mutated' => false,
            'sitemap_eligibility_mutated' => false,
            'llms_eligibility_mutated' => false,
            'payload' => $payload,
        ]];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function readbackManifest(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'approval_record_id' => $row['approval_record_id'],
            'target_path' => $row['target_path'],
            'entity_kind' => $row['entity_kind'],
            'draft_revision_id' => $row['created_revision_id'] ?? $row['existing_revision_id'],
            'payload_sha256' => $row['payload_sha256'],
            'visibility' => 'draft_only',
        ], $rows);
    }

    /** @param array<string,mixed> $value */
    private function hashJson(array $value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** @param array<string,mixed> $package @param array<string,string> $options @return array<string,mixed> */
    private function baseSummary(array $package, array $options, bool $write, bool $intpRevision): array
    {
        return [
            'artifact' => $intpRevision ? 'MBTI-COMP-RUNTIME-46-INTP-REVISION-IMPORT' : 'MBTI-CMS-IMPORT-40',
            'source_artifact' => (string) ($package['artifact'] ?? ''),
            'source_package_sha256' => (string) ($options['expected_source_package_sha256'] ?? ''),
            'authorization_payload_sha256' => (string) ($options['expected_authorization_payload_sha256'] ?? ''),
            'import_scope_mode' => (string) ($options['expected_import_scope_mode'] ?? ''),
            'record_count' => (int) ($options['expected_record_count'] ?? 0),
            'dry_run' => ! $write,
            'write' => $write,
            'draft_only' => true,
            'publish_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'production_import_authorization_required' => true,
            'writes_committed' => false,
            'cms_write_attempted' => false,
        ];
    }

    /** @param array<string,mixed> $package @return list<array<string,string>> */
    private function validateIntpRevisionHashes(array $package): array
    {
        $errors = [];
        $exact = is_array($package['exact_package'] ?? null) ? $package['exact_package'] : [];
        $records = is_array($package['repair_records'] ?? null) ? array_values($package['repair_records']) : [];
        $record = is_array($records[0] ?? null) ? $records[0] : [];
        $payload = is_array($record['import_payload'] ?? null) ? $record['import_payload'] : [];
        $sourceManifest = is_array($package['source_manifest'] ?? null) ? $package['source_manifest'] : [];
        $authorizationPayload = is_array($package['authorization_payload'] ?? null) ? $package['authorization_payload'] : [];

        foreach ([
            'exact_package.source_package_sha256' => [$this->hashJson($sourceManifest), (string) ($exact['source_package_sha256'] ?? '')],
            'exact_package.authorization_payload_sha256' => [$this->hashJson($authorizationPayload), (string) ($exact['authorization_payload_sha256'] ?? '')],
            'repair_records.0.exact_payload_sha256' => [$this->hashJson($payload), (string) ($record['exact_payload_sha256'] ?? '')],
        ] as $field => [$actual, $expected]) {
            if ($expected === '' || ! hash_equals($expected, $actual)) {
                $errors[] = $this->issue($field, 'canonical_json_hash_mismatch', 'The immutable canonical JSON hash does not match the package content.');
            }
        }
        if (($exact['import_scope_mode'] ?? null) !== self::INTP_REVISION_SCOPE
            || ($authorizationPayload['import_scope_mode'] ?? null) !== self::INTP_REVISION_SCOPE
            || ($authorizationPayload['record_count'] ?? null) !== 1
            || ($exact['public_promotion_authorized'] ?? null) !== false) {
            $errors[] = $this->issue('authorization_payload', 'intp_revision_authorization_boundary_mismatch', 'The single-record authorization boundary is incomplete or permits public promotion.');
        }

        return $errors;
    }

    /** @return array<string,string> */
    private function issue(string $field, string $code, string $message): array
    {
        return compact('field', 'code', 'message');
    }
}
