import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const directory = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(directory, '../../..');
const snapshotPath = path.join(repositoryRoot, 'generated/big-five-authority-v2/big5-authority-v2-zh6-snapshot-48/final-snapshot-package.json');
const confirmationPath = path.join(repositoryRoot, 'generated/big-five-authority-v2/big5-authority-v2-zh6-snapshot-48/exact-snapshot-confirmation.json');
const ownerAuthorityPath = path.resolve(process.env.PR49_OWNER_AUTHORITY_PATH ?? path.join(directory, 'pr48-owner-authority.json'));
const observationPath = path.resolve(process.env.PR49_OBSERVATION_PATH ?? path.join(directory, 'production-observation.json'));
const outputPath = path.resolve(process.env.PR49_OUTPUT_PATH ?? path.join(directory, 'promotion-readiness-package.json'));
const outputHashPath = path.resolve(process.env.PR49_OUTPUT_HASH_PATH ?? path.join(directory, 'promotion-readiness-package.sha256'));
const observationInputPath = process.env.PR49_OBSERVATION_PATH
  ? observationPath
  : 'generated/big-five-authority-v2/big5-authority-v2-zh6-promotion-readiness-49/production-observation.json';

const invariant = (condition, message) => {
  if (!condition) throw new Error(message);
};

invariant(path.basename(observationPath) === 'production-observation.json'
  && path.dirname(observationPath) === path.dirname(outputPath),
'production observation must be the reviewed package sibling named production-observation.json');
invariant(outputPath.endsWith('.json') && outputHashPath === outputPath.replace(/\.json$/, '.sha256'),
'package SHA sidecar must be colocated with and named after the package');

const sha256 = (value) => crypto.createHash('sha256').update(value).digest('hex');

const sortRecursive = (value) => {
  if (Array.isArray(value)) return value.map(sortRecursive);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, sortRecursive(value[key])]));
  }
  return value;
};

const canonicalSha256 = (value) => sha256(JSON.stringify(sortRecursive(value)));
const readJson = (file) => JSON.parse(fs.readFileSync(file, 'utf8'));

const snapshotText = fs.readFileSync(snapshotPath, 'utf8');
const confirmationText = fs.readFileSync(confirmationPath, 'utf8');
const ownerAuthorityText = fs.readFileSync(ownerAuthorityPath, 'utf8');
const observationText = fs.readFileSync(observationPath, 'utf8');
const snapshot = JSON.parse(snapshotText);
const confirmation = JSON.parse(confirmationText);
const ownerAuthority = JSON.parse(ownerAuthorityText);
const observation = JSON.parse(observationText);

invariant(snapshot.cohort_id === 'big_five_v2_zh_cn_hub_plus_five_domains_01', 'unexpected ZH6 cohort');
invariant(snapshot.cohort_snapshot_sha256 === 'f724913f5cdd5fcd33b7e899e3bd8c7f9f003919c12d5631572d5ddebc4265fa', 'snapshot SHA drift');
invariant(snapshot.package_payload_sha256 === '0c009c77310fb6ca8d67cf3fac2b85a56ecb892e5b6b20d56ee41de103e910d7', 'snapshot payload SHA drift');
invariant(sha256(snapshotText) === 'b8206a045e100aed1016e24d4266ee8d75fb82b38496213f892a9dff0ed7eb5d', 'snapshot file SHA drift');
invariant(confirmation.status === 'approved_by_real_human', 'exact snapshot confirmation is not approved');
invariant(confirmation.reviewer_admin_user_id === 1, 'snapshot confirmation reviewer must be admin_user:1');
invariant(confirmation.cohort_snapshot_sha256 === snapshot.cohort_snapshot_sha256, 'confirmation snapshot SHA mismatch');
invariant(confirmation.package_payload_sha256 === snapshot.package_payload_sha256, 'confirmation payload SHA mismatch');
invariant(confirmation.package_file_sha256 === sha256(snapshotText), 'confirmation file SHA mismatch');
invariant(ownerAuthority.schema_version === 'big5-zh6-pr48-owner-authority.v1', 'OWNER authority schema mismatch');
invariant(ownerAuthority.source === 'github_pull_request_comment', 'OWNER authority source mismatch');
invariant(ownerAuthority.repository === 'fermatmind/fap-api' && ownerAuthority.pull_request_number === 3139, 'OWNER authority PR mismatch');
invariant(ownerAuthority.comment_database_id === 4990228962, 'OWNER authority comment mismatch');
invariant(ownerAuthority.author_login === 'fermatmind' && ownerAuthority.author_association === 'OWNER', 'OWNER identity mismatch');
invariant(ownerAuthority.reviewer_admin_user_id === 1, 'OWNER authority reviewer must be admin_user:1');
invariant(ownerAuthority.cohort_snapshot_sha256 === snapshot.cohort_snapshot_sha256, 'OWNER authority snapshot SHA mismatch');
invariant(ownerAuthority.package_payload_sha256 === snapshot.package_payload_sha256, 'OWNER authority payload SHA mismatch');
invariant(ownerAuthority.package_file_sha256 === sha256(snapshotText), 'OWNER authority file SHA mismatch');
const expectedOwnerPhrase = `我已阅读并批准 BIG5-AUTHORITY-V2-ZH6-SNAPSHOT-48 最终公开 snapshot；cohort_snapshot_sha256=${snapshot.cohort_snapshot_sha256}；package_payload_sha256=${snapshot.package_payload_sha256}；package_file_sha256=${sha256(snapshotText)}；CMS reviewer_admin_user_id=1。`;
invariant(ownerAuthority.confirmation_phrase === expectedOwnerPhrase, 'OWNER authority phrase does not match the locked three hashes');
invariant(ownerAuthority.confirmed_at === '2026-07-16T09:24:18Z', 'OWNER authority timestamp mismatch');
const controlledOwnerApprovalFields = [
  'cms_or_database_write',
  'working_revision_write',
  'media_authority',
  'promotion_or_publication',
  'indexability_sitemap_llms_schema',
  'deployment_cache_or_search',
];
invariant(controlledOwnerApprovalFields.every((field) => ownerAuthority.approval_scope?.[field] === false),
  'OWNER authority must not imply controlled action approval');
invariant(sha256(ownerAuthorityText) === '6646dd8086d6e85a42539d8e77f4cda31649a903875825d7916d3023467134cf',
  'OWNER authority file SHA drift');
invariant(observation.schema_version === 'big5-zh6-promotion-readiness-production-observation.v1', 'production observation schema mismatch');
invariant(observation.admin_user_1?.exists === true && observation.admin_user_1?.is_active === true, 'admin_user:1 is not active');
invariant(observation.admin_user_1?.public_label === 'FermatMind Editorial', 'public editorial label drift');
invariant(typeof observation.admin_user_1?.totp_policy_enabled === 'boolean', 'admin_user:1 TOTP policy evidence is missing');
invariant(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(observation.admin_user_1?.totp_policy_observed_at ?? ''), 'admin_user:1 TOTP policy observation time is missing');
invariant(typeof observation.admin_user_1?.totp_enrolled === 'boolean', 'admin_user:1 TOTP enrollment evidence is missing');
const reviewerTotpRequired = observation.admin_user_1.totp_policy_enabled;
const reviewerTotpEnrolled = observation.admin_user_1.totp_enrolled;
const reviewerTotpReady = reviewerTotpRequired === false || reviewerTotpEnrolled === true;
invariant(Array.isArray(snapshot.assets) && snapshot.assets.length === 6, 'snapshot must contain exactly six assets');
invariant(observation.runtime_assets?.count_found === 6 && observation.runtime_assets?.rows?.length === 6, 'runtime observation must bind exactly six assets');

const snapshotByRoute = new Map(snapshot.assets.map((asset) => [asset.canonical_path, asset]));
for (const row of observation.runtime_assets.rows) {
  invariant(snapshotByRoute.has(row.route), `runtime route is outside ZH6: ${row.route}`);
  invariant(row.canonical_path === row.route, `runtime canonical mismatch: ${row.route}`);
  invariant(Number.isInteger(row.primary_id) && row.primary_id > 0, `runtime primary id missing: ${row.route}`);
  invariant(/^[0-9a-f]{64}$/.test(row.public_runtime_baseline_sha256), `runtime fingerprint invalid: ${row.route}`);
}

const reviewRecord = {
  cohort_id: snapshot.cohort_id,
  cohort_snapshot_sha256: snapshot.cohort_snapshot_sha256,
  package_payload_sha256: snapshot.package_payload_sha256,
  package_file_sha256: sha256(snapshotText),
  confirmation_record_sha256: confirmation.confirmation_record_sha256,
  mode: 'solo_operator',
  explicit_self_review: true,
  author_admin_user_id: 1,
  reviewer_admin_user_id: 1,
  public_label: 'FermatMind Editorial',
  reviewed_at: ownerAuthority.confirmed_at,
  external_human_authority: {
    source: ownerAuthority.source,
    pull_request_number: ownerAuthority.pull_request_number,
    comment_database_id: ownerAuthority.comment_database_id,
    author_login: ownerAuthority.author_login,
    author_association: ownerAuthority.author_association,
    confirmation_phrase_sha256: sha256(ownerAuthority.confirmation_phrase),
  },
  revision_binding: 'pending_exact_working_revision_ids_in_task50',
  global_role_separation_relaxed: false,
  assets: snapshot.assets.map((asset) => ({
    asset_id: asset.asset_id,
    canonical_path: asset.canonical_path,
    snapshot_sha256: asset.snapshot_sha256,
  })),
};
const reviewRecordSha256 = canonicalSha256(reviewRecord);

const sourcePermissions = snapshot.assets.map((asset) => {
  const sources = asset.public_snapshot?.visible_sources;
  invariant(Array.isArray(sources) && sources.length === 3, `${asset.asset_id} must keep exactly three visible sources`);
  invariant(asset.source_authority?.status === 'approved_for_link_citation_and_original_paraphrase', `${asset.asset_id} source authority is not approved`);
  return {
    asset_id: asset.asset_id,
    snapshot_sha256: asset.snapshot_sha256,
    approved: true,
    permission_scope: 'public_link_citation_and_original_paraphrase_only',
    approval_reference: `source-ledger:${asset.source_authority.locked_ledger_sha256}`,
    source_ids: sources.map((source) => source.source_id),
  };
});
const sourcePermissionSha256 = canonicalSha256(sourcePermissions);

const requiredMediaContentIdentity = 'big5:model_hub:zh-CN:hero-og';
const requiredMediaVariantKeys = ['hero', 'og'];
const observedEligibleMedia = observation.media_inventory.authority_complete_hero_og;
invariant(Array.isArray(observedEligibleMedia), 'authority-complete media candidates must be an array');
invariant(observation.media_inventory.authority_complete_hero_og_count === observedEligibleMedia.length, 'media candidate count mismatch');
const eligibleMedia = observedEligibleMedia.map((candidate, index) => {
  invariant(Number.isInteger(candidate.media_asset_id) && candidate.media_asset_id > 0, `eligible media ${index} asset id missing`);
  invariant(typeof candidate.media_asset_key === 'string' && candidate.media_asset_key.length > 0, `eligible media ${index} asset key missing`);
  invariant(candidate.locale === 'zh-CN', `eligible media ${index} locale mismatch`);
  invariant(candidate.content_identity === requiredMediaContentIdentity, `eligible media ${index} content identity mismatch`);
  invariant(candidate.status === 'published_public_synced_cdn_verified', `eligible media ${index} authority status mismatch`);
  invariant(Array.isArray(candidate.variant_keys) && requiredMediaVariantKeys.every((key) => candidate.variant_keys.includes(key)), `eligible media ${index} hero/og variants missing`);
  invariant(typeof candidate.alt === 'string' && candidate.alt.length > 0, `eligible media ${index} zh-CN alt missing`);
  invariant(['rights', 'license', 'provenance', 'operator_approval_ref'].every((key) => typeof candidate[key] === 'string' && candidate[key].trim().length > 0), `eligible media ${index} rights/provenance approval missing`);
  invariant(candidate.public_urls && /^https:\/\/(assets|api)\.fermatmind\.com\//.test(candidate.public_urls.hero ?? '') && /^https:\/\/(assets|api)\.fermatmind\.com\//.test(candidate.public_urls.og ?? ''), `eligible media ${index} public URLs invalid`);
  const material = {
    media_asset_id: candidate.media_asset_id,
    media_asset_key: candidate.media_asset_key,
    locale: candidate.locale,
    content_identity: candidate.content_identity,
    status: candidate.status,
    variant_keys: requiredMediaVariantKeys,
    public_urls: {
      hero: candidate.public_urls.hero,
      og: candidate.public_urls.og,
    },
    alt: candidate.alt,
    rights: candidate.rights,
    license: candidate.license,
    provenance: candidate.provenance,
    operator_approval_ref: candidate.operator_approval_ref,
  };
  const candidateSha256 = canonicalSha256(material);
  invariant(candidate.candidate_sha256 === undefined || candidate.candidate_sha256 === candidateSha256, `eligible media ${index} candidate SHA mismatch`);

  return { ...material, candidate_sha256: candidateSha256 };
});
const mediaStatus = eligibleMedia.length === 1
  ? 'unique_eligible_candidate_locked'
  : (eligibleMedia.length === 0 ? 'blocked_zero_eligible_candidates' : 'blocked_multiple_eligible_candidates');
const selectedMedia = eligibleMedia.length === 1 ? eligibleMedia[0] : null;
const mediaAuthority = {
  required_content_identity: requiredMediaContentIdentity,
  required_variant_keys: requiredMediaVariantKeys,
  selection_status: mediaStatus,
  eligible_candidate_count: eligibleMedia.length,
  selected_candidate: selectedMedia,
  fail_closed_on_zero_or_multiple: true,
  observation_sha256: sha256(observationText),
};
const mediaAuthoritySha256 = canonicalSha256(mediaAuthority);

const rollbackRows = observation.runtime_assets.rows.map((row) => ({
  asset_id: row.asset_id,
  route: row.route,
  primary_id: row.primary_id,
  observed_working_revision_id: row.working_revision_id,
  restore_published_revision_id: row.published_revision_id,
  restore_public_runtime_baseline_sha256: row.public_runtime_baseline_sha256,
  exact_target_bound: true,
  abort_on_missing_or_drifted_target: true,
}));
const rollbackBaselineSha256 = canonicalSha256(rollbackRows);

const permissions = {
  author: {
    approved: true,
    authority_reference: 'admin_user:1',
    public_label: 'FermatMind Editorial',
  },
  reviewer: {
    approved: reviewerTotpReady,
    authority_reference: reviewerTotpReady ? `solo_operator_review:${reviewRecordSha256}` : null,
    admin_user_id: 1,
    totp_required: reviewerTotpRequired,
    totp_enrolled: reviewerTotpEnrolled,
  },
  sources: {
    approved: true,
    authority_reference: `source_permissions:${sourcePermissionSha256}`,
    asset_count: sourcePermissions.length,
    visible_source_count: sourcePermissions.reduce((count, row) => count + row.source_ids.length, 0),
  },
  media: {
    approved: eligibleMedia.length === 1,
    authority_reference: eligibleMedia.length === 1 ? `media_authority:${mediaAuthoritySha256}` : null,
  },
};
const permissionsSha256 = canonicalSha256(permissions);

const releaseLockMaterial = {
  cohort_snapshot_sha256: snapshot.cohort_snapshot_sha256,
  package_payload_sha256: snapshot.package_payload_sha256,
  package_file_sha256: sha256(snapshotText),
  confirmation_record_sha256: confirmation.confirmation_record_sha256,
  review_record_sha256: reviewRecordSha256,
  source_permission_sha256: sourcePermissionSha256,
  media_authority_sha256: mediaAuthoritySha256,
  permissions_sha256: permissionsSha256,
  rollback_baseline_sha256: rollbackBaselineSha256,
  production_observation_sha256: sha256(observationText),
  owner_authority_sha256: sha256(ownerAuthorityText),
};
const releaseSnapshotSha256 = canonicalSha256(releaseLockMaterial);
const mediaReady = eligibleMedia.length === 1;
const readinessReady = mediaReady && reviewerTotpReady;
const blockers = [];
if (!reviewerTotpReady) blockers.push('admin_user_1_totp_enrollment_missing');
if (!mediaReady) blockers.push(eligibleMedia.length === 0
  ? 'unique_hub_hero_og_media_missing'
  : 'multiple_hub_hero_og_media_candidates');

const packagePayload = {
  schema_version: 'big5-zh6-promotion-readiness-package.v1',
  task_id: 'BIG5-AUTHORITY-V2-ZH6-PROMOTION-READINESS-49',
  cohort_id: snapshot.cohort_id,
  status: !mediaReady
    ? 'HOLD_FAIL_CLOSED_MEDIA_AUTHORITY'
    : (reviewerTotpReady ? 'PASS_PROMOTION_READINESS_ZERO_WRITE' : 'HOLD_FAIL_CLOSED_REVIEWER_TOTP'),
  ready_for_working_revision: readinessReady,
  ready_for_promotion: false,
  release_snapshot_sha256: releaseSnapshotSha256,
  release_snapshot_executable: false,
  release_lock_material: releaseLockMaterial,
  inputs: {
    snapshot_path: 'generated/big-five-authority-v2/big5-authority-v2-zh6-snapshot-48/final-snapshot-package.json',
    snapshot_file_sha256: sha256(snapshotText),
    confirmation_path: 'generated/big-five-authority-v2/big5-authority-v2-zh6-snapshot-48/exact-snapshot-confirmation.json',
    confirmation_file_sha256: sha256(confirmationText),
    owner_authority_path: 'generated/big-five-authority-v2/big5-authority-v2-zh6-promotion-readiness-49/pr48-owner-authority.json',
    owner_authority_sha256: sha256(ownerAuthorityText),
    production_observation_path: observationInputPath,
    production_observation_sha256: sha256(observationText),
  },
  counts: {
    assets: snapshot.assets.length,
    reviewed_assets: reviewRecord.assets.length,
    source_permission_assets: sourcePermissions.length,
    visible_sources: sourcePermissions.reduce((count, row) => count + row.source_ids.length, 0),
    runtime_baselines: rollbackRows.length,
    eligible_hub_media_candidates: eligibleMedia.length,
    selected_hub_media_assets: selectedMedia === null ? 0 : 1,
    promotion_eligible: 0,
  },
  editorial_authority: {
    review_record: reviewRecord,
    review_record_sha256: reviewRecordSha256,
  },
  source_permissions: {
    rows: sourcePermissions,
    source_permission_sha256: sourcePermissionSha256,
  },
  media_authority: {
    ...mediaAuthority,
    media_authority_sha256: mediaAuthoritySha256,
    inventory_counts: {
      all_media_assets: observation.media_inventory.all_media_assets,
      published_public_synced_cdn_verified: observation.media_inventory.published_public_synced_cdn_verified,
      with_verified_hero_and_og: observation.media_inventory.with_verified_hero_and_og,
      big_five_named_hero_og_count: observation.media_inventory.big_five_named_hero_og_count,
    },
    rejected_named_candidates: observation.media_inventory.big_five_named_hero_og,
  },
  permissions: {
    ...permissions,
    permissions_sha256: permissionsSha256,
  },
  runtime_baseline: {
    observed_at: observation.observed_at,
    observed_deployed_sha: observation.deployed_sha,
    rows: observation.runtime_assets.rows,
  },
  rollback_baseline: {
    rows: rollbackRows,
    rollback_baseline_sha256: rollbackBaselineSha256,
  },
  blockers,
  next_stage_gates: [
    'working_revision_not_created',
    'exact_revision_bound_editorial_review_not_created',
    'authenticated_preview_not_approved',
    'cohort_promotion_not_authorized',
  ],
  actions: observation.actions,
  repository_rule_impact: 'CMS/backend remains authoritative; this package adds a read-only fail-closed readiness gate and a narrow solo_operator record contract without changing global role separation or public runtime behavior.',
};
const packagePayloadSha256 = canonicalSha256(packagePayload);
const output = { ...packagePayload, package_payload_sha256: packagePayloadSha256 };
const outputText = `${JSON.stringify(output, null, 2)}\n`;

fs.writeFileSync(outputPath, outputText);
fs.writeFileSync(outputHashPath, `${sha256(outputText)}\n`);

console.log(`status=${output.status}`);
console.log(`release_snapshot_sha256=${releaseSnapshotSha256}`);
console.log(`package_payload_sha256=${packagePayloadSha256}`);
console.log(`package_file_sha256=${sha256(outputText)}`);
console.log(`eligible_hub_media_candidates=${eligibleMedia.length}`);
