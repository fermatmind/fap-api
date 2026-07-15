import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const directory = dirname(fileURLToPath(import.meta.url));
const root = resolve(directory, '../../..');
const readJson = (path) => JSON.parse(readFileSync(path, 'utf8'));
const sha256 = (value) => createHash('sha256').update(value).digest('hex');
const fileSha256 = (path) => sha256(readFileSync(path));
const fail = (message) => { throw new Error(message); };
const existingArticleSlugs = new Set([
  'big-five-conscientiousness-low-procrastination-task-plan',
  'big-five-emotional-stability-stress-recovery-communication',
  'big-five-personality-test-vs-mbti',
  'big-five-growth-guide',
  'big-five-narrative-portrait',
  'big-five-tool-guide',
]);
const sourceArtifactPaths = [
  'generated/big-five-authority-v2/big5-authority-v2-release-gate-37/draft-import-package.json',
  'generated/big-five-authority-v2/big5-authority-v2-media-authority-41/mapping-package.json',
  'generated/big-five-authority-v2/big5-authority-v2-visible-date-42/visible-date-findings.json',
  'generated/big-five-authority-v2/big5-authority-v2-visible-provenance-43/visible-provenance-findings.json',
  'generated/big-five-authority-v2/big5-authority-v2-discoverability-parity-44/discoverability-parity-findings.json',
  'generated/big-five-authority-v2/big5-authority-v2-structured-data-45/structured-data-findings.json',
  'generated/big-five-authority-v2/big5-authority-v2-topic-authority-46/topic-draft-revision-package.json',
];
const lockedAuthorityPackageFileSha256 = '80f95a73d497f28a74197b5af7dc1849af35ec9c15958ac898b29b669b997154';
const surfaceKey = (surface) => surface.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '');

const reviewPath = resolve(directory, 'review-manifest.json');
const rollbackPath = resolve(directory, 'rollback-plan.json');
const authorizationPath = resolve(directory, 'authorization-packet-template.json');
const reportPath = resolve(directory, 'promotion-preflight-report.json');
const hashes = readJson(resolve(directory, 'sha256sums.json'));
const review = readJson(reviewPath);
const rollback = readJson(rollbackPath);
const authorization = readJson(authorizationPath);
const report = readJson(reportPath);
const lockedAuthorityPath = resolve(root, sourceArtifactPaths[0]);
if (fileSha256(lockedAuthorityPath) !== lockedAuthorityPackageFileSha256) fail('locked PR37 authority package SHA-256 mismatch');
const lockedAuthority = readJson(lockedAuthorityPath);
if (lockedAuthority.schema_version !== 'big5-authority-v2-multi-surface-draft-import.v1'
  || !/^[0-9a-f]{64}$/.test(lockedAuthority.authority_package_sha256)
  || lockedAuthority.assets?.length !== 231) fail('locked PR37 authority inventory mismatch');
const lockedRows = new Map(lockedAuthority.assets.map((row) => [row.asset_id, row]));
if (lockedRows.size !== 231) fail('locked PR37 authority identity mismatch');

const expectedHashFiles = ['authorization-packet-template.json', 'promotion-preflight-report.json', 'review-manifest.json', 'rollback-plan.json'];
if (hashes.schema_version !== 'big5-authority-v2-review-promotion-hashes.v1'
  || JSON.stringify(Object.keys(hashes.files ?? {}).sort()) !== JSON.stringify(expectedHashFiles)) fail('artifact hash inventory mismatch');
for (const [name, expected] of Object.entries(hashes.files ?? {})) {
  const actual = fileSha256(resolve(directory, name));
  if (actual !== expected) fail(`${name} SHA-256 mismatch`);
}

const reviewSourceArtifactPaths = (review.source_artifacts ?? []).map((source) => source?.path).sort();
if (JSON.stringify(reviewSourceArtifactPaths) !== JSON.stringify([...sourceArtifactPaths].sort())) {
  fail('source artifact identity contract mismatch');
}
for (const source of review.source_artifacts) {
  if (fileSha256(resolve(root, source.path)) !== source.sha256) fail(`source artifact drift: ${source.path}`);
}

if (review.schema_version !== 'big5-authority-v2-review-manifest.v1') fail('review schema mismatch');
if (review.status !== 'HOLD_PENDING_MANUAL_REVIEW_AND_RUNTIME_BINDING') fail('review status must remain on hold');
if (review.rows?.length !== 231 || review.counts?.assets !== 231) fail('asset count mismatch');
if (review.counts?.primary_create !== 106 || review.counts?.existing_revision !== 125 || review.counts?.revision_create !== 229 || review.counts?.product_shell_preserved !== 2) fail('action counts mismatch');
if (review.counts?.promotion_eligible !== 0) fail('checked-in review package cannot be promotion eligible');
if (new Set(review.rows.map((row) => row.asset_id)).size !== 231) fail('duplicate asset identity');
if (review.invariants?.public_reader_selects_working_revision !== false || review.invariants?.abort_on_any_mismatch !== true || review.invariants?.production_promotion_currently_authorized !== false) fail('review invariants mismatch');
if (review.authority_package_sha256 !== lockedAuthority.authority_package_sha256
  || review.source_package_path !== sourceArtifactPaths[0]
  || review.source_package_file_sha256 !== lockedAuthorityPackageFileSha256) fail('review source package lock mismatch');

const actionCounts = { primary_create: 0, existing_revision: 0, revision_create: 0, product_shell_preserved: 0 };
for (const row of review.rows) {
  const lockedRow = lockedRows.get(row.asset_id);
  if (!lockedRow
    || row.source_package !== lockedRow.source_package
    || row.source_hash !== lockedRow.source_hash
    || row.route !== lockedRow.route
    || row.locale !== lockedRow.locale
    || row.page_family !== lockedRow.page_family
    || row.authority_surface !== lockedRow.authority_surface
    || row.authority_package_sha256 !== lockedAuthority.authority_package_sha256) {
    fail(`source/package authority mismatch: ${row.asset_id}`);
  }
  if (!/^\/(?:en|zh)\//.test(row.route) || !['en', 'zh-CN'].includes(row.locale)) fail(`route/locale mismatch: ${row.asset_id}`);
  if (!/^[0-9a-f]{64}$/.test(row.source_hash) || !/^[0-9a-f]{64}$/.test(row.authority_package_sha256)) fail(`source/package hash missing: ${row.asset_id}`);
  for (const [action, enabled] of Object.entries(row.action_contract)) if (enabled) actionCounts[action] += 1;
  const slug = row.route.split('/').at(-1);
  const expectedExisting = row.authority_surface === 'CMS personality_public_content_assets'
    || row.authority_surface === 'CMS topic_profiles'
    || (row.authority_surface === 'CMS Article' && existingArticleSlugs.has(slug));
  const expectedShell = row.authority_surface === 'CMS landing_surfaces/page_blocks';
  const expectedActions = { primary_create: !expectedExisting, existing_revision: expectedExisting, revision_create: !expectedShell, product_shell_preserved: expectedShell };
  if (JSON.stringify(row.action_contract) !== JSON.stringify(expectedActions)) fail(`per-identity action classification mismatch: ${row.asset_id}`);
  const actions = Object.values(row.action_contract).filter(Boolean).length;
  if (row.action_contract.product_shell_preserved ? actions !== 2 || !row.action_contract.primary_create : actions !== 2) fail(`action contract mismatch: ${row.asset_id}`);
  if (row.expected_runtime?.bound !== false || Object.entries(row.expected_runtime).some(([key, value]) => key !== 'bound' && value !== null)) fail(`runtime id/baseline fabricated: ${row.asset_id}`);
  if (row.manual_review?.status !== 'pending_manual_review' || row.manual_review?.reviewer_id !== null || row.manual_review?.reviewed_at !== null || row.manual_review?.review_record_sha256 !== null) fail(`manual review must remain pending: ${row.asset_id}`);
  if (row.permissions?.source?.approved !== false || row.permissions?.source?.approval_reference !== null || row.permissions?.media?.approved !== false || row.permissions?.media?.approval_reference !== null) fail(`permissions must remain pending: ${row.asset_id}`);
  if (row.promotion?.eligible !== false) fail(`promotion eligibility fabricated: ${row.asset_id}`);
  if (row.action_contract.revision_create && !row.promotion?.cohort_id) fail(`revision missing cohort: ${row.asset_id}`);
  if (row.action_contract.product_shell_preserved && row.promotion?.cohort_id !== null) fail(`product shell cannot enter a cohort: ${row.asset_id}`);
}
if (JSON.stringify(actionCounts) !== JSON.stringify({ primary_create: 106, existing_revision: 125, revision_create: 229, product_shell_preserved: 2 })) fail('derived action counts mismatch');

const cohortAssets = [];
const cohortByAsset = new Map();
for (const cohort of review.cohorts ?? []) {
  if (cohort.asset_count !== cohort.asset_ids.length || cohort.asset_count < 1 || cohort.asset_count > 25 || cohort.abort_on_any_mismatch !== true) fail(`cohort contract mismatch: ${cohort.cohort_id}`);
  if (sha256(JSON.stringify(cohort.asset_ids)) !== cohort.cohort_sha256) fail(`cohort hash mismatch: ${cohort.cohort_id}`);
  cohortAssets.push(...cohort.asset_ids);
  for (const assetId of cohort.asset_ids) cohortByAsset.set(assetId, cohort.cohort_id);
}
if (cohortAssets.length !== 229 || new Set(cohortAssets).size !== 229) fail('cohort coverage mismatch');
const expectedCohortGroups = new Map();
for (const row of lockedAuthority.assets.filter((candidate) => candidate.authority_surface !== 'CMS landing_surfaces/page_blocks')) {
  const key = `${row.authority_surface}|${row.locale}`;
  const group = expectedCohortGroups.get(key) ?? [];
  group.push(row.asset_id);
  expectedCohortGroups.set(key, group);
}
const expectedCohorts = [];
for (const key of [...expectedCohortGroups.keys()].sort()) {
  const assetIds = expectedCohortGroups.get(key).sort((a, b) => a.localeCompare(b));
  const [surface, locale] = key.split('|');
  for (let offset = 0; offset < assetIds.length; offset += 25) {
    const cohortAssetIds = assetIds.slice(offset, offset + 25);
    expectedCohorts.push({
      cohort_id: `${surfaceKey(surface)}_${locale.toLowerCase().replace(/[^a-z0-9]+/g, '_')}_${String((offset / 25) + 1).padStart(2, '0')}`,
      authority_surface: surface,
      locale,
      asset_count: cohortAssetIds.length,
      asset_ids: cohortAssetIds,
      cohort_sha256: sha256(JSON.stringify(cohortAssetIds)),
      abort_on_any_mismatch: true,
    });
  }
}
if (JSON.stringify(review.cohorts) !== JSON.stringify(expectedCohorts)) fail('exact cohort identity contract mismatch');
for (const row of review.rows) {
  const expectedCohort = row.action_contract.revision_create ? cohortByAsset.get(row.asset_id) : undefined;
  if ((row.promotion.cohort_id ?? undefined) !== expectedCohort) fail(`cohort membership mismatch: ${row.asset_id}`);
}

if (rollback.schema_version !== 'big5-authority-v2-promotion-rollback-plan.v1' || rollback.status !== 'HOLD_PENDING_EXACT_RUNTIME_TARGETS' || rollback.rows?.length !== 231 || rollback.execution_implemented !== false) fail('rollback plan contract mismatch');
const expectedRollbackEffects = {
  database_writes: 0,
  indexability_changes: 0,
  promotions: 0,
  public_release_changes: 0,
  rollbacks: 0,
};
if (!rollback.effects
  || Array.isArray(rollback.effects)
  || JSON.stringify(Object.keys(rollback.effects).sort()) !== JSON.stringify(Object.keys(expectedRollbackEffects).sort())
  || Object.entries(expectedRollbackEffects).some(([effect, expected]) => rollback.effects[effect] !== expected)) {
  fail('rollback effects must match the exact zero-effect contract');
}
if (rollback.review_manifest_sha256 !== fileSha256(reviewPath)) fail('rollback review-manifest lock mismatch');
if (new Set(rollback.rows.map((row) => row.asset_id)).size !== 231) fail('rollback identity coverage mismatch');
const reviewRows = new Map(review.rows.map((row) => [row.asset_id, row]));
for (const row of rollback.rows) {
  const reviewRow = reviewRows.get(row.asset_id);
  const expectedAction = reviewRow?.action_contract.product_shell_preserved
    ? 'preserve_product_shell_without_mutation'
    : reviewRow?.action_contract.existing_revision
      ? 'restore_exact_published_revision_and_runtime_baseline'
      : 'restore_unpublished_draft_and_clear_published_pointer';
  if (!reviewRow || row.action !== expectedAction) fail(`rollback identity/action mismatch: ${row.asset_id}`);
  if (row.primary_id !== null || row.restore_published_revision_id !== null || row.restore_public_runtime_baseline_sha256 !== null || row.exact_target_bound !== false) fail(`rollback target fabricated: ${row.asset_id}`);
}

if (authorization.schema_version !== 'big5-authority-v2-cohort-promotion-authorization.v1' || authorization.production_promotion_currently_authorized !== false || authorization.approval_phrases_currently_executable !== false || authorization.deployed_sha !== null || authorization.promotion_preflight_fingerprint !== null) fail('authorization template must remain non-executable');
if (authorization.review_manifest_sha256 !== fileSha256(reviewPath) || authorization.rollback_plan_sha256 !== fileSha256(rollbackPath)) fail('authorization artifact locks mismatch');
if (authorization.authority_package_sha256 !== lockedAuthority.authority_package_sha256) fail('authorization source package lock mismatch');
if (authorization.cohorts?.length !== review.cohorts.length) fail('cohort authorization identity coverage mismatch');
for (const [index, cohort] of authorization.cohorts.entries()) {
  const reviewCohort = review.cohorts[index];
  if (cohort.cohort_id !== reviewCohort.cohort_id
    || cohort.cohort_sha256 !== reviewCohort.cohort_sha256
    || cohort.asset_count !== reviewCohort.asset_count) {
    fail('cohort authorization identity coverage mismatch');
  }
  if (cohort.authorized !== false || cohort.exact_authorization !== null) fail('cohort authorization must remain pending');
}

if (report.schema_version !== 'big5-authority-v2-review-promotion-preflight-report.v1'
  || report.status !== 'HOLD_FAIL_CLOSED_PENDING_REVIEW_AND_AUTHORIZATION'
  || report.mode !== 'package_only_zero_write'
  || report.review_manifest_sha256 !== fileSha256(reviewPath)
  || report.rollback_plan_sha256 !== fileSha256(rollbackPath)
  || JSON.stringify(report.counts) !== JSON.stringify({ assets: 231, working_revisions: 229, product_shells_preserved: 2, primary_create: 106, existing_revision: 125, cohorts: 16, manually_reviewed: 0, runtime_bound: 0, rollback_targets_bound: 0, promotion_eligible: 0, cohorts_authorized: 0 })) fail('preflight report must remain fail closed');
for (const [action, value] of Object.entries(report.actions ?? {})) if (value !== 0) fail(`non-zero package action: ${action}`);

console.log(`Big Five PR47 validation passed: ${fileSha256(reviewPath)} / 231 assets / 229 revisions / ${review.cohorts.length} cohorts / 0 authorizations / 0 writes`);
