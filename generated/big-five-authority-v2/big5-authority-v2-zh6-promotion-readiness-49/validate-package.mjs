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
invariant(packageJson.inputs.production_observation_sha256 === sha256(observationText), 'production observation SHA mismatch');
invariant(packageJson.inputs.owner_authority_sha256 === sha256(ownerAuthorityText), 'OWNER authority SHA mismatch');
invariant(packageJson.release_lock_material.owner_authority_sha256 === sha256(ownerAuthorityText), 'release lock OWNER authority SHA mismatch');
invariant(ownerAuthority.schema_version === 'big5-zh6-pr48-owner-authority.v1', 'OWNER authority schema mismatch');
invariant(ownerAuthority.comment_database_id === 4990228962 && ownerAuthority.author_login === 'fermatmind' && ownerAuthority.author_association === 'OWNER', 'OWNER authority identity mismatch');
const expectedOwnerPhrase = `我已阅读并批准 BIG5-AUTHORITY-V2-ZH6-SNAPSHOT-48 最终公开 snapshot；cohort_snapshot_sha256=${ownerAuthority.cohort_snapshot_sha256}；package_payload_sha256=${ownerAuthority.package_payload_sha256}；package_file_sha256=${ownerAuthority.package_file_sha256}；CMS reviewer_admin_user_id=1。`;
invariant(ownerAuthority.confirmation_phrase === expectedOwnerPhrase, 'OWNER authority phrase mismatch');
invariant(ownerAuthority.confirmed_at === '2026-07-16T09:24:18Z' && ownerAuthority.reviewer_admin_user_id === 1, 'OWNER authority timestamp or reviewer mismatch');
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
invariant(packageJson.editorial_authority.review_record_sha256 === canonicalSha256(packageJson.editorial_authority.review_record), 'review record SHA mismatch');
invariant(packageJson.source_permissions.source_permission_sha256 === canonicalSha256(packageJson.source_permissions.rows), 'source permission SHA mismatch');
invariant(packageJson.source_permissions.rows.every((row) => row.approved === true && row.source_ids.length === 3), 'each asset must keep three approved sources');
invariant(packageJson.permissions.permissions_sha256 === canonicalSha256({
  author: packageJson.permissions.author,
  reviewer: packageJson.permissions.reviewer,
  sources: packageJson.permissions.sources,
  media: packageJson.permissions.media,
}), 'permissions SHA mismatch');
invariant(typeof observation.admin_user_1?.totp_enrolled === 'boolean', 'reviewer TOTP enrollment evidence is missing');
const reviewerTotpReady = observation.admin_user_1.totp_enrolled === true;
invariant(packageJson.permissions.reviewer.totp_required === true, 'reviewer TOTP must remain required');
invariant(packageJson.permissions.reviewer.totp_enrolled === reviewerTotpReady, 'reviewer TOTP observation mismatch');
invariant(packageJson.permissions.reviewer.approved === reviewerTotpReady, 'reviewer approval must fail closed on missing TOTP enrollment');
invariant(packageJson.permissions.reviewer.authority_reference === (reviewerTotpReady
  ? `solo_operator_review:${packageJson.editorial_authority.review_record_sha256}`
  : null), 'reviewer authority reference does not match TOTP readiness');
invariant(packageJson.rollback_baseline.rollback_baseline_sha256 === canonicalSha256(packageJson.rollback_baseline.rows), 'rollback baseline SHA mismatch');
invariant(packageJson.rollback_baseline.rows.every((row) => row.exact_target_bound === true && row.abort_on_missing_or_drifted_target === true), 'rollback targets must fail closed');
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
  invariant(packageJson.permissions.media.approved === true && typeof packageJson.permissions.media.authority_reference === 'string', 'unique media authority permission missing');
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
for (const [name, value] of Object.entries(packageJson.actions)) {
  if (name === 'production_database_read_only_observation') {
    invariant(value === true, 'production observation evidence missing');
  } else {
    invariant(value === 0, `${name} must remain zero`);
  }
}

console.log('PASS: exact ZH6 snapshot is bound to admin_user:1 author/reviewer authority, a hash-locked solo_operator review record, 18 source permissions, six runtime rollback baselines, and a non-executable release snapshot.');
console.log(`PASS: the Media Library observation found ${eligibleCount} authority-complete Hub hero/OG assets; uniqueness is enforced and no ambiguous or article media is repurposed.`);
console.log('PASS: CMS/database/media/working-revision/published-pointer/promotion/indexability/sitemap/llms/schema/Search/cache/deploy mutations remain zero.');
console.log(`Release snapshot SHA256: ${packageJson.release_snapshot_sha256}`);
console.log(`Package payload SHA256: ${packageJson.package_payload_sha256}`);
console.log(`Package file SHA256: ${sha256(packageText)}`);
