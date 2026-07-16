import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const candidatePackage = JSON.parse(readFileSync(resolve(here, 'candidate-package.json'), 'utf8'));
const attestation = JSON.parse(readFileSync(resolve(here, 'human-review-attestation.json'), 'utf8'));

const fail = (message) => {
  throw new Error(message);
};
const invariant = (condition, message) => {
  if (!condition) fail(message);
};
const sha256 = (value) => createHash('sha256').update(value).digest('hex');

invariant(attestation.schema_version === '1.0.0', 'unexpected attestation schema version');
invariant(attestation.cohort_id === candidatePackage.cohort_id, 'attestation cohort does not match candidate package');
invariant(attestation.assets.length === candidatePackage.assets.length, 'attestation asset count does not match candidate package');
invariant(attestation.review_scope.content === true, 'content review scope must be explicit');
invariant(attestation.review_scope.source_mapping === true, 'source-mapping review scope must be explicit');
invariant(attestation.review_scope.claim_boundaries === true, 'claim-boundary review scope must be explicit');
invariant(attestation.review_scope.media_permission === false, 'media permission must remain outside this review');
invariant(attestation.review_scope.cms_or_production_write === false, 'attestation must not authorize a CMS or production write');
invariant(attestation.review_scope.publication_or_indexability === false, 'attestation must not authorize publication or indexability');

for (let index = 0; index < candidatePackage.assets.length; index += 1) {
  const candidate = candidatePackage.assets[index];
  const reviewed = attestation.assets[index];
  invariant(reviewed.asset_id === candidate.asset_id, `attestation asset ${index} identity drifted`);
  invariant(reviewed.candidate_content_sha256 === candidate.candidate_content_sha256, `${candidate.asset_id} attestation content hash drifted`);
}

if (attestation.status === 'pending_real_human_attestation') {
  invariant(attestation.reviewer.admin_user_id === null, 'pending reviewer ID must be null');
  invariant(attestation.reviewer.public_label === null, 'pending reviewer label must be null');
  invariant(attestation.reviewer.role === null, 'pending reviewer role must be null');
  invariant(attestation.reviewer.authority_reference === null, 'pending reviewer authority reference must be null');
  invariant(attestation.reviewed_at === null, 'pending reviewed_at must be null');
  invariant(attestation.review_record_sha256 === null, 'pending review hash must be null');
  if (attestation.superseded_attestation !== undefined) {
    invariant(attestation.superseded_attestation.status === 'superseded_after_candidate_cleanup', 'superseded attestation status is invalid');
    invariant(typeof attestation.superseded_attestation.reviewed_at === 'string' && !Number.isNaN(Date.parse(attestation.superseded_attestation.reviewed_at)), 'superseded attestation reviewed_at is invalid');
    invariant(/^admin_user:[1-9][0-9]*$/u.test(attestation.superseded_attestation.reviewer_authority_reference), 'superseded reviewer authority is invalid');
    invariant(/^[a-f0-9]{64}$/u.test(attestation.superseded_attestation.review_record_sha256), 'superseded review hash is invalid');
    invariant(typeof attestation.superseded_attestation.reason === 'string' && attestation.superseded_attestation.reason.trim() !== '', 'superseded attestation reason is missing');
  }
  console.log('PASS: human-review attestation is correctly pending a real CMS administrator identity and confirmation.');
  process.exit(0);
}

invariant(attestation.status === 'approved_by_real_human', 'unsupported attestation status');
invariant(Number.isInteger(attestation.reviewer.admin_user_id) && attestation.reviewer.admin_user_id > 0, 'approved attestation requires a positive admin_user_id');
invariant(typeof attestation.reviewer.public_label === 'string' && attestation.reviewer.public_label.trim() !== '', 'approved attestation requires a public reviewer label');
invariant(['content_reviewer', 'editor', 'subject_matter_reviewer', 'operator_reviewer'].includes(attestation.reviewer.role), 'approved attestation reviewer role is not allowed');
invariant(attestation.reviewer.authority_reference === `admin_user:${attestation.reviewer.admin_user_id}`, 'approved attestation authority reference must bind the admin user ID');
invariant(typeof attestation.reviewed_at === 'string' && !Number.isNaN(Date.parse(attestation.reviewed_at)), 'approved attestation requires an ISO 8601 reviewed_at');

const reviewRecord = {
  schema_version: attestation.schema_version,
  artifact: attestation.artifact,
  cohort_id: attestation.cohort_id,
  candidate_package: attestation.candidate_package,
  status: attestation.status,
  review_scope: attestation.review_scope,
  attestation_statement: attestation.attestation_statement,
  reviewer: attestation.reviewer,
  reviewed_at: attestation.reviewed_at,
  assets: attestation.assets,
};
invariant(attestation.review_record_sha256 === sha256(JSON.stringify(reviewRecord)), 'approved attestation review_record_sha256 mismatch');

console.log('PASS: exact six-page cohort has a hash-bound real-human review attestation.');
