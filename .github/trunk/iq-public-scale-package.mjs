import { createHash, createHmac } from 'node:crypto';
import { readFileSync, realpathSync, lstatSync } from 'node:fs';
import { resolve } from 'node:path';
import { isEqOnlyPromotionRegistration } from './eq-new-source-package.mjs';

export const packagePath = 'content_assets/iq_public/entry/20261009-v1';
export const packageSha256 = 'f6e170de25839e081342c8953d17a17a85938c2c9ebb2b07d9cf8ff48351cb7f';
export const executorPaths = [
  'app/Console/Commands/ContentPromoteExactPackage.php',
  'app/Services/ContentPromotion/Adapters/IqPublicScalePromotionAdapter.php',
  'app/Services/ContentPromotion/IqPublicEntryPackage.php',
  'app/Services/ContentPromotion/ExactPackagePromotionService.php',
  'app/Services/ContentPromotion/PromotionContextFactory.php',
  'app/Services/ContentPromotion/PromotionReceiptStore.php',
  'app/Services/ContentPromotion/PromotionRollbackSnapshotService.php',
  'app/Services/Scale/ScaleRegistry.php',
  'app/Services/Scale/ScaleRegistryWriter.php',
  'app/Services/Scale/PublicScaleCatalogCache.php',
  'scripts/deploy/run_iq_public_scale_publish.php',
].sort();
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
export function canonical(value) {
  if (Array.isArray(value)) return value.map(canonical);
  if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map(key => [key, canonical(value[key])]));
  return value;
}
export function inspectPackage(backendRoot) {
  const root = realpathSync(backendRoot);
  const bytes = path => {
    if (path.startsWith('/') || path.split('/').includes('..')) throw new Error('IQ_PACKAGE_PATH_INVALID');
    const file = resolve(root, path);
    const stat = lstatSync(file);
    if (!stat.isFile() || stat.nlink !== 1 || !realpathSync(file).startsWith(`${root}/`)) throw new Error('IQ_PACKAGE_PATH_INVALID');
    return readFileSync(file);
  };
  if (lstatSync(resolve(root, packagePath)).isSymbolicLink()) throw new Error('IQ_PACKAGE_PATH_INVALID');
  const manifestBytes = bytes(`${packagePath}/manifest.json`);
  const manifest = JSON.parse(manifestBytes);
  if (manifest.schema !== 'fermatmind.iq_public_entry_package.v1' || manifest.org_id !== 0
    || manifest.code !== 'IQ_RAVEN' || manifest.primary_slug !== 'iq-test-intelligence-quotient-assessment'
    || manifest.targets?.length !== 2) throw new Error('IQ_PACKAGE_SCOPE_INVALID');
  const names = ['IQ-01-en.json', 'IQ-01-en.md', 'IQ-01-zh-CN.json', 'IQ-01-zh-CN.md', 'iq-all-source-fresh-result.json', 'iq-publication-three-fresh-result.json'];
  if (Object.keys(manifest.files ?? {}).join('|') !== names.join('|')) throw new Error('IQ_PACKAGE_FILES_INVALID');
  let material = `${manifest.schema}\n${sha(manifestBytes)}\n`;
  for (const name of names) {
    const digest = sha(bytes(`${packagePath}/${name}`));
    if (digest !== manifest.files[name]) throw new Error('IQ_PACKAGE_FILE_DIGEST_INVALID');
    material += `${name}\n${digest}\n`;
  }
  if (sha(material) !== packageSha256) throw new Error('IQ_PACKAGE_DIGEST_INVALID');
  const executor = executorPaths.map(path => `${path}\n${sha(bytes(path))}\n`).join('');
  return {
    schema: 'iq.public_scale.binding.v1', package_path: packagePath, package_sha256: packageSha256,
    executor_release_sha256: sha(executor),
    release_policy_sha256: sha(JSON.stringify(canonical(JSON.parse(bytes('config/content_promotion_release_policy.v2.json'))))),
    lane: 'W6', subscope: 'iq-public-scale', expected_row_count: 2,
  };
}
export function workflowSignature(binding, key, source, run, attempt) {
  if (typeof key !== 'string' || key.length < 32 || !/^[a-f0-9]{40}$/.test(source) || !/^[1-9][0-9]*$/.test(String(run)) || attempt !== 1) throw new Error('IQ_WORKFLOW_IDENTITY_INVALID');
  const material = ['content-promotion-v2', source, run, attempt, binding.lane, binding.subscope, binding.package_sha256, binding.release_policy_sha256, binding.expected_row_count].join('|');
  return createHmac('sha256', key).update(material).digest('hex');
}

export function isIqOnlyPromotionRegistration(before, after) {
  const root = "        'content_assets/eq_public/candidate/20261009-new-articles',\n";
  const lane = "        'W6' => ['iq' => 'fail_closed_legacy_audit'],";
  const addedRoot = `${root}        '${packagePath}',\n`;
  const addedLane = lane.replace("],", ", 'iq-public-scale' => 'audit_compatible'],");
  if (!before.includes(lane) || !after.includes(addedRoot) || !after.includes(addedLane)) return false;
  const addIq = source => source.replace(root, addedRoot).replace(lane, addedLane);
  if (addIq(before) === after) return true;
  // An unreleased EQ commit remains in the exact-SHA CI union. Only its
  // separately proven registration delta may accompany this exact IQ delta.
  const withoutIq = after.replace(addedRoot, root).replace(addedLane, lane);
  return addIq(withoutIq) === after && isEqOnlyPromotionRegistration(before, withoutIq);
}
