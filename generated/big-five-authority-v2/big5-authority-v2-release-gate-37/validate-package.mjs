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
if (authorization.status !== 'NO_GO_PENDING_ELIGIBILITY_REPAIR_AND_EXACT_PRODUCTION_AUTHORIZATION' || authorization.approval_phrase_currently_executable !== false) fail('authorization must remain fail closed');
if (authorization.write_workflow.production_command !== null) fail('PR37 must not expose an executable production writer');
if (dryRun.planned_create_count !== 231 || dryRun.planned_update_count !== 0 || dryRun.measured_database_write_delta !== 0) fail('dry-run counts drifted');

const phpDryRun = spawnSync('php', [path.join(dir, 'local-test-db-dry-run.php')], { encoding: 'utf8' });
if (phpDryRun.status !== 0) fail(`local/test DB dry-run failed: ${phpDryRun.stderr || phpDryRun.stdout}`);
const measured = JSON.parse(phpDryRun.stdout);
if (measured.package_sha256 !== manifest.package_sha256 || measured.planned_create_count !== 231 || measured.planned_update_count !== 0 || measured.measured_database_write_delta !== 0) fail('measured local/test DB dry-run differs from committed report');

console.log(`Big Five PR37 validation passed: 231 assets / ${manifest.input_file_count} inputs / ${manifest.package_sha256} / zero writes / NO_GO`);
