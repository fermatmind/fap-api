import { createHash, createHmac } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFileSync, realpathSync, lstatSync } from 'node:fs';
import { resolve } from 'node:path';
import { canonical, isIqOnlyPromotionRegistration, isIqOnlyContextFactoryChange } from './iq-public-scale-package.mjs';
import { isEqOnlyPromotionRegistration } from './eq-new-source-package.mjs';

export const packagePath = 'content_assets/iq_public/articles/20261010-v1';
export const packageSha256 = 'ca6612bf60a63e2f8f253658587e0299997e82a3dfecad11994ee7f21d79fcad';
export const seoPackagePath = 'content_assets/iq_public/articles/20261015-seo-v1';
export const seoPackageSha256 = 'b9755f9564f3ff6b39a5c987cac2c49d0fda4c00b3ed2713b6612fb5d361c041';
export const parityPackagePath = 'content_assets/iq_public/articles/20261015-en-parity';
export const parityPackageSha256 = '1a0ad2975484f667e3e6da3c529c31a87697283552207a8945a743e95880262a';
export const mensaPackagePath = 'content_assets/iq_public/articles/20261015-mensa-v1';
export const mensaPackageSha256 = '32230e74353bab576906b97d58c171579163557354abca3b87888edaecd9811c';
export const matrixPackagePath = 'content_assets/iq_public/articles/20261015-matrix-v1';
export const matrixPackageSha256 = 'e6a16981341c69a66e3d2e8f39ba67047acf47f1421313dff913dc1c98e4facf';
export const comparisonPackagePath = 'content_assets/iq_public/articles/20261016-comparison-v1';
export const comparisonPackageSha256 = '7cc18306373b74247d410bc00aebee3df675c7b751d8253fd5d683340b1c19f9';
export const executorPaths = [
  "app/Console/Commands/ContentPromoteExactPackage.php",
  "app/Http/Controllers/API/V0_5/Cms/ArticleController.php",
  "app/Models/Article.php",
  "app/Models/ArticleSeoMeta.php",
  "app/Models/ArticleTranslationRevision.php",
  "app/Services/Cms/ArticleBodyHeadingGuard.php",
  "app/Services/Cms/ArticleMaterialDecisionService.php",
  "app/Services/Cms/ArticlePublicListQuery.php",
  "app/Services/Cms/ArticleSeoService.php",
  "app/Services/Cms/IqPublicArticleFaqProjection.php",
  "app/Services/ContentPromotion/Adapters/IqPublicArticlePromotionAdapter.php",
  "app/Services/ContentPromotion/ExactPackagePathGuard.php",
  "app/Services/ContentPromotion/ExactPackagePromotionService.php",
  "app/Services/ContentPromotion/IqPublicArticlePackage.php",
  "app/Services/ContentPromotion/PromotionAdapterRegistry.php",
  "app/Services/ContentPromotion/PromotionAdapterResultFactory.php",
  "app/Services/ContentPromotion/PromotionContext.php",
  "app/Services/ContentPromotion/PromotionContextFactory.php",
  "app/Services/ContentPromotion/PromotionExecutionContext.php",
  "app/Services/ContentPromotion/PromotionReceiptStore.php",
  "app/Services/ContentPromotion/PromotionRollbackSnapshotService.php",
  "app/Services/ContentPromotion/PromotionTargetSet.php",
  "app/Services/SEO/SeoDiscoverabilityCacheInvalidator.php",
  "app/Support/ControlledReceiptWriter.php",
  "config/content_promotion.php",
  "scripts/deploy/run_iq_public_article_publish.php"
];
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
export function readCandidateRows(backendRoot, expectedPackageSha256 = packageSha256) {
  const code = 'require $argv[1]."/app/Services/ContentPromotion/PromotionContextFactory.php"; require $argv[1]."/app/Services/Cms/ArticleBodyHeadingGuard.php"; require $argv[1]."/app/Services/ContentPromotion/IqPublicArticlePackage.php"; echo json_encode((new App\\Services\\ContentPromotion\\IqPublicArticlePackage(new App\\Services\\Cms\\ArticleBodyHeadingGuard))->read($argv[1],$argv[2]), JSON_THROW_ON_ERROR);';
  return JSON.parse(execFileSync('php', ['-r', code, realpathSync(backendRoot), expectedPackageSha256], { encoding: 'utf8', timeout: 30000, maxBuffer: 2000000, stdio: ['pipe', 'pipe', 'pipe'] }));
}
export function inspectPackage(backendRoot, expectedPackageSha256 = packageSha256) {
  const root = realpathSync(backendRoot);
  const selected = expectedPackageSha256 === packageSha256 ? { path: packagePath, count: 10 }
    : expectedPackageSha256 === seoPackageSha256 ? { path: seoPackagePath, count: 2 }
    : expectedPackageSha256 === parityPackageSha256 ? { path: parityPackagePath, count: 6 }
    : expectedPackageSha256 === mensaPackageSha256 ? { path: mensaPackagePath, count: 2 }
    : expectedPackageSha256 === matrixPackageSha256 ? { path: matrixPackagePath, count: 4 }
    : expectedPackageSha256 === comparisonPackageSha256 ? { path: comparisonPackagePath, count: 4 } : null;
  if (!selected || readCandidateRows(root, expectedPackageSha256).length !== selected.count) throw new Error('IQ_ARTICLE_PACKAGE_SCOPE_INVALID');
  const material = executorPaths.map(path => {
    const file = resolve(root, path);
    const stat = lstatSync(file);
    if (!stat.isFile() || stat.nlink !== 1 || realpathSync(file) !== file) throw new Error('IQ_ARTICLE_EXECUTOR_INVALID');
    return `${path}\n${sha(readFileSync(file))}\n`;
  }).join('');
  return { schema: 'iq.public_articles.binding.v1', package_path: selected.path, package_sha256: expectedPackageSha256,
    executor_release_sha256: sha(material), release_policy_sha256: sha(JSON.stringify(canonical(JSON.parse(readFileSync(resolve(root, 'config/content_promotion_release_policy.v2.json'), 'utf8'))))),
    lane: 'W3', subscope: 'IQ-PUBLIC-ARTICLES', expected_row_count: selected.count };
}
export function workflowSignature(binding, key, source, run, attempt) {
  if (typeof key !== 'string' || key.length < 32 || !/^[a-f0-9]{40}$/.test(source) || !/^[1-9][0-9]*$/.test(String(run)) || attempt !== 1) throw new Error('IQ_ARTICLE_WORKFLOW_IDENTITY_INVALID');
  const material = ['content-promotion-v2', source, run, attempt, binding.lane, binding.subscope, binding.package_sha256, binding.release_policy_sha256, binding.expected_row_count, binding.executor_release_sha256].join('|');
  return createHmac('sha256', key).update(material).digest('hex');
}
export function isIqArticleOnlyPromotionRegistration(before, after) {
  const comparisonRoot = `        '${comparisonPackagePath}',\n`;
  if (after.includes(comparisonRoot) && !before.includes(comparisonRoot)) {
    const without = after.replace(comparisonRoot, '');
    return without === before || isIqArticleOnlyPromotionRegistration(before, without);
  }
  const matrixRoot = `        '${matrixPackagePath}',\n`;
  if (after.includes(matrixRoot) && !before.includes(matrixRoot)) {
    const without = after.replace(matrixRoot, '');
    return without === before || isIqArticleOnlyPromotionRegistration(before, without);
  }
  const mensaRoot = `        '${mensaPackagePath}',\n`;
  if (after.includes(mensaRoot) && !before.includes(mensaRoot)) {
    const without = after.replace(mensaRoot, '');
    return without === before || isIqArticleOnlyPromotionRegistration(before, without);
  }
  const parityRoot = `        '${parityPackagePath}',\n`;
  if (after.includes(parityRoot) && !before.includes(parityRoot)) {
    const without = after.replace(parityRoot, '');
    return without === before || isIqArticleOnlyPromotionRegistration(before, without);
  }
  const refreshRoot = `        '${seoPackagePath}',\n`;
  if (after.includes(refreshRoot) && !before.includes(refreshRoot)) {
    const without = after.replace(refreshRoot, '');
    return without === before || isIqArticleOnlyPromotionRegistration(before, without);
  }
  const root = `        '${packagePath}',\n`;
  const lane = ", 'IQ-PUBLIC-ARTICLES' => 'audit_compatible'";
  if (!after.includes(root) || !after.includes(lane) || before.includes(root) || before.includes(lane)) return false;
  const without = after.replace(root, '').replace(lane, '');
  return without === before || isIqOnlyPromotionRegistration(before, without) || isEqOnlyPromotionRegistration(before, without);
}

export function isIqArticleOnlyContextFactoryChange(before, after) {
  const addition = `        // The IQ article executor contract binds its exact implementation bytes.
        // Existing lanes retain their established signature wire format.
        if ($lane === 'W3' && $subscope === 'IQ-PUBLIC-ARTICLES') {
            $signatureMaterial .= '|'.$executorReleaseSha256;
        }
`;
  if (before.includes(addition) || !after.includes(addition)) return false;
  const without = after.replace(addition, '');
  return without === before || isIqOnlyContextFactoryChange(before, without);
}
