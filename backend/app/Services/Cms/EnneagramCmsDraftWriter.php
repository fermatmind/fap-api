<?php

declare(strict_types=1);

namespace App\Services\Cms;

use App\Models\PersonalityPublicContentAsset;
use Illuminate\Support\Facades\DB;

final class EnneagramCmsDraftWriter
{
    private const SNAPSHOT_SOURCE = 'enneagram_agent_projection_draft_v1';

    private const EVIDENCE_REFRESH_EXPECTED_COUNT = 26;

    private const EVIDENCE_SECTION_KEY = 'evidence_and_limitations';

    private const ALLOWED_ENTITY_TYPES = [
        PersonalityPublicContentAsset::ENTITY_HUB,
        PersonalityPublicContentAsset::ENTITY_CENTER,
        PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
    ];

    private const FORBIDDEN_ROUTE_PATTERNS = [
        '#/results?(?:/|$)#i',
        '#/orders?(?:/|$)#i',
        '#/share(?:/|$)#i',
        '#/pay(?:/|$)#i',
        '#/payment(?:/|$)#i',
        '#/history(?:/|$)#i',
        '#/private(?:/|$)#i',
        '#/account(?:/|$)#i',
        '#[?&](?:token|session|user|result_id|report_id|order_no)=#i',
    ];

    /**
     * @param  array<string,array<string,mixed>>  $packages
     * @param  array<string,array<string,mixed>>  $ledgers
     * @param  array<string,string>  $packageHashes
     * @return array<string,mixed>
     */
    public function planEvidenceRefresh(array $packages, array $ledgers, array $packageHashes, string $deployedSha): array
    {
        return $this->buildEvidenceRefreshSummary($packages, $ledgers, $packageHashes, $deployedSha, false, null, null, false);
    }

    /**
     * @param  array<string,array<string,mixed>>  $packages
     * @param  array<string,array<string,mixed>>  $ledgers
     * @param  array<string,string>  $packageHashes
     * @return array<string,mixed>
     */
    public function writeEvidenceRefresh(
        array $packages,
        array $ledgers,
        array $packageHashes,
        string $deployedSha,
        string $confirmedCohortSha256,
        string $operatorToken,
        bool $writeEnabled,
    ): array {
        return DB::transaction(fn (): array => $this->buildEvidenceRefreshSummary(
            $packages,
            $ledgers,
            $packageHashes,
            $deployedSha,
            true,
            $confirmedCohortSha256,
            $operatorToken,
            $writeEnabled,
        ));
    }

    /**
     * @param  array<string,array<string,mixed>>  $packages
     * @param  array<string,array<string,mixed>>  $ledgers
     * @param  array<string,string>  $packageHashes
     * @return array<string,mixed>
     */
    private function buildEvidenceRefreshSummary(
        array $packages,
        array $ledgers,
        array $packageHashes,
        string $deployedSha,
        bool $write,
        ?string $confirmedCohortSha256,
        ?string $operatorToken,
        bool $writeEnabled,
    ): array {
        $issues = [];
        $rows = [];
        $identities = [];
        if (preg_match('/^[0-9a-f]{40}$/', $deployedSha) !== 1) {
            $issues[] = 'deployed_sha_invalid';
        }

        foreach (['en', 'zh-CN'] as $locale) {
            $package = $packages[$locale] ?? [];
            $ledger = $ledgers[$locale] ?? [];
            $packageHash = strtolower(trim((string) ($packageHashes[$locale] ?? '')));
            $ledgerIds = $this->evidenceLedgerSourceIds($ledger);
            if (($package['locale'] ?? null) !== $locale || ($package['page_count'] ?? null) !== 13) {
                $issues[] = $locale.':package_shape_invalid';
            }
            if (preg_match('/^[0-9a-f]{64}$/', $packageHash) !== 1) {
                $issues[] = $locale.':package_hash_invalid';
            }
            if (($package['source_audit'] ?? null) !== ($ledger['artifact'] ?? null) || count($ledgerIds) < 2) {
                $issues[] = $locale.':ledger_binding_invalid';
            }

            foreach ((array) ($package['recommendations'] ?? []) as $position => $recommendation) {
                if (! is_array($recommendation)) {
                    $issues[] = $locale.':recommendation_invalid';

                    continue;
                }
                $identity = $this->identityForRecommendation($recommendation);
                if ($identity === null || $identity['locale'] !== $locale
                    || ! in_array($identity['entity_type'], self::ALLOWED_ENTITY_TYPES, true)) {
                    $issues[] = $locale.':'.((string) $position).':identity_invalid';

                    continue;
                }
                $identityKey = $identity['locale'].'|'.$identity['entity_type'].'|'.$identity['entity_key'];
                if (isset($identities[$identityKey])) {
                    $issues[] = $identityKey.':duplicate_identity';

                    continue;
                }
                $identities[$identityKey] = true;
                $payload = is_array($recommendation['recommendations'] ?? null) ? $recommendation['recommendations'] : [];
                $notes = array_values(array_filter(
                    is_array($payload['evidence_notes'] ?? null) ? $payload['evidence_notes'] : [],
                    static fn (mixed $note): bool => is_array($note)
                ));
                $section = $this->evidenceSection((array) ($payload['sections'] ?? []));
                $sourceIds = array_values(array_unique(array_filter(array_map(
                    static fn (array $note): string => trim((string) ($note['source_id'] ?? '')),
                    $notes
                ))));
                if ($section === null || $this->evidenceVisibleLength($section['body_md'] ?? null) < 120) {
                    $issues[] = $identityKey.':visible_evidence_incomplete';
                }
                if (count($sourceIds) < 2 || array_diff($sourceIds, $ledgerIds) !== []) {
                    $issues[] = $identityKey.':source_ids_invalid';
                }
                foreach ($notes as $note) {
                    if ($this->evidenceVisibleLength($note['claim'] ?? null) < 20
                        || $this->evidenceVisibleLength($note['limitation'] ?? null) < 20) {
                        $issues[] = $identityKey.':claim_or_limitation_incomplete';
                        break;
                    }
                }
                $evidenceJson = $this->jsonString(['section' => $section, 'notes' => $notes]);
                if ($this->containsForbiddenRoutePattern($evidenceJson)) {
                    $issues[] = $identityKey.':private_or_sensitive_evidence';
                }

                $asset = $this->existingAsset($identity);
                if (! $asset instanceof PersonalityPublicContentAsset) {
                    $issues[] = $identityKey.':asset_missing';

                    continue;
                }
                if (! $this->evidenceRefreshStateIsSafe($asset)) {
                    $issues[] = $identityKey.':asset_state_forbidden';
                }
                $candidate = [
                    'content_sections_json' => $this->mergeEvidenceSection((array) $asset->content_sections_json, $section),
                    'evidence_notes_json' => $notes,
                    'source_package' => 'enneagram_en13_evidence_v1:'.$locale,
                    'source_hash' => $packageHash,
                    'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
                ];
                $rows[] = [
                    'identity' => $identityKey,
                    'asset_id' => (int) $asset->id,
                    'source_hash' => $packageHash,
                    'evidence_sha256' => hash('sha256', $this->jsonString($notes)),
                    'visible_evidence_sha256' => hash('sha256', $this->jsonString((array) $section)),
                    'already_current' => $this->evidenceRefreshMatches($asset, $candidate),
                    'candidate' => $candidate,
                ];
            }
        }

        if (count($rows) !== self::EVIDENCE_REFRESH_EXPECTED_COUNT || count($identities) !== self::EVIDENCE_REFRESH_EXPECTED_COUNT) {
            $issues[] = 'exact_26_identity_count_required';
        }
        $cohortRows = array_map(static fn (array $row): array => [
            'identity' => $row['identity'],
            'source_hash' => $row['source_hash'],
            'evidence_sha256' => $row['evidence_sha256'],
            'visible_evidence_sha256' => $row['visible_evidence_sha256'],
        ], $rows);
        usort($cohortRows, static fn (array $left, array $right): int => $left['identity'] <=> $right['identity']);
        $cohortSha256 = hash('sha256', $this->jsonString($cohortRows));

        if ($write) {
            if (! $writeEnabled) {
                $issues[] = 'process_write_gate_disabled';
            }
            if (! is_string($confirmedCohortSha256) || ! hash_equals($cohortSha256, strtolower(trim($confirmedCohortSha256)))) {
                $issues[] = 'cohort_sha256_confirmation_mismatch';
            }
            $expectedToken = 'ENNEAGRAM-EN13-EVIDENCE-CMS-REFRESH-01:'.$deployedSha.':'.$cohortSha256;
            if (! is_string($operatorToken) || ! hash_equals($expectedToken, trim($operatorToken))) {
                $issues[] = 'operator_approval_mismatch';
            }
        }

        $issues = array_values(array_unique($issues));
        sort($issues);
        if ($issues !== []) {
            return $this->evidenceRefreshPayload('blocked', $write, $deployedSha, $cohortSha256, $rows, $issues, 0);
        }
        $alreadyCurrent = count(array_filter($rows, static fn (array $row): bool => $row['already_current']));
        if (! $write) {
            return $this->evidenceRefreshPayload(
                $alreadyCurrent === self::EVIDENCE_REFRESH_EXPECTED_COUNT ? 'already_refreshed' : 'dry_run_ready',
                false,
                $deployedSha,
                $cohortSha256,
                $rows,
                [],
                0,
            );
        }

        $updated = 0;
        foreach ($rows as $row) {
            if ($row['already_current']) {
                continue;
            }
            PersonalityPublicContentAsset::query()->withoutGlobalScopes()->whereKey($row['asset_id'])->update($row['candidate']);
            $updated++;
        }

        return $this->evidenceRefreshPayload(
            $updated === 0 ? 'already_refreshed' : 'refreshed',
            true,
            $deployedSha,
            $cohortSha256,
            $rows,
            [],
            $updated,
        );
    }

    /** @return list<string> */
    private function evidenceLedgerSourceIds(array $ledger): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $source): string => is_array($source) ? trim((string) ($source['id'] ?? '')) : '',
            (array) ($ledger['sources'] ?? [])
        ))));
    }

    private function evidenceSection(array $sections): ?array
    {
        $matches = array_values(array_filter(
            $sections,
            static fn (mixed $section): bool => is_array($section) && ($section['key'] ?? null) === self::EVIDENCE_SECTION_KEY
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function mergeEvidenceSection(array $existing, ?array $evidence): array
    {
        if ($evidence === null) {
            return $existing;
        }
        $sections = array_values(array_filter(
            $existing,
            static fn (mixed $section): bool => ! is_array($section) || ($section['key'] ?? null) !== self::EVIDENCE_SECTION_KEY
        ));
        $index = array_search('method_boundary', array_map(
            static fn (mixed $section): mixed => is_array($section) ? ($section['key'] ?? null) : null,
            $sections
        ), true);
        array_splice($sections, $index === false ? count($sections) : $index, 0, [$evidence]);

        return $sections;
    }

    private function evidenceRefreshStateIsSafe(PersonalityPublicContentAsset $asset): bool
    {
        return $asset->is_public
            && $asset->launch_state === PersonalityPublicContentAsset::LAUNCH_PUBLISHED
            && $asset->robots === PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW
            && $asset->index_eligible && $asset->sitemap_eligible && ! $asset->llms_eligible;
    }

    private function evidenceRefreshMatches(PersonalityPublicContentAsset $asset, array $candidate): bool
    {
        foreach ($candidate as $field => $value) {
            if ($asset->getAttribute($field) !== $value) {
                return false;
            }
        }

        return true;
    }

    private function evidenceVisibleLength(mixed $value): int
    {
        return is_scalar($value) ? mb_strlen(trim(strip_tags((string) $value))) : 0;
    }

    /** @return array<string,mixed> */
    private function evidenceRefreshPayload(string $status, bool $write, string $deployedSha, string $cohortSha256, array $rows, array $issues, int $updated): array
    {
        return [
            'schema_version' => 'enneagram-en13-evidence-refresh.v1',
            'ok' => in_array($status, ['dry_run_ready', 'refreshed', 'already_refreshed'], true),
            'status' => $status,
            'dry_run' => ! $write,
            'write_requested' => $write,
            'deployed_sha' => $deployedSha,
            'cohort_sha256' => $cohortSha256,
            'target_count' => count($rows),
            'already_current_count' => count(array_filter($rows, static fn (array $row): bool => $row['already_current'])),
            'updated_count' => $updated,
            'issues' => $issues,
            'negative_guarantees' => [
                'publish_state_write' => false,
                'robots_index_sitemap_write' => false,
                'llms_eligibility_write' => false,
                'review_state_write' => false,
                'search_queue_or_submit' => false,
                'cache_warm' => false,
                'deploy' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return array<string,mixed>
     */
    public function plan(array $package, array $qa, string $sourceSha256, string $qaSha256, bool $updateExisting = false): array
    {
        return $this->buildSummary($package, $qa, $sourceSha256, $qaSha256, false, $updateExisting);
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return array<string,mixed>
     */
    public function write(array $package, array $qa, string $sourceSha256, string $qaSha256, bool $updateExisting = false): array
    {
        return DB::transaction(fn (): array => $this->buildSummary($package, $qa, $sourceSha256, $qaSha256, true, $updateExisting));
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return array<string,mixed>
     */
    private function buildSummary(array $package, array $qa, string $sourceSha256, string $qaSha256, bool $write, bool $updateExisting = false): array
    {
        $qaRows = $this->qaRowsByUrl($qa);
        $errors = [];
        $rows = [];

        foreach ($this->recommendations($package) as $position => $recommendation) {
            $identity = $this->identityForRecommendation($recommendation);
            $targetUrl = (string) ($recommendation['target_url'] ?? '');
            $qaRow = $qaRows[$targetUrl] ?? [];
            $recommendationJson = $this->jsonString($recommendation);

            if ($identity === null) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position).'.target_url',
                    'code' => 'unsupported_enneagram_target_url',
                    'message' => 'Only Enneagram hub, center, and core type public URLs are supported.',
                ];

                continue;
            }

            if ($this->containsForbiddenRoutePattern($targetUrl) || $this->containsForbiddenRoutePattern($recommendationJson)) {
                $errors[] = [
                    'field' => 'recommendations.'.((string) $position),
                    'code' => 'forbidden_private_route_pattern_present',
                    'message' => 'Recommendation contains a private/result/order/payment/account/share route or sensitive query key.',
                ];
            }

            if ($qaRow === [] || ! $this->qaDecisionPasses((string) ($qaRow['decision'] ?? $qaRow['status'] ?? $qaRow['qa_status'] ?? ''))) {
                $errors[] = [
                    'field' => 'qa.'.((string) $targetUrl),
                    'code' => 'qa_pass_required',
                    'message' => 'Every Enneagram draft row requires a matching PASS QA row.',
                ];
            }

            if ((array) ($qaRow['blockers'] ?? []) !== []) {
                $errors[] = [
                    'field' => 'qa.'.((string) $targetUrl).'.blockers',
                    'code' => 'qa_blockers_present',
                    'message' => 'QA blockers prevent CMS draft planning.',
                ];
            }

            $assetPayload = $this->assetPayload($recommendation, $identity, $sourceSha256, $qaSha256);
            $existing = $this->existingAsset($identity);
            if ($existing instanceof PersonalityPublicContentAsset && ! $this->existingAssetIsWritableDraft($existing, $sourceSha256)) {
                if ($updateExisting && $this->assetIsUpdatableContentReady($existing)) {
                    // Allow: updating an existing content_ready asset with new content.
                } else {
                    $errors[] = [
                        'field' => 'recommendations.'.((string) $position).'.target_url',
                        'code' => 'existing_live_or_foreign_asset_blocks_draft_write',
                        'message' => 'Existing public/content-ready/published/indexable or foreign-source asset blocks draft-only writes. Use --update-existing to backfill content_ready assets.',
                    ];
                }
            }

            if ($errors === [] || $write) {
                // Determine action for existing assets
                $action = 'create_draft_asset';
                if ($existing instanceof PersonalityPublicContentAsset) {
                    if ($updateExisting && $this->assetIsUpdatableContentReady($existing)) {
                        $action = $this->contentReadyAssetMatchesPayload($existing, $assetPayload)
                            ? 'skip_existing_content_ready_same_source'
                            : 'update_existing_content_ready';
                    } else {
                        $action = $this->existingAssetIsWritableDraft($existing, $sourceSha256)
                            ? 'skip_existing_same_source_draft'
                            : 'blocked_existing';
                    }
                }
            }

            $rows[] = [
                'position' => $position + 1,
                'url' => $targetUrl,
                'path' => $identity['path'],
                'locale' => $identity['locale'],
                'entity_type' => $identity['entity_type'],
                'entity_key' => $identity['entity_key'],
                'slug' => $identity['slug'],
                'source_sha256' => $sourceSha256,
                'qa_source_sha256' => $qaSha256,
                'recommendation_sha256' => hash('sha256', $recommendationJson),
                'existing_asset_id' => $existing?->id !== null ? (int) $existing->id : null,
                'action' => $action,
                'asset_preview' => $assetPayload,
            ];
        }

        if ($errors !== []) {
            return array_merge($this->baseSummary($package, $qa, $sourceSha256, $qaSha256, $write), [
                'ok' => false,
                'status' => 'fail',
                'row_count' => count($rows),
                'hub_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_HUB),
                'center_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_CENTER),
                'core_type_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_CORE_TYPE),
                'wing_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_WING),
                'instinctual_subtype_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_INSTINCTUAL_SUBTYPE),
                'would_create_asset_count' => 0,
                'created_asset_count' => 0,
                'skipped_existing_count' => 0,
                'rows' => $rows,
                'errors' => $errors,
                'warnings' => [],
            ]);
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        if ($write) {
            foreach ($rows as &$row) {
                if (in_array($row['action'], [
                    'skip_existing_same_source_draft',
                    'skip_existing_content_ready_same_source',
                ], true)) {
                    $row['action'] = 'skipped_existing';
                    $skipped++;

                    continue;
                }

                if ($row['action'] === 'blocked_existing') {
                    continue;
                }

                if ($row['action'] === 'update_existing_content_ready' && ($row['existing_asset_id'] ?? null) !== null) {
                    $payload = $this->contentReadyUpdatePayload((array) $row['asset_preview']);
                    PersonalityPublicContentAsset::query()
                        ->withoutGlobalScopes()
                        ->whereKey((int) $row['existing_asset_id'])
                        ->update($payload);
                    $row['action'] = 'updated_content_ready_asset';
                    $updated++;

                    continue;
                }

                PersonalityPublicContentAsset::query()->create((array) $row['asset_preview']);
                $row['action'] = 'created_draft_asset';
                $created++;
            }
            unset($row);
        }

        return array_merge($this->baseSummary($package, $qa, $sourceSha256, $qaSha256, $write), [
            'ok' => true,
            'status' => 'pass',
            'row_count' => count($rows),
            'hub_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_HUB),
            'center_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_CENTER),
            'core_type_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_CORE_TYPE),
            'wing_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_WING),
            'instinctual_subtype_row_count' => $this->countRows($rows, PersonalityPublicContentAsset::ENTITY_INSTINCTUAL_SUBTYPE),
            'would_create_asset_count' => $write ? 0 : count(array_filter($rows, static fn (array $row): bool => ($row['existing_asset_id'] ?? null) === null)),
            'created_asset_count' => $created,
            'updated_asset_count' => $updated,
            'skipped_existing_count' => $skipped,
            'writes_committed' => $write && ($created > 0 || $updated > 0),
            'rows' => $rows,
            'errors' => [],
            'warnings' => [],
        ]);
    }

    /**
     * @param  array<string,mixed>  $package
     * @return list<array<string,mixed>>
     */
    private function recommendations(array $package): array
    {
        return array_values(array_filter(
            is_array($package['recommendations'] ?? null) ? $package['recommendations'] : [],
            static fn (mixed $item): bool => is_array($item)
        ));
    }

    /**
     * @param  array<string,mixed>  $qa
     * @return array<string,array<string,mixed>>
     */
    private function qaRowsByUrl(array $qa): array
    {
        $rows = [];
        foreach ([$qa['page_results'] ?? null, $qa['results'] ?? null, $qa['items'] ?? null] as $source) {
            if (! is_array($source)) {
                continue;
            }

            foreach ($source as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $url = (string) ($item['target_url'] ?? $item['url'] ?? '');
                if ($url !== '') {
                    $rows[$url] = $item;
                }
            }
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $recommendation
     * @return array{path:string,locale:string,entity_type:string,entity_key:string,slug:string}|null
     */
    private function identityForRecommendation(array $recommendation): ?array
    {
        $framework = (string) ($recommendation['framework'] ?? '');
        if ($framework !== PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM) {
            return null;
        }

        $path = (string) parse_url((string) ($recommendation['target_url'] ?? ''), PHP_URL_PATH);
        if ($path === '') {
            return null;
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram$#i', $path, $matches) === 1) {
            return [
                'path' => $path,
                'locale' => $this->localeFromPrefix((string) $matches['prefix']),
                'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
                'entity_key' => 'enneagram',
                'slug' => 'enneagram',
            ];
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram/centers/(?<code>gut|heart|head)$#i', $path, $matches) === 1) {
            $code = strtolower((string) $matches['code']);

            return [
                'path' => $path,
                'locale' => $this->localeFromPrefix((string) $matches['prefix']),
                'entity_type' => PersonalityPublicContentAsset::ENTITY_CENTER,
                'entity_key' => $code,
                'slug' => 'enneagram/centers/'.$code,
            ];
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram/type-(?<type>[1-9])$#i', $path, $matches) === 1) {
            $code = 'type-'.((string) $matches['type']);

            return [
                'path' => $path,
                'locale' => $this->localeFromPrefix((string) $matches['prefix']),
                'entity_type' => PersonalityPublicContentAsset::ENTITY_CORE_TYPE,
                'entity_key' => $code,
                'slug' => 'enneagram/'.$code,
            ];
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram/wings/(?<code>[1-9]w[1-9])$#i', $path, $matches) === 1) {
            $code = strtolower((string) $matches['code']);

            return [
                'path' => $path,
                'locale' => $this->localeFromPrefix((string) $matches['prefix']),
                'entity_type' => PersonalityPublicContentAsset::ENTITY_WING,
                'entity_key' => $code,
                'slug' => 'enneagram/wings/'.$code,
            ];
        }

        if (preg_match('#^/(?<prefix>en|zh)/personality/enneagram/type-(?<type>[1-9])/instincts/(?<subtype>self-preservation|social|one-to-one)$#i', $path, $matches) === 1) {
            $type = 'type-'.((string) $matches['type']);
            $subtype = strtolower((string) $matches['subtype']);

            return [
                'path' => $path,
                'locale' => $this->localeFromPrefix((string) $matches['prefix']),
                'entity_type' => PersonalityPublicContentAsset::ENTITY_INSTINCTUAL_SUBTYPE,
                'entity_key' => $type.'/'.$subtype,
                'slug' => 'enneagram/'.$type.'/instincts/'.$subtype,
            ];
        }

        return null;
    }

    /**
     * @param  array{locale:string,entity_type:string,entity_key:string}  $identity
     */
    private function existingAsset(array $identity): ?PersonalityPublicContentAsset
    {
        return PersonalityPublicContentAsset::query()
            ->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM)
            ->where('entity_type', $identity['entity_type'])
            ->where('entity_key', $identity['entity_key'])
            ->where('locale', $identity['locale'])
            ->first();
    }

    private function existingAssetIsWritableDraft(PersonalityPublicContentAsset $asset, string $sourceSha256): bool
    {
        return (string) $asset->source_hash === $sourceSha256
            && (bool) $asset->is_public === false
            && (bool) $asset->index_eligible === false
            && (bool) $asset->sitemap_eligible === false
            && (bool) $asset->llms_eligible === false
            && in_array((string) $asset->launch_state, [
                PersonalityPublicContentAsset::LAUNCH_DRAFT,
                PersonalityPublicContentAsset::LAUNCH_REVIEW,
            ], true);
    }

    private function assetIsUpdatableContentReady(PersonalityPublicContentAsset $asset): bool
    {
        return (bool) $asset->is_public === true
            && (bool) $asset->index_eligible === false
            && (bool) $asset->sitemap_eligible === false
            && (bool) $asset->llms_eligible === false
            && $asset->launch_state === PersonalityPublicContentAsset::LAUNCH_CONTENT_READY
            && $asset->robots === PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW;
    }

    /**
     * @param  array<string,mixed>  $assetPayload
     */
    private function contentReadyAssetMatchesPayload(PersonalityPublicContentAsset $asset, array $assetPayload): bool
    {
        foreach ($this->contentReadyUpdatePayload($assetPayload) as $field => $expectedValue) {
            if ($asset->getAttribute($field) !== $expectedValue) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $assetPayload
     * @return array<string,mixed>
     */
    private function contentReadyUpdatePayload(array $assetPayload): array
    {
        unset(
            $assetPayload['org_id'], $assetPayload['framework'], $assetPayload['entity_type'], $assetPayload['entity_key'],
            $assetPayload['slug'], $assetPayload['locale'],
            $assetPayload['is_public'], $assetPayload['index_eligible'], $assetPayload['sitemap_eligible'],
            $assetPayload['llms_eligible'], $assetPayload['launch_state'], $assetPayload['review_state'],
            $assetPayload['robots'], $assetPayload['last_reviewed_at'],
        );

        return $assetPayload;
    }

    /**
     * @param  array<string,mixed>  $recommendation
     * @param  array{path:string,locale:string,entity_type:string,entity_key:string,slug:string}  $identity
     * @return array<string,mixed>
     */
    private function assetPayload(array $recommendation, array $identity, string $sourceSha256, string $qaSha256): array
    {
        $recommendations = is_array($recommendation['recommendations'] ?? null) ? $recommendation['recommendations'] : [];
        $title = trim((string) ($recommendations['h1'] ?? $recommendations['title'] ?? 'Enneagram public profile draft'));
        $seoTitle = trim((string) ($recommendations['title'] ?? $title));
        $description = trim((string) ($recommendations['description'] ?? ''));
        $quickAnswer = trim((string) ($recommendations['quick_answer'] ?? ''));

        return [
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'entity_type' => $identity['entity_type'],
            'entity_key' => $identity['entity_key'],
            'slug' => $identity['slug'],
            'locale' => $identity['locale'],
            'title' => $title,
            'summary' => $quickAnswer !== '' ? $quickAnswer : $description,
            'content_sections_json' => $this->contentSections($quickAnswer, $recommendations),
            'seo_json' => [
                'title' => $seoTitle,
                'description' => $description,
            ],
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical_json' => [
                'path' => $identity['path'],
            ],
            'hreflang_json' => [],
            'faq_json' => array_values(is_array($recommendations['faq'] ?? null) ? $recommendations['faq'] : []),
            'media_json' => [],
            'schema_json' => [],
            'method_boundary_json' => [
                'summary' => 'Enneagram public profile drafts are reflective educational content only; they are not clinical diagnosis, hiring screening, official affiliation, or deterministic guidance.',
                'not_for' => ['clinical diagnosis', 'employment screening', 'deterministic decisions'],
            ],
            'evidence_notes_json' => $this->evidenceNotes($recommendations, $sourceSha256, $qaSha256),
            'internal_links_json' => array_values(is_array($recommendations['internal_links'] ?? null) ? $recommendations['internal_links'] : []),
            'is_public' => false,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_REVIEW,
            'review_state' => 'agent_draft_pending_review',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'source_package' => self::SNAPSHOT_SOURCE,
            'source_hash' => $sourceSha256,
            'last_reviewed_at' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $recommendations
     * @return list<array<string,mixed>>
     */
    private function contentSections(string $quickAnswer, array $recommendations): array
    {
        // Generic sections array takes priority when present.
        $genericSections = $recommendations['sections'] ?? null;
        if (is_array($genericSections) && $genericSections !== []) {
            return $this->normalizeSections($genericSections);
        }

        // Legacy: quick_answer + differentiation_notes
        $sections = [];
        if ($quickAnswer !== '') {
            $sections[] = [
                'key' => 'quick_answer',
                'title' => 'Quick answer',
                'body_md' => $quickAnswer,
            ];
        }

        $differentiationNotes = $recommendations['differentiation_notes'] ?? [];
        if (is_scalar($differentiationNotes)) {
            $differentiationNotes = [$differentiationNotes];
        }

        foreach (array_values(is_array($differentiationNotes) ? $differentiationNotes : []) as $index => $note) {
            if (! is_scalar($note) || trim((string) $note) === '') {
                continue;
            }

            $sections[] = [
                'key' => 'differentiation_'.((string) ($index + 1)),
                'title' => 'Differentiation note',
                'body_md' => trim((string) $note),
            ];
        }

        return $sections;
    }

    /**
     * @param  array<string,mixed>  $recommendations
     * @return list<array<string,mixed>>
     */
    private function evidenceNotes(array $recommendations, string $sourceSha256, string $qaSha256): array
    {
        $notes = array_values(array_filter(
            is_array($recommendations['evidence_notes'] ?? null) ? $recommendations['evidence_notes'] : [],
            static fn (mixed $note): bool => is_array($note)
        ));
        if ($notes === []) {
            $notes = [[
                'source_type' => 'agent_recommendation',
                'source' => self::SNAPSHOT_SOURCE,
            ]];
        }

        return array_map(static fn (array $note): array => [
            ...$note,
            'package_sha256' => $sourceSha256,
            'qa_sha256' => $qaSha256,
        ], $notes);
    }

    /**
     * @param  list<array{key?:string,title?:string,body_md?:string,body_html?:string}>  $sections
     * @return list<array{key:string,title:string,body_md:string}>
     */
    private function normalizeSections(array $sections): array
    {
        return array_values(array_filter(
            array_map(static function (array $section): array {
                return [
                    'key' => trim((string) ($section['key'] ?? '')),
                    'title' => trim((string) ($section['title'] ?? '')),
                    'body_md' => trim((string) ($section['body_md'] ?? $section['body_html'] ?? '')),
                ];
            }, $sections),
            static fn (array $section): bool => $section['key'] !== ''
        ));
    }

    private function qaDecisionPasses(string $decision): bool
    {
        return in_array($decision, [
            'pass',
            'PASS',
            'PASS_READY_FOR_CMS_DRAFT',
            'PASS_READY_FOR_APPROVAL_QUEUE',
        ], true);
    }

    private function countRows(array $rows, string $entityType): int
    {
        return count(array_filter($rows, static fn (array $row): bool => ($row['entity_type'] ?? null) === $entityType));
    }

    private function containsForbiddenRoutePattern(string $value): bool
    {
        foreach (self::FORBIDDEN_ROUTE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    private function localeFromPrefix(string $prefix): string
    {
        return $prefix === 'zh' ? 'zh-CN' : 'en';
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return array<string,mixed>
     */
    private function baseSummary(array $package, array $qa, string $sourceSha256, string $qaSha256, bool $write): array
    {
        return [
            'artifact' => 'ENNEAGRAM-CMS-DRAFT-WRITER-CONTRACT-01',
            'status' => 'pending',
            'ok' => false,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_ENNEAGRAM,
            'package_artifact' => (string) ($package['artifact'] ?? ''),
            'qa_artifact' => (string) ($qa['artifact'] ?? ''),
            'source_sha256' => $sourceSha256,
            'qa_sha256' => $qaSha256,
            'dry_run' => ! $write,
            'write' => $write,
            'writes_attempted' => $write,
            'writes_committed' => false,
            'cms_write_attempted' => $write,
            'cms_mutation_attempted' => $write,
            'publish_attempted' => false,
            'index_attempted' => false,
            'sitemap_llms_release_attempted' => false,
            'search_release_attempted' => false,
            'enqueue_attempted' => false,
            'external_calls_attempted' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function jsonString(array $value): string
    {
        return (string) json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
}
