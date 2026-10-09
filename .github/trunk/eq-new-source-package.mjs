import { createHash, createHmac } from 'node:crypto';
import { readFileSync, realpathSync, lstatSync } from 'node:fs';
import { resolve } from 'node:path';

export const packagePath = 'content_assets/eq_public/candidate/20261009-new-articles';
export const executorPaths = [
  'app/Console/Commands/ContentPromoteExactPackage.php',
  'app/Services/ContentPromotion/Adapters/EqNewSourceArticlePromotionAdapter.php',
  'app/Services/ContentPromotion/EqPublicArticlePackage.php',
  'app/Services/ContentPromotion/EqSourceExecutionMutex.php',
  'app/Services/ContentPromotion/ExactPackagePromotionService.php',
  'app/Services/ContentPromotion/PromotionContextFactory.php',
  'scripts/deploy/run_eq_new_source_article_publish.php',
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
    if (path.startsWith('/') || path.split('/').includes('..')) throw new Error('EQ_PACKAGE_PATH_INVALID');
    const file = resolve(root, path);
    if (lstatSync(file).isSymbolicLink() || !realpathSync(file).startsWith(`${root}/`)) throw new Error('EQ_PACKAGE_PATH_INVALID');
    return readFileSync(file);
  };
  const assets = bytes(`${packagePath}/assets.json`);
  const decoded = JSON.parse(assets);
  if (decoded.schema !== 'fermatmind.eq_public_article_candidates.v1' || decoded.candidates?.length !== 6) throw new Error('EQ_PACKAGE_SCHEMA_INVALID');
  const proofs = new Map();
  const targets = new Set();
  const slugs = {
    'EQ-03': 'eq60-score-and-results-guide',
    'EQ-04': 'emotional-intelligence-models-and-measures',
    'EQ-05': 'emotional-awareness-regulation-and-relationship-practice',
  };
  for (const row of decoded.candidates) {
    const { locale, org_id: org, slug } = row.identity ?? {};
    const key = `${row.page_id}:${locale}`;
    if (org !== 0 || !['en', 'zh-CN'].includes(locale) || slug !== slugs[row.page_id] || targets.has(key)) throw new Error('EQ_PACKAGE_SCOPE_INVALID');
    targets.add(key);
    for (const field of ['independent_review_input', 'independent_review_output']) {
      const proof = row[field];
      if (!proof?.path?.startsWith('content_assets/eq_public/reviews/20261009-new-articles/') || sha(bytes(proof.path)) !== proof.sha256) throw new Error('EQ_PACKAGE_REVIEW_DIGEST_INVALID');
      proofs.set(proof.path, proof.sha256);
    }
  }
  let material = `fermatmind.eq_public_article_candidates.v1\n${sha(assets)}\n`;
  for (const [path, digest] of [...proofs].sort(([a], [b]) => a < b ? -1 : a > b ? 1 : 0)) material += `${path}\n${digest}\n`;
  const executor = executorPaths.map(path => `${path}\n${sha(bytes(path))}\n`).join('');
  return {
    schema: 'eq.new_source_articles.binding.v1',
    package_path: packagePath, package_sha256: sha(material),
    executor_release_sha256: sha(executor),
    release_policy_sha256: sha(JSON.stringify(canonical(JSON.parse(bytes('config/content_promotion_release_policy.v2.json'))))),
    lane: 'W3', subscope: 'EQ-NEW-SOURCE-ARTICLES', expected_row_count: 3,
  };
}
export function workflowSignature(binding, key, source, run, attempt) {
  if (typeof key !== 'string' || key.length < 32 || !/^[a-f0-9]{40}$/.test(source) || !/^[1-9][0-9]*$/.test(String(run)) || attempt !== 1) throw new Error('EQ_WORKFLOW_IDENTITY_INVALID');
  const material = ['content-promotion-v2', source, run, attempt, binding.lane, binding.subscope, binding.package_sha256, binding.release_policy_sha256, binding.expected_row_count].join('|');
  return createHmac('sha256', key).update(material).digest('hex');
}

// Only this exact registration delta is unrelated to private-result publication.
// Every other shared-config change keeps conservative private consumer selection.
export function isEqOnlyPromotionRegistration(before, after) {
  const root = "        'content_assets/en-content-parity',\n";
  const lane = "        'W3' => ['W3-ARTICLES' => 'audit_compatible', 'W3-CAREER-GUIDES' => 'audit_compatible'],";
  if (!before.includes(root) || !before.includes(lane)) return false;
  return before.replace(root, `${root}        '${packagePath}',\n`)
    .replace(lane, lane.replace("],", ", 'EQ-NEW-SOURCE-ARTICLES' => 'audit_compatible'],")) === after;
}
