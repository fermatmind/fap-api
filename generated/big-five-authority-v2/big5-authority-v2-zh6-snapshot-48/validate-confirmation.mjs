import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const packagePath = resolve(here, 'final-snapshot-package.json');
const confirmationPath = resolve(here, 'exact-snapshot-confirmation.json');
const packageText = readFileSync(packagePath, 'utf8');
const packageJson = JSON.parse(packageText);
const confirmation = JSON.parse(readFileSync(confirmationPath, 'utf8'));

const sha256 = (value) => createHash('sha256').update(value).digest('hex');
const invariant = (condition, message) => {
  if (!condition) throw new Error(message);
};

invariant(confirmation.schema_version === '1.0.0', 'unexpected confirmation schema version');
invariant(confirmation.artifact === 'big-five-authority-v2-zh6-exact-snapshot-confirmation', 'unexpected confirmation artifact');
invariant(confirmation.cohort_id === packageJson.cohort_id, 'confirmation cohort does not match the snapshot package');
invariant(confirmation.status === 'approved_by_real_human', 'exact snapshot confirmation is still pending');
invariant(confirmation.cohort_snapshot_sha256 === packageJson.cohort_snapshot_sha256, 'confirmed cohort snapshot SHA drifted');
invariant(confirmation.package_payload_sha256 === packageJson.package_payload_sha256, 'confirmed package payload SHA drifted');
invariant(confirmation.package_file_sha256 === sha256(packageText), 'confirmed package file SHA drifted');
invariant(confirmation.requested_reviewer_admin_user_id === 1, 'requested reviewer must remain admin_user:1');
invariant(Number.isInteger(confirmation.reviewer_admin_user_id) && confirmation.reviewer_admin_user_id === 1, 'confirmation must bind admin_user:1');
invariant(typeof confirmation.confirmed_at === 'string' && !Number.isNaN(Date.parse(confirmation.confirmed_at)), 'confirmation timestamp is invalid');

const exactConfirmationPhrase = `我已阅读并批准 BIG5-AUTHORITY-V2-ZH6-SNAPSHOT-48 最终公开 snapshot；cohort_snapshot_sha256=${packageJson.cohort_snapshot_sha256}；package_payload_sha256=${packageJson.package_payload_sha256}；package_file_sha256=${sha256(packageText)}；CMS reviewer_admin_user_id=1。`;
invariant(confirmation.expected_confirmation_phrase === exactConfirmationPhrase, 'expected confirmation phrase does not match the locked package hashes and reviewer');
invariant(confirmation.confirmation_phrase === exactConfirmationPhrase, 'confirmation phrase does not match the locked package hashes and reviewer');

const expectedScope = {
  six_public_snapshots: true,
  reader_safe_copy: true,
  independent_faq_field_with_35_items: true,
  three_visible_sources_per_page: true,
  claim_boundaries: true,
  cms_or_database_write: false,
  working_revision_write: false,
  media_authority: false,
  promotion_or_publication: false,
  indexability_sitemap_llms_schema: false,
  deployment_cache_or_search: false,
};
invariant(JSON.stringify(confirmation.approval_scope) === JSON.stringify(expectedScope), 'confirmation scope drifted');

const reviewRecord = {
  cohort_id: confirmation.cohort_id,
  cohort_snapshot_sha256: confirmation.cohort_snapshot_sha256,
  package_payload_sha256: confirmation.package_payload_sha256,
  package_file_sha256: confirmation.package_file_sha256,
  reviewer_admin_user_id: confirmation.reviewer_admin_user_id,
  confirmed_at: confirmation.confirmed_at,
  approval_scope: confirmation.approval_scope,
  confirmation_phrase: confirmation.confirmation_phrase,
};
invariant(confirmation.confirmation_record_sha256 === sha256(JSON.stringify(reviewRecord)), 'confirmation record SHA mismatch');

console.log('PASS: real-human approval is bound to the exact immutable ZH6 snapshot, package payload, package file, admin_user:1, timestamp, and non-promotion scope.');
console.log(`Confirmation record SHA256: ${confirmation.confirmation_record_sha256}`);
