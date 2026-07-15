import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const relative = {
  intake: 'generated/big-five-authority-v2/big5-authority-v2-media-authority-41/approved-media-intake.json',
  candidateMap: 'generated/big-five-authority-v2/big5-authority-v2-media-og-34/candidate-media-map.json',
  requirements: 'generated/big-five-authority-v2/big5-authority-v2-media-og-34/upload-mapping-manifest.json',
};
const file = (name) => path.join(root, relative[name]);
const read = (name) => JSON.parse(fs.readFileSync(file(name), 'utf8'));
const sha256File = (name) => crypto.createHash('sha256').update(fs.readFileSync(file(name))).digest('hex');
const canonicalize = (value) => {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize(value[key])]));
  }
  return value;
};
const canonicalSha256 = (value) => crypto.createHash('sha256').update(JSON.stringify(canonicalize(value))).digest('hex');

const intake = read('intake');
const candidateMap = read('candidateMap');
const requirements = read('requirements');
if (intake.schema_version !== 'big5-approved-media-intake.v1') throw new Error('approved media intake schema mismatch');
if (!Array.isArray(intake.approved_assets)) throw new Error('approved_assets must be an array');
if (intake.approved_assets.length !== 0 || intake.operator_approval_claimed !== false) {
  throw new Error('Repository build is fail-closed and accepts only the current zero-approved intake; real approved inputs require the Laravel Media Library preflight.');
}
if (candidateMap.schema_version !== 'big5-candidate-media-map.v1' || candidateMap.mappings.length !== 231) throw new Error('PR34 candidate map mismatch');
if (requirements.schema_version !== 'big5-media-upload-mapping-manifest.v1' || requirements.requirements.length !== 18) throw new Error('PR34 requirement manifest mismatch');
if (requirements.requirements.reduce((sum, group) => sum + group.slot_requirements.length, 0) !== 54) throw new Error('PR34 grouped slot requirement mismatch');

const mappings = candidateMap.mappings.map((candidate) => ({
  candidate_key: candidate.candidate_key,
  page_family: candidate.page_family,
  locale: candidate.locale,
  route: candidate.route,
  source_package: candidate.source_package,
  source_type: candidate.source_type,
  mapping_status: 'missing_pending',
  slots: ['hero', 'inline', 'og'].map((slot) => ({
    slot,
    status: 'missing_pending',
    content_identity: `big5:${candidate.page_family}:${candidate.locale}:${slot}`,
    media_asset_id: null,
    media_asset_key: null,
    variant_key: null,
    public_url: null,
    alt: null,
    rights: null,
    license: null,
    provenance: null,
    operator_approval_ref: null,
    reason: 'No approved intake entry passed every Media Library authority gate for this grouped slot requirement.',
  })),
  cms_write_executed: false,
  media_upload_executed: false,
  publish_state_change: false,
  indexability_change: false,
}));

const mappingPackage = {
  schema_version: 'big5-media-authority-mapping-package.v1',
  source_inventory: {
    pr34_candidate_map_path: relative.candidateMap,
    pr34_candidate_map_sha256: sha256File('candidateMap'),
    pr34_requirements_path: relative.requirements,
    pr34_requirements_sha256: sha256File('requirements'),
  },
  intake: {
    path: relative.intake,
    sha256: sha256File('intake'),
    schema_version: intake.schema_version,
    operator_approval_claimed: false,
    approved_entry_count: 0,
  },
  counts: {
    candidate_pages: 231,
    family_locale_requirement_groups: 18,
    grouped_slot_requirements: 54,
    approved_grouped_slot_requirements: 0,
    pending_grouped_slot_requirements: 54,
    total_page_slots: 693,
    mapped_page_slots: 0,
    missing_pending_page_slots: 693,
  },
  mappings,
  actions: {
    database_reads: 0,
    database_writes: 0,
    media_uploads: 0,
    media_library_writes: 0,
    cms_mapping_writes: 0,
    publish_state_changes: 0,
    indexability_changes: 0,
    deployments: 0,
  },
};
const mappingPackageSha256 = canonicalSha256(mappingPackage);
const preflightReport = {
  schema_version: 'big5-media-authority-preflight-report.v1',
  status: 'PASS_FAIL_CLOSED_NO_APPROVED_ASSETS',
  mode: 'preflight_only_zero_write',
  mapping_package_sha256: mappingPackageSha256,
  counts: mappingPackage.counts,
  actions: mappingPackage.actions,
  blockers: [
    '18 real family-locale approved media groups are not present in repository authority.',
    'No grouped slot may map until Media Library identity, public-safe URL, exact hero/inline/og variant, locale alt, rights/license, provenance, operator approval reference, and content identity all match.',
  ],
};

fs.writeFileSync(path.join(dir, 'mapping-package.json'), `${JSON.stringify(mappingPackage, null, 2)}\n`);
fs.writeFileSync(path.join(dir, 'mapping-package.sha256'), `${mappingPackageSha256}\n`);
fs.writeFileSync(path.join(dir, 'preflight-report.json'), `${JSON.stringify(preflightReport, null, 2)}\n`);
console.log(`built PR41 fail-closed media package ${mappingPackageSha256}: 231 pages / 693 missing_pending slots / 0 writes`);
