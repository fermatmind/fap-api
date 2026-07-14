import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const read = (file) => JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8'));
const manifest = read('aggregate-manifest.json');
const perPage = read('per-page-release-report.json');
const dryRun = read('local-test-db-dry-run-report.json');
const authorization = read('production-authorization-packet.json');
const draftImport = read('draft-import-package.json');
const qa = read('qa_report.json');
const fail = (message) => { throw new Error(message); };
const sha256 = (value) => crypto.createHash('sha256').update(value).digest('hex');

const refreshedEntries = manifest.input_files.map((entry) => {
  const contents = fs.readFileSync(path.join(root, entry.path));
  return { path: entry.path, bytes: contents.byteLength, sha256: sha256(contents) };
});
const material = `${refreshedEntries.map((entry) => `${entry.path}\t${entry.bytes}\t${entry.sha256}`).join('\n')}\n`;
const refreshedPackageSha = sha256(material);
if (JSON.stringify(refreshedEntries) !== JSON.stringify(manifest.input_files)) fail('input file hash manifest drifted');
if (refreshedPackageSha !== manifest.package_sha256) fail('aggregate package SHA256 drifted');
if (perPage.package_sha256 !== manifest.package_sha256 || dryRun.package_sha256 !== manifest.package_sha256 || authorization.package_sha256 !== manifest.package_sha256 || qa.package_sha256 !== manifest.package_sha256) fail('output package SHA mismatch');
if (manifest.exact_counts.assets !== 231 || perPage.assets.length !== 231 || authorization.asset_count !== 231) fail('exact asset count must be 231');
if (new Set(perPage.assets.map((asset) => asset.route)).size !== 231) fail('routes must be unique');
if (new Set(perPage.assets.map((asset) => asset.asset_id)).size !== 231) fail('asset ids must be unique');
if (!Object.values(qa.checks).every(Boolean)) fail('one or more aggregate QA checks failed');
if (perPage.assets.some((asset) => asset.publish_eligible || asset.indexability_eligible || asset.sitemap_eligible || asset.llms_eligible || asset.llms_full_eligible)) fail('release surfaces must remain withheld');
if (authorization.status !== 'GO_DRAFT_ONLY_PRODUCTION_IMPORT_AUTHORIZED_PENDING_EXACT_PREFLIGHT' || authorization.approval_phrase_currently_executable !== true) fail('draft-only authorization packet must be executable');
if (authorization.pr37_merge_sha !== 'af99ac41406a2967b9f4778dc9da07b920bfbb7f') fail('PR37 merge SHA must be reconciled from GitHub');
if (typeof authorization.write_workflow.production_command !== 'string' || !authorization.write_workflow.production_command.includes('personality:big-five-authority-v2-draft-import')) fail('controlled draft writer command missing');
if (draftImport.asset_count !== 231 || draftImport.assets.length !== 231 || draftImport.pr37_merge_sha !== authorization.pr37_merge_sha || draftImport.authority_package_sha256 !== manifest.package_sha256) fail('draft import package identity drifted');
if (new Set(draftImport.assets.map((asset) => asset.asset_id)).size !== 231 || new Set(draftImport.assets.map((asset) => asset.route)).size !== 231) fail('draft import package assets must be unique');
if (draftImport.assets.some((asset) => asset.publish_eligible || asset.indexability_eligible || asset.sitemap_eligible || asset.llms_eligible || asset.llms_full_eligible)) fail('draft import package must remain no-public-release');
const draftImportRaw = fs.readFileSync(path.join(dir, 'draft-import-package.json'));
if (sha256(draftImportRaw) !== authorization.draft_import_package_sha256) fail('draft import package file SHA256 drifted');
if (dryRun.planned_create_count !== 231 || dryRun.planned_update_count !== 0 || dryRun.measured_database_write_delta !== 0) fail('dry-run counts drifted');

const phpDryRun = spawnSync('php', [path.join(dir, 'local-test-db-dry-run.php')], { encoding: 'utf8' });
if (phpDryRun.status !== 0) fail(`local/test DB dry-run failed: ${phpDryRun.stderr || phpDryRun.stdout}`);
const measured = JSON.parse(phpDryRun.stdout);
if (measured.package_sha256 !== manifest.package_sha256 || measured.planned_create_count !== 231 || measured.planned_update_count !== 0 || measured.measured_database_write_delta !== 0) fail('measured local/test DB dry-run differs from committed report');

console.log(`Big Five PR37 validation passed: 231 assets / ${manifest.input_file_count} inputs / ${manifest.package_sha256} / draft-only writer authorized / no public release`);
