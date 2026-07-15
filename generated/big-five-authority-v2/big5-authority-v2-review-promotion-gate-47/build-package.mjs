import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const directory = dirname(fileURLToPath(import.meta.url));
const root = resolve(directory, '../../..');
const generatedAt = '2026-07-15T09:38:54Z';
const authorityPackageSha256 = 'fb67edc033e679da3f134b34db30901465c7b44e0585818b23613fab83bf9162';
const sourcePath = 'generated/big-five-authority-v2/big5-authority-v2-release-gate-37/draft-import-package.json';
const source = JSON.parse(readFileSync(resolve(root, sourcePath), 'utf8'));
const existingArticleSlugs = new Set([
  'big-five-conscientiousness-low-procrastination-task-plan',
  'big-five-emotional-stability-stress-recovery-communication',
  'big-five-personality-test-vs-mbti',
  'big-five-growth-guide',
  'big-five-narrative-portrait',
  'big-five-tool-guide',
]);

const sha256 = (value) => createHash('sha256').update(value).digest('hex');
const fileSha256 = (path) => sha256(readFileSync(resolve(root, path)));
const json = (value) => `${JSON.stringify(value, null, 2)}\n`;
const writeJson = (name, value) => writeFileSync(resolve(directory, name), json(value));
const surfaceKey = (surface) => surface.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');

const sourceArtifacts = [
  sourcePath,
  'generated/big-five-authority-v2/big5-authority-v2-media-authority-41/mapping-package.json',
  'generated/big-five-authority-v2/big5-authority-v2-visible-date-42/visible-date-findings.json',
  'generated/big-five-authority-v2/big5-authority-v2-visible-provenance-43/visible-provenance-findings.json',
  'generated/big-five-authority-v2/big5-authority-v2-discoverability-parity-44/discoverability-parity-findings.json',
  'generated/big-five-authority-v2/big5-authority-v2-structured-data-45/structured-data-findings.json',
  'generated/big-five-authority-v2/big5-authority-v2-topic-authority-46/topic-draft-revision-package.json',
].map((path) => ({ path, sha256: fileSha256(path) }));

const rows = source.assets.map((asset) => {
  const isShell = asset.authority_surface === 'CMS landing_surfaces/page_blocks';
  const isExisting = asset.authority_surface === 'CMS personality_public_content_assets'
    || asset.authority_surface === 'CMS topic_profiles'
    || (asset.authority_surface === 'CMS Article' && existingArticleSlugs.has(asset.draft_payload?.slug));

  return {
    asset_id: asset.asset_id,
    route: asset.route,
    locale: asset.locale,
    page_family: asset.page_family,
    authority_surface: asset.authority_surface,
    source_package: asset.source_package,
    source_hash: asset.source_hash,
    authority_package_sha256: authorityPackageSha256,
    action_contract: {
      primary_create: !isExisting,
      existing_revision: isExisting,
      revision_create: !isShell,
      product_shell_preserved: isShell,
    },
    expected_runtime: {
      bound: false,
      primary_id: null,
      working_revision_id: null,
      published_revision_id: null,
      public_runtime_baseline_sha256: null,
    },
    manual_review: {
      status: 'pending_manual_review',
      reviewer_id: null,
      reviewed_at: null,
      review_record_sha256: null,
    },
    permissions: {
      source: { approved: false, approval_reference: null },
      media: { approved: false, approval_reference: null },
    },
    promotion: {
      cohort_id: null,
      eligible: false,
      exact_authorization_required: !isShell,
    },
    blockers: isShell
      ? ['product_shell_must_remain_preserved', 'runtime_baseline_unbound']
      : ['manual_review_missing', 'reviewer_missing', 'review_date_missing', 'source_permission_missing', 'media_permission_missing', 'runtime_identity_unbound', 'rollback_target_unbound', 'cohort_authorization_missing'],
  };
});

const cohortGroups = new Map();
for (const row of rows.filter((candidate) => candidate.action_contract.revision_create)) {
  const key = `${row.authority_surface}|${row.locale}`;
  const group = cohortGroups.get(key) ?? [];
  group.push(row);
  cohortGroups.set(key, group);
}

const cohorts = [];
for (const key of [...cohortGroups.keys()].sort()) {
  const group = cohortGroups.get(key).sort((a, b) => a.asset_id.localeCompare(b.asset_id));
  const [surface, locale] = key.split('|');
  for (let offset = 0; offset < group.length; offset += 25) {
    const members = group.slice(offset, offset + 25);
    const cohortId = `${surfaceKey(surface)}_${locale.toLowerCase().replace(/[^a-z0-9]+/g, '_')}_${String((offset / 25) + 1).padStart(2, '0')}`;
    const assetIds = members.map((member) => member.asset_id);
    const cohortSha256 = sha256(JSON.stringify(assetIds));
    for (const member of members) member.promotion.cohort_id = cohortId;
    cohorts.push({
      cohort_id: cohortId,
      authority_surface: surface,
      locale,
      asset_count: assetIds.length,
      asset_ids: assetIds,
      cohort_sha256: cohortSha256,
      abort_on_any_mismatch: true,
    });
  }
}

const actionCounts = rows.reduce((counts, row) => {
  for (const [key, enabled] of Object.entries(row.action_contract)) if (enabled) counts[key] += 1;
  return counts;
}, { primary_create: 0, existing_revision: 0, revision_create: 0, product_shell_preserved: 0 });

const reviewManifest = {
  schema_version: 'big5-authority-v2-review-manifest.v1',
  generated_at: generatedAt,
  status: 'HOLD_PENDING_MANUAL_REVIEW_AND_RUNTIME_BINDING',
  mode: 'review_manifest_template_zero_write',
  authority: 'CMS/backend only',
  source_artifacts: sourceArtifacts,
  authority_package_sha256: authorityPackageSha256,
  source_package_path: sourcePath,
  source_package_file_sha256: fileSha256(sourcePath),
  counts: {
    assets: rows.length,
    ...actionCounts,
    cohorts: cohorts.length,
    promotion_eligible: 0,
  },
  invariants: {
    public_reader_selects_working_revision: false,
    abort_on_any_mismatch: true,
    exact_cohort_authorization_required: true,
    production_promotion_currently_authorized: false,
  },
  cohorts,
  rows,
};
writeJson('review-manifest.json', reviewManifest);
const reviewManifestSha256 = fileSha256('generated/big-five-authority-v2/big5-authority-v2-review-promotion-gate-47/review-manifest.json');

const rollbackPlan = {
  schema_version: 'big5-authority-v2-promotion-rollback-plan.v1',
  generated_at: generatedAt,
  status: 'HOLD_PENDING_EXACT_RUNTIME_TARGETS',
  review_manifest_sha256: reviewManifestSha256,
  abort_on_missing_target: true,
  execution_implemented: false,
  rows: rows.map((row) => ({
    asset_id: row.asset_id,
    action: row.action_contract.product_shell_preserved
      ? 'preserve_product_shell_without_mutation'
      : row.action_contract.existing_revision
        ? 'restore_exact_published_revision_and_runtime_baseline'
        : 'restore_unpublished_draft_and_clear_published_pointer',
    primary_id: null,
    restore_published_revision_id: null,
    restore_public_runtime_baseline_sha256: null,
    exact_target_bound: false,
  })),
  effects: {
    database_writes: 0,
    promotions: 0,
    rollbacks: 0,
    public_release_changes: 0,
    indexability_changes: 0,
  },
};
writeJson('rollback-plan.json', rollbackPlan);
const rollbackPlanSha256 = fileSha256('generated/big-five-authority-v2/big5-authority-v2-review-promotion-gate-47/rollback-plan.json');

const authorizationPacket = {
  schema_version: 'big5-authority-v2-cohort-promotion-authorization.v1',
  generated_at: generatedAt,
  status: 'HOLD_PENDING_MANUAL_REVIEW_RUNTIME_PREFLIGHT_AND_EXACT_COHORT_AUTHORIZATION',
  review_manifest_sha256: reviewManifestSha256,
  rollback_plan_sha256: rollbackPlanSha256,
  authority_package_sha256: authorityPackageSha256,
  deployed_sha: null,
  promotion_preflight_fingerprint: null,
  production_promotion_currently_authorized: false,
  approval_phrases_currently_executable: false,
  cohorts: cohorts.map((cohort) => ({
    cohort_id: cohort.cohort_id,
    cohort_sha256: cohort.cohort_sha256,
    asset_count: cohort.asset_count,
    authorized: false,
    exact_authorization: null,
  })),
};
writeJson('authorization-packet-template.json', authorizationPacket);

const preflightReport = {
  schema_version: 'big5-authority-v2-review-promotion-preflight-report.v1',
  generated_at: generatedAt,
  status: 'HOLD_FAIL_CLOSED_PENDING_REVIEW_AND_AUTHORIZATION',
  mode: 'package_only_zero_write',
  review_manifest_sha256: reviewManifestSha256,
  rollback_plan_sha256: rollbackPlanSha256,
  counts: {
    assets: 231,
    working_revisions: 229,
    product_shells_preserved: 2,
    primary_create: 106,
    existing_revision: 125,
    cohorts: cohorts.length,
    manually_reviewed: 0,
    runtime_bound: 0,
    rollback_targets_bound: 0,
    promotion_eligible: 0,
    cohorts_authorized: 0,
  },
  actions: {
    database_reads: 0,
    database_writes: 0,
    cms_writes: 0,
    promotions: 0,
    rollbacks: 0,
    public_release_changes: 0,
    indexability_changes: 0,
    sitemap_changes: 0,
    llms_changes: 0,
    search_submissions: 0,
    cache_operations: 0,
    deployments: 0,
  },
  blockers: ['manual_review_missing', 'runtime_identity_unbound', 'source_permission_missing', 'media_permission_missing', 'rollback_target_unbound', 'exact_cohort_authorization_missing'],
};
writeJson('promotion-preflight-report.json', preflightReport);

const files = [
  'review-manifest.json',
  'rollback-plan.json',
  'authorization-packet-template.json',
  'promotion-preflight-report.json',
];
writeJson('sha256sums.json', {
  schema_version: 'big5-authority-v2-review-promotion-hashes.v1',
  generated_at: generatedAt,
  files: Object.fromEntries(files.map((name) => [name, fileSha256(`generated/big-five-authority-v2/big5-authority-v2-review-promotion-gate-47/${name}`)])),
});

console.log(`Built Big Five PR47 package: ${rows.length} assets / ${actionCounts.revision_create} revisions / ${cohorts.length} cohorts / 0 authorizations`);
