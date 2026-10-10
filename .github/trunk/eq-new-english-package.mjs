import { createHash } from 'node:crypto';
import { readFileSync, realpathSync, lstatSync } from 'node:fs';
import { resolve } from 'node:path';
import { inspectPackage as inspectSource, packagePath, canonical, workflowSignature } from './eq-new-source-package.mjs';

export { packagePath, workflowSignature };
export const executorPaths = [
  'app/Console/Commands/ContentPromoteExactPackage.php',
  'app/Services/ContentPromotion/Adapters/ArticleCmsPromotionAdapter.php',
  'app/Services/ContentPromotion/ArticleCmsPromotionAuthority.php',
  'app/Services/ContentPromotion/EqEnglishArticlePackage.php',
  'app/Services/ContentPromotion/EqPublicArticlePackage.php',
  'app/Services/ContentPromotion/EqSourceExecutionMutex.php',
  'app/Services/ContentPromotion/ExactPackagePromotionService.php',
  'app/Services/ContentPromotion/PromotionAdapterRegistry.php',
  'app/Services/ContentPromotion/PromotionContextFactory.php',
  'scripts/deploy/run_eq_new_english_article_publish.php',
].sort();
const sha = bytes => createHash('sha256').update(bytes).digest('hex');

export function inspectPackage(backendRoot) {
  const root = realpathSync(backendRoot);
  const bytes = path => {
    const file = resolve(root, path);
    if (path.startsWith('/') || path.split('/').includes('..') || lstatSync(file).isSymbolicLink()
      || !realpathSync(file).startsWith(`${root}/`)) throw new Error('EQ_ENGLISH_PACKAGE_PATH_INVALID');
    return readFileSync(file);
  };
  const source = inspectSource(root);
  const raw = bytes(`${packagePath}/en-publication.json`);
  const marker = JSON.parse(raw);
  if (Object.keys(marker).sort().join('|') !== 'expected_row_count|locale|schema|source_commit|source_package_sha256|staging_source_commit'
    || marker.schema !== 'fermatmind.eq_english_article_publication.v1' || marker.locale !== 'en'
    || marker.expected_row_count !== 3 || !/^[a-f0-9]{40}$/.test(marker.source_commit)
    || !/^[a-f0-9]{40}$/.test(marker.staging_source_commit)
    || marker.source_package_sha256 !== source.package_sha256) throw new Error('EQ_ENGLISH_PACKAGE_MARKER_INVALID');
  const executor = executorPaths.map(path => `${path}\n${sha(bytes(path))}\n`).join('');
  return {
    schema: 'eq.new_english_articles.binding.v1', package_path: packagePath,
    package_sha256: sha(`${marker.schema}\n${sha(raw)}\n${source.package_sha256}\n`),
    executor_release_sha256: sha(executor),
    release_policy_sha256: sha(JSON.stringify(canonical(JSON.parse(bytes('config/content_promotion_release_policy.v2.json'))))),
    lane: 'W3', subscope: 'W3-ARTICLES', expected_row_count: 3,
    accepted_source_commit: marker.source_commit,
  };
}
