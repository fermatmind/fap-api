import { createHash, createHmac } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFileSync, realpathSync, lstatSync } from 'node:fs';
import { resolve } from 'node:path';
import { isIqArticleOnlyPromotionRegistration, isIqArticleOnlyContextFactoryChange } from './iq-public-article-package.mjs';
import { canonical } from './iq-public-scale-package.mjs';

export const packagePath = 'content_assets/iq_public/topics/20261010-v1';
export const packageSha256 = 'ad0e2d75cdee2f969582620fb6157267dc661c688122900a12ca4a4f7359f2af';
export const executorPaths = [
  "app/Console/Commands/ContentPromoteExactPackage.php",
  "app/Http/Controllers/API/V0_5/Cms/TopicController.php",
  "app/Models/Article.php",
  "app/Models/ArticleSeoMeta.php",
  "app/Models/ArticleTranslationRevision.php",
  "app/Models/TopicProfile.php",
  "app/Models/TopicProfileEntry.php",
  "app/Models/TopicProfileRevision.php",
  "app/Models/TopicProfileSection.php",
  "app/Models/TopicProfileSeoMeta.php",
  "app/Services/Cms/ArticleBodyHeadingGuard.php",
  "app/Services/Cms/IqEqTopicFaqProjection.php",
  "app/Services/Cms/TopicEntryResolverService.php",
  "app/Services/Cms/TopicProfileSeoService.php",
  "app/Services/Cms/TopicProfileService.php",
  "app/Services/ContentPromotion/Adapters/IqEqTopicPromotionAdapter.php",
  "app/Services/ContentPromotion/ExactPackagePathGuard.php",
  "app/Services/ContentPromotion/ExactPackagePromotionService.php",
  "app/Services/ContentPromotion/IqEqTopicPackage.php",
  "app/Services/ContentPromotion/IqEqTopicPrerequisites.php",
  "app/Services/ContentPromotion/IqEqTopicProjection.php",
  "app/Services/ContentPromotion/IqPublicArticlePackage.php",
  "app/Services/ContentPromotion/IqPublicEntryPackage.php",
  "app/Services/ContentPromotion/PromotionAdapterRegistry.php",
  "app/Services/ContentPromotion/PromotionAdapterResultFactory.php",
  "app/Services/ContentPromotion/PromotionContext.php",
  "app/Services/ContentPromotion/PromotionContextFactory.php",
  "app/Services/ContentPromotion/PromotionExecutionContext.php",
  "app/Services/ContentPromotion/PromotionReceiptStore.php",
  "app/Services/ContentPromotion/PromotionRollbackSnapshotService.php",
  "app/Services/ContentPromotion/PromotionTargetSet.php",
  "app/Services/Scale/ScaleRegistry.php",
  "app/Support/ControlledReceiptWriter.php",
  "config/content_promotion.php",
  "scripts/deploy/run_iq_eq_topic_publish.php"
];
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
export function readCandidateRows(backendRoot) {
  const code = 'require $argv[1]."/app/Services/ContentPromotion/PromotionContextFactory.php"; require $argv[1]."/app/Services/ContentPromotion/IqEqTopicPackage.php"; echo json_encode((new App\\Services\\ContentPromotion\\IqEqTopicPackage)->read($argv[1],$argv[2]), JSON_THROW_ON_ERROR);';
  return JSON.parse(execFileSync('php', ['-r', code, realpathSync(backendRoot), packageSha256], { encoding: 'utf8', timeout: 30000, maxBuffer: 2000000, stdio: ['pipe', 'pipe', 'pipe'] }));
}
export function inspectPackage(backendRoot) {
  const root = realpathSync(backendRoot);
  if (readCandidateRows(root).length !== 2) throw new Error('IQ_EQ_TOPIC_PACKAGE_SCOPE_INVALID');
  const material = executorPaths.map(path => {
    const file = resolve(root, path);
    const stat = lstatSync(file);
    if (!stat.isFile() || stat.nlink !== 1 || realpathSync(file) !== file) throw new Error('IQ_EQ_TOPIC_EXECUTOR_INVALID');
    return `${path}\n${sha(readFileSync(file))}\n`;
  }).join('');
  return { schema: 'iq.eq.topic.binding.v1', package_path: packagePath, package_sha256: packageSha256,
    executor_release_sha256: sha(material), release_policy_sha256: sha(JSON.stringify(canonical(JSON.parse(readFileSync(resolve(root, 'config/content_promotion_release_policy.v2.json'), 'utf8'))))),
    lane: 'W3', subscope: 'IQ-EQ-TOPIC', expected_row_count: 2 };
}
export function workflowSignature(binding, key, source, run, attempt) {
  if (typeof key !== 'string' || key.length < 32 || !/^[a-f0-9]{40}$/.test(source) || !/^[1-9][0-9]*$/.test(String(run)) || attempt !== 1) throw new Error('IQ_EQ_TOPIC_WORKFLOW_IDENTITY_INVALID');
  const material = ['content-promotion-v2', source, run, attempt, binding.lane, binding.subscope, binding.package_sha256, binding.release_policy_sha256, binding.expected_row_count, binding.executor_release_sha256].join('|');
  return createHmac('sha256', key).update(material).digest('hex');
}

export function isIqEqTopicOnlyPromotionRegistration(before, after) {
  const root = `        '${packagePath}',\n`;
  const lane = ", 'IQ-EQ-TOPIC' => 'audit_compatible'";
  if (!after.includes(root) || !after.includes(lane) || before.includes(root) || before.includes(lane)) return false;
  const without = after.replace(root, '').replace(lane, '');
  return without === before || isIqArticleOnlyPromotionRegistration(before, without);
}

export function isIqEqTopicOnlyContextFactoryChange(before, after) {
  const addition = `        // The IQ/EQ topic executor contract binds its exact implementation bytes.
        // Existing lanes retain their established signature wire format.
        if ($lane === 'W3' && $subscope === 'IQ-EQ-TOPIC') {
            $signatureMaterial .= '|'.$executorReleaseSha256;
        }
`;
  if (before.includes(addition) || !after.includes(addition)) return false;
  const without = after.replace(addition, '');
  return without === before || isIqArticleOnlyContextFactoryChange(before, without);
}
