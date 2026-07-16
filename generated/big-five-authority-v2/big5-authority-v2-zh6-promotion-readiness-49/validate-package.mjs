import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const directory = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(directory, '../../..');
const packagePath = path.resolve(process.env.PR49_PACKAGE_PATH ?? path.join(directory, 'promotion-readiness-package.json'));
const packageHashPath = path.resolve(process.env.PR49_PACKAGE_HASH_PATH ?? path.join(directory, 'promotion-readiness-package.sha256'));
const observationPath = path.resolve(process.env.PR49_OBSERVATION_PATH ?? path.join(directory, 'production-observation.json'));
const ownerAuthorityPath = path.resolve(process.env.PR49_OWNER_AUTHORITY_PATH ?? path.join(directory, 'pr48-owner-authority.json'));

const fail = (message) => {
  console.error(`FAIL: ${message}`);
  process.exit(1);
};
const invariant = (condition, message) => { if (!condition) fail(message); };
const sha256 = (value) => crypto.createHash('sha256').update(value).digest('hex');
const sortRecursive = (value) => {
  if (Array.isArray(value)) return value.map(sortRecursive);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, sortRecursive(value[key])]));
  }
  return value;
};
const canonicalSha256 = (value) => sha256(JSON.stringify(sortRecursive(value)));

const packageText = fs.readFileSync(packagePath, 'utf8');
const packageJson = JSON.parse(packageText);
const observationText = fs.readFileSync(observationPath, 'utf8');
const observation = JSON.parse(observationText);
const ownerAuthorityText = fs.readFileSync(ownerAuthorityPath, 'utf8');
const ownerAuthority = JSON.parse(ownerAuthorityText);

invariant(packageJson.schema_version === 'big5-zh6-promotion-readiness-package.v1', 'package schema mismatch');
invariant(packageJson.task_id === 'BIG5-AUTHORITY-V2-ZH6-PROMOTION-READINESS-49', 'task id mismatch');
invariant(packageJson.cohort_id === 'big_five_v2_zh_cn_hub_plus_five_domains_01', 'cohort id mismatch');
invariant(packageJson.release_snapshot_sha256 === canonicalSha256(packageJson.release_lock_material), 'release snapshot SHA mismatch');
const payload = structuredClone(packageJson);
delete payload.package_payload_sha256;
invariant(packageJson.package_payload_sha256 === canonicalSha256(payload), 'package payload SHA mismatch');
invariant(sha256(packageText) === fs.readFileSync(packageHashPath, 'utf8').trim(), 'package file SHA mismatch');
for (const [pathField, hashField] of Object.entries({
  snapshot_path: 'snapshot_file_sha256',
  confirmation_path: 'confirmation_file_sha256',
  owner_authority_path: 'owner_authority_sha256',
  production_observation_path: 'production_observation_sha256',
})) {
  invariant(typeof packageJson.inputs[pathField] === 'string', `${pathField} is missing`);
  const overridePath = pathField === 'owner_authority_path'
    ? process.env.PR49_OWNER_AUTHORITY_PATH
    : (pathField === 'production_observation_path' ? process.env.PR49_OBSERVATION_PATH : undefined);
  const inputText = fs.readFileSync(overridePath ?? path.join(repositoryRoot, packageJson.inputs[pathField]));
  invariant(packageJson.inputs[hashField] === sha256(inputText), `${pathField} file SHA mismatch`);
}
const snapshotText = fs.readFileSync(path.join(repositoryRoot, packageJson.inputs.snapshot_path), 'utf8');
const snapshot = JSON.parse(snapshotText);
const confirmationText = fs.readFileSync(path.join(repositoryRoot, packageJson.inputs.confirmation_path), 'utf8');
const confirmation = JSON.parse(confirmationText);
invariant(packageJson.inputs.production_observation_sha256 === sha256(observationText), 'production observation SHA mismatch');
invariant(packageJson.inputs.owner_authority_sha256 === sha256(ownerAuthorityText), 'OWNER authority SHA mismatch');
invariant(packageJson.release_lock_material.owner_authority_sha256 === sha256(ownerAuthorityText), 'release lock OWNER authority SHA mismatch');
invariant(ownerAuthority.schema_version === 'big5-zh6-pr48-owner-authority.v1', 'OWNER authority schema mismatch');
invariant(ownerAuthority.source === 'github_pull_request_comment', 'OWNER authority source mismatch');
invariant(ownerAuthority.repository === 'fermatmind/fap-api' && ownerAuthority.pull_request_number === 3139,
  'OWNER authority repository or PR mismatch');
invariant(ownerAuthority.comment_database_id === 4990228962 && ownerAuthority.author_login === 'fermatmind' && ownerAuthority.author_association === 'OWNER', 'OWNER authority identity mismatch');
invariant(ownerAuthority.cohort_snapshot_sha256 === snapshot.cohort_snapshot_sha256
  && ownerAuthority.package_payload_sha256 === snapshot.package_payload_sha256
  && ownerAuthority.package_file_sha256 === sha256(snapshotText),
'OWNER authority snapshot hashes mismatch');
const expectedOwnerPhrase = `我已阅读并批准 BIG5-AUTHORITY-V2-ZH6-SNAPSHOT-48 最终公开 snapshot；cohort_snapshot_sha256=${snapshot.cohort_snapshot_sha256}；package_payload_sha256=${snapshot.package_payload_sha256}；package_file_sha256=${sha256(snapshotText)}；CMS reviewer_admin_user_id=1。`;
invariant(ownerAuthority.confirmation_phrase === expectedOwnerPhrase, 'OWNER authority phrase mismatch');
invariant(ownerAuthority.confirmed_at === '2026-07-16T09:24:18Z' && ownerAuthority.reviewer_admin_user_id === 1, 'OWNER authority timestamp or reviewer mismatch');
const controlledOwnerApprovalFields = [
  'cms_or_database_write',
  'working_revision_write',
  'media_authority',
  'promotion_or_publication',
  'indexability_sitemap_llms_schema',
  'deployment_cache_or_search',
];
invariant(controlledOwnerApprovalFields.every((field) => ownerAuthority.approval_scope?.[field] === false),
  'OWNER authority must not approve controlled actions');
invariant(sha256(ownerAuthorityText) === '6646dd8086d6e85a42539d8e77f4cda31649a903875825d7916d3023467134cf',
  'OWNER authority file SHA drift');
invariant(packageJson.counts.assets === 6 && packageJson.counts.reviewed_assets === 6, 'exact six-asset review binding missing');
invariant(packageJson.counts.source_permission_assets === 6 && packageJson.counts.visible_sources === 18, 'source permission counts mismatch');
invariant(packageJson.counts.runtime_baselines === 6, 'rollback baseline count mismatch');
invariant(packageJson.editorial_authority.review_record.mode === 'solo_operator', 'solo_operator review mode missing');
invariant(packageJson.editorial_authority.review_record.author_admin_user_id === 1, 'author must bind admin_user:1');
invariant(packageJson.editorial_authority.review_record.reviewer_admin_user_id === 1, 'reviewer must bind admin_user:1');
invariant(packageJson.editorial_authority.review_record.explicit_self_review === true, 'explicit self review missing');
invariant(packageJson.editorial_authority.review_record.global_role_separation_relaxed === false, 'global role separation must remain unchanged');
invariant(packageJson.editorial_authority.review_record.public_label === 'FermatMind Editorial', 'public editorial label mismatch');
invariant(packageJson.editorial_authority.review_record.reviewed_at === ownerAuthority.confirmed_at, 'review record must use external OWNER confirmation time');
invariant(canonicalSha256(packageJson.editorial_authority.review_record.external_human_authority) === canonicalSha256({
  source: ownerAuthority.source,
  pull_request_number: ownerAuthority.pull_request_number,
  comment_database_id: ownerAuthority.comment_database_id,
  author_login: ownerAuthority.author_login,
  author_association: ownerAuthority.author_association,
  confirmation_phrase_sha256: sha256(ownerAuthority.confirmation_phrase),
}), 'review record OWNER external authority mismatch');
const expectedReviewAssets = snapshot.assets.map((asset) => ({
  asset_id: asset.asset_id,
  canonical_path: asset.canonical_path,
  snapshot_sha256: asset.snapshot_sha256,
}));
const expectedSourceRows = snapshot.assets.map((asset) => {
  const visibleSources = asset.public_snapshot?.visible_sources;
  invariant(Array.isArray(visibleSources) && visibleSources.length === 3, 'locked snapshot source rows are incomplete');
  invariant(asset.source_authority?.status === 'approved_for_link_citation_and_original_paraphrase', 'locked snapshot source authority is invalid');
  return {
    asset_id: asset.asset_id,
    snapshot_sha256: asset.snapshot_sha256,
    approved: true,
    permission_scope: 'public_link_citation_and_original_paraphrase_only',
    approval_reference: `source-ledger:${asset.source_authority.locked_ledger_sha256}`,
    source_ids: visibleSources.map((source) => source.source_id),
  };
});
invariant(canonicalSha256(packageJson.editorial_authority.review_record.assets) === canonicalSha256(expectedReviewAssets)
  && canonicalSha256(packageJson.source_permissions.rows) === canonicalSha256(expectedSourceRows),
'review or source rows do not match the locked snapshot');
invariant(packageJson.editorial_authority.review_record_sha256 === canonicalSha256(packageJson.editorial_authority.review_record), 'review record SHA mismatch');
invariant(packageJson.source_permissions.source_permission_sha256 === canonicalSha256(packageJson.source_permissions.rows), 'source permission SHA mismatch');
invariant(packageJson.source_permissions.rows.every((row) => row.approved === true && row.source_ids.length === 3), 'each asset must keep three approved sources');
invariant(packageJson.permissions.sources.authority_reference === `source_permissions:${packageJson.source_permissions.source_permission_sha256}`, 'source permission authority reference is detached from the locked hash');
invariant(packageJson.permissions.permissions_sha256 === canonicalSha256({
  author: packageJson.permissions.author,
  reviewer: packageJson.permissions.reviewer,
  sources: packageJson.permissions.sources,
  media: packageJson.permissions.media,
}), 'permissions SHA mismatch');
invariant(typeof observation.admin_user_1?.totp_policy_enabled === 'boolean', 'reviewer TOTP policy evidence is missing');
invariant(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(observation.admin_user_1?.totp_policy_observed_at ?? ''), 'reviewer TOTP policy observation time is missing');
invariant(typeof observation.admin_user_1?.totp_enrolled === 'boolean', 'reviewer TOTP enrollment evidence is missing');
const reviewerTotpRequired = observation.admin_user_1.totp_policy_enabled;
const reviewerTotpEnrolled = observation.admin_user_1.totp_enrolled;
const reviewerTotpReady = reviewerTotpRequired === false || reviewerTotpEnrolled === true;
invariant(packageJson.permissions.reviewer.totp_required === reviewerTotpRequired, 'reviewer TOTP policy observation mismatch');
invariant(packageJson.permissions.reviewer.totp_enrolled === reviewerTotpEnrolled, 'reviewer TOTP enrollment observation mismatch');
invariant(packageJson.permissions.reviewer.approved === reviewerTotpReady, 'reviewer approval does not honor the observed TOTP policy');
invariant(packageJson.permissions.reviewer.authority_reference === (reviewerTotpReady
  ? `solo_operator_review:${packageJson.editorial_authority.review_record_sha256}`
  : null), 'reviewer authority reference does not match TOTP readiness');
invariant(packageJson.rollback_baseline.rollback_baseline_sha256 === canonicalSha256(packageJson.rollback_baseline.rows), 'rollback baseline SHA mismatch');
invariant(packageJson.rollback_baseline.rows.every((row) => row.exact_target_bound === true && row.abort_on_missing_or_drifted_target === true), 'rollback targets must fail closed');
invariant(Array.isArray(observation.runtime_assets?.rows), 'runtime observation rows are missing');
invariant(packageJson.runtime_baseline.observed_at === observation.observed_at
  && packageJson.runtime_baseline.observed_deployed_sha === observation.deployed_sha
  && canonicalSha256(packageJson.runtime_baseline.rows) === canonicalSha256(observation.runtime_assets.rows),
'runtime baseline does not match the production observation');
const expectedRollbackRows = observation.runtime_assets.rows.map((row) => ({
  asset_id: row.asset_id,
  route: row.route,
  primary_id: row.primary_id,
  observed_working_revision_id: row.working_revision_id,
  restore_published_revision_id: row.published_revision_id,
  restore_public_runtime_baseline_sha256: row.public_runtime_baseline_sha256,
  exact_target_bound: true,
  abort_on_missing_or_drifted_target: true,
}));
invariant(canonicalSha256(packageJson.rollback_baseline.rows) === canonicalSha256(expectedRollbackRows),
  'rollback rows do not match the observed runtime baseline');
invariant(packageJson.media_authority.required_content_identity === 'big5:model_hub:zh-CN:hero-og', 'Hub media content identity mismatch');
invariant(packageJson.media_authority.fail_closed_on_zero_or_multiple === true, 'media uniqueness gate missing');
invariant(packageJson.media_authority.media_authority_sha256 === canonicalSha256({
  required_content_identity: packageJson.media_authority.required_content_identity,
  required_variant_keys: packageJson.media_authority.required_variant_keys,
  selection_status: packageJson.media_authority.selection_status,
  eligible_candidate_count: packageJson.media_authority.eligible_candidate_count,
  selected_candidate: packageJson.media_authority.selected_candidate,
  fail_closed_on_zero_or_multiple: packageJson.media_authority.fail_closed_on_zero_or_multiple,
  observation_sha256: packageJson.media_authority.observation_sha256,
}), 'media authority SHA mismatch');
const eligibleCount = observation.media_inventory.authority_complete_hero_og_count;
invariant(eligibleCount === observation.media_inventory.authority_complete_hero_og.length, 'observation media candidate count mismatch');
invariant(packageJson.media_authority.eligible_candidate_count === eligibleCount, 'package media candidate count mismatch');
if (eligibleCount === 1) {
  invariant(packageJson.counts.selected_hub_media_assets === 1 && packageJson.media_authority.selected_candidate !== null, 'unique media candidate must be selected');
  const selectedCandidate = structuredClone(packageJson.media_authority.selected_candidate);
  const selectedCandidateSha256 = selectedCandidate.candidate_sha256;
  delete selectedCandidate.candidate_sha256;
  invariant(selectedCandidateSha256 === canonicalSha256(selectedCandidate), 'unique media candidate SHA mismatch');
  invariant(['rights', 'license', 'provenance', 'operator_approval_ref'].every((key) => typeof selectedCandidate[key] === 'string' && selectedCandidate[key].trim().length > 0), 'unique media authority text is invalid');
  const observedCandidate = observation.media_inventory.authority_complete_hero_og[0];
  const observedCandidateMaterial = {
    media_asset_id: observedCandidate.media_asset_id,
    media_asset_key: observedCandidate.media_asset_key,
    locale: observedCandidate.locale,
    content_identity: observedCandidate.content_identity,
    status: observedCandidate.status,
    variant_keys: ['hero', 'og'],
    public_urls: {
      hero: observedCandidate.public_urls?.hero,
      og: observedCandidate.public_urls?.og,
    },
    alt: observedCandidate.alt,
    rights: observedCandidate.rights,
    license: observedCandidate.license,
    provenance: observedCandidate.provenance,
    operator_approval_ref: observedCandidate.operator_approval_ref,
  };
  invariant(canonicalSha256(selectedCandidate) === canonicalSha256(observedCandidateMaterial), 'selected media candidate does not match production observation');
  invariant(packageJson.permissions.media.approved === true
    && packageJson.permissions.media.authority_reference === `media_authority:${packageJson.media_authority.media_authority_sha256}`,
  'unique media authority permission does not match locked hash');
} else {
  invariant(packageJson.counts.selected_hub_media_assets === 0 && packageJson.media_authority.selected_candidate === null, 'ambiguous media must not be selected');
  invariant(packageJson.permissions.media.approved === false && packageJson.permissions.media.authority_reference === null, 'ambiguous media permission must remain unapproved');
  invariant(packageJson.blockers.includes(eligibleCount === 0 ? 'unique_hub_hero_og_media_missing' : 'multiple_hub_hero_og_media_candidates'), 'media uniqueness blocker missing');
}
const readinessReady = eligibleCount === 1 && reviewerTotpReady;
const expectedStatus = eligibleCount !== 1
  ? 'HOLD_FAIL_CLOSED_MEDIA_AUTHORITY'
  : (reviewerTotpReady ? 'PASS_PROMOTION_READINESS_ZERO_WRITE' : 'HOLD_FAIL_CLOSED_REVIEWER_TOTP');
invariant(packageJson.status === expectedStatus, 'reviewer/media readiness status mismatch');
invariant(packageJson.ready_for_working_revision === readinessReady, 'reviewer/media working-revision readiness mismatch');
invariant(packageJson.blockers.includes('admin_user_1_totp_enrollment_missing') === !reviewerTotpReady, 'reviewer TOTP blocker mismatch');
invariant(readinessReady ? packageJson.blockers.length === 0 : packageJson.blockers.length > 0, 'readiness blockers do not match the final disposition');
invariant(packageJson.ready_for_promotion === false && packageJson.release_snapshot_executable === false, 'promotion/release execution must remain blocked');
invariant(canonicalSha256(packageJson.actions) === canonicalSha256({
  production_database_read_only_observation: true,
  database_writes: 0,
  cms_writes: 0,
  media_library_writes: 0,
  media_uploads: 0,
  working_revisions_created: 0,
  promotions: 0,
  published_pointer_changes: 0,
  indexability_changes: 0,
  sitemap_changes: 0,
  llms_changes: 0,
  schema_changes: 0,
  search_submissions: 0,
  cache_operations: 0,
  deployments: 0,
}), 'action evidence must contain the exact read-only observation and zero-mutation fields');
const expectedReleaseLockMaterial = {
  cohort_snapshot_sha256: snapshot.cohort_snapshot_sha256,
  package_payload_sha256: snapshot.package_payload_sha256,
  package_file_sha256: sha256(snapshotText),
  confirmation_record_sha256: confirmation.confirmation_record_sha256,
  review_record_sha256: packageJson.editorial_authority.review_record_sha256,
  source_permission_sha256: packageJson.source_permissions.source_permission_sha256,
  media_authority_sha256: packageJson.media_authority.media_authority_sha256,
  permissions_sha256: packageJson.permissions.permissions_sha256,
  rollback_baseline_sha256: packageJson.rollback_baseline.rollback_baseline_sha256,
  production_observation_sha256: sha256(observationText),
  owner_authority_sha256: sha256(ownerAuthorityText),
};
invariant(canonicalSha256(packageJson.release_lock_material) === canonicalSha256(expectedReleaseLockMaterial)
  && packageJson.release_snapshot_sha256 === canonicalSha256(expectedReleaseLockMaterial),
'release lock material does not match validated evidence');

console.log('PASS: exact ZH6 snapshot is bound to admin_user:1 author/reviewer authority, a hash-locked solo_operator review record, 18 source permissions, six runtime rollback baselines, and a non-executable release snapshot.');
console.log(`PASS: the Media Library observation found ${eligibleCount} authority-complete Hub hero/OG assets; uniqueness is enforced and no ambiguous or article media is repurposed.`);
console.log('PASS: CMS/database/media/working-revision/published-pointer/promotion/indexability/sitemap/llms/schema/Search/cache/deploy mutations remain zero.');
console.log(`Release snapshot SHA256: ${packageJson.release_snapshot_sha256}`);
console.log(`Package payload SHA256: ${packageJson.package_payload_sha256}`);
console.log(`Package file SHA256: ${sha256(packageText)}`);
