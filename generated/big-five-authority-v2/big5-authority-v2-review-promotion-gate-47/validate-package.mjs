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

const reviewPath = resolve(directory, 'review-manifest.json');
const rollbackPath = resolve(directory, 'rollback-plan.json');
const authorizationPath = resolve(directory, 'authorization-packet-template.json');
const reportPath = resolve(directory, 'promotion-preflight-report.json');
const hashes = readJson(resolve(directory, 'sha256sums.json'));
const review = readJson(reviewPath);
const rollback = readJson(rollbackPath);
const authorization = readJson(authorizationPath);
const report = readJson(reportPath);

for (const [name, expected] of Object.entries(hashes.files ?? {})) {
  const actual = fileSha256(resolve(directory, name));
  if (actual !== expected) fail(`${name} SHA-256 mismatch`);
}

for (const source of review.source_artifacts ?? []) {
  if (fileSha256(resolve(root, source.path)) !== source.sha256) fail(`source artifact drift: ${source.path}`);
}

if (review.schema_version !== 'big5-authority-v2-review-manifest.v1') fail('review schema mismatch');
if (review.status !== 'HOLD_PENDING_MANUAL_REVIEW_AND_RUNTIME_BINDING') fail('review status must remain on hold');
if (review.rows?.length !== 231 || review.counts?.assets !== 231) fail('asset count mismatch');
if (review.counts?.primary_create !== 106 || review.counts?.existing_revision !== 125 || review.counts?.revision_create !== 229 || review.counts?.product_shell_preserved !== 2) fail('action counts mismatch');
if (review.counts?.promotion_eligible !== 0) fail('checked-in review package cannot be promotion eligible');
if (new Set(review.rows.map((row) => row.asset_id)).size !== 231) fail('duplicate asset identity');
if (review.invariants?.public_reader_selects_working_revision !== false || review.invariants?.abort_on_any_mismatch !== true || review.invariants?.production_promotion_currently_authorized !== false) fail('review invariants mismatch');

const actionCounts = { primary_create: 0, existing_revision: 0, revision_create: 0, product_shell_preserved: 0 };
for (const row of review.rows) {
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
for (const row of review.rows) {
  const expectedCohort = row.action_contract.revision_create ? cohortByAsset.get(row.asset_id) : undefined;
  if ((row.promotion.cohort_id ?? undefined) !== expectedCohort) fail(`cohort membership mismatch: ${row.asset_id}`);
}

if (rollback.schema_version !== 'big5-authority-v2-promotion-rollback-plan.v1' || rollback.status !== 'HOLD_PENDING_EXACT_RUNTIME_TARGETS' || rollback.rows?.length !== 231 || rollback.execution_implemented !== false) fail('rollback plan contract mismatch');
if (rollback.review_manifest_sha256 !== fileSha256(reviewPath)) fail('rollback review-manifest lock mismatch');
if (new Set(rollback.rows.map((row) => row.asset_id)).size !== 231) fail('rollback identity coverage mismatch');
for (const row of rollback.rows) {
  if (row.primary_id !== null || row.restore_published_revision_id !== null || row.restore_public_runtime_baseline_sha256 !== null || row.exact_target_bound !== false) fail(`rollback target fabricated: ${row.asset_id}`);
}

if (authorization.schema_version !== 'big5-authority-v2-cohort-promotion-authorization.v1' || authorization.production_promotion_currently_authorized !== false || authorization.approval_phrases_currently_executable !== false || authorization.deployed_sha !== null || authorization.promotion_preflight_fingerprint !== null) fail('authorization template must remain non-executable');
if (authorization.review_manifest_sha256 !== fileSha256(reviewPath) || authorization.rollback_plan_sha256 !== fileSha256(rollbackPath)) fail('authorization artifact locks mismatch');
if (authorization.cohorts?.length !== review.cohorts.length || authorization.cohorts.some((cohort) => cohort.authorized !== false || cohort.exact_authorization !== null)) fail('cohort authorization must remain pending');

if (report.status !== 'HOLD_FAIL_CLOSED_PENDING_REVIEW_AND_AUTHORIZATION' || report.counts?.promotion_eligible !== 0 || report.counts?.cohorts_authorized !== 0) fail('preflight report must remain fail closed');
for (const [action, value] of Object.entries(report.actions ?? {})) if (value !== 0) fail(`non-zero package action: ${action}`);

console.log(`Big Five PR47 validation passed: ${fileSha256(reviewPath)} / 231 assets / 229 revisions / ${review.cohorts.length} cohorts / 0 authorizations / 0 writes`);
