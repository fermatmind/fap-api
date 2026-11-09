import { createHash, createHmac } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFileSync, realpathSync, lstatSync } from 'node:fs';
import { resolve } from 'node:path';
import { canonical } from './iq-public-scale-package.mjs';

export const packagePath = 'content_assets/public_content_53';
// Selection comes from the existing exact-range classifier, never file presence.
const packages = {
  'staging-article-originals': ['fermatmind.staging_article_originals.v1', ['staging-article-originals.json']],
  'support-contact': ['fermatmind.public_support_contact.v1', ['support-contact.json','support-contact-review.json']],
  'pricing-refund': ['fermatmind.public_content_pricing_refund.v1', ['pricing-refund.json','pricing-refund-review.json']],
  'traditional-article-batch': ['fermatmind.traditional_article_batch.v1', ['traditional-article-batch.json','traditional-article-batch-review.json']],
  'traditional-blog-featured-order': ['fermatmind.traditional_blog_featured_order.v1', ['traditional-blog-featured-order.json']],
  'traditional-article-career-eq': ['fermatmind.traditional_article_batch.v1', ['traditional-article-career-eq.json','traditional-article-career-eq-review.json']],
  'traditional-article-big-five': ['fermatmind.traditional_article_batch.v1', ['traditional-article-big-five.json','traditional-article-big-five-review.json']],
  'traditional-article-assessment-guides': ['fermatmind.traditional_article_batch.v1', ['traditional-article-assessment-guides.json','traditional-article-assessment-guides-review.json']],
  'traditional-article-interpretation': ['fermatmind.traditional_article_batch.v1', ['traditional-article-interpretation.json','traditional-article-interpretation-review.json']],
  'traditional-article-cross-group': ['fermatmind.traditional_article_batch.v1', ['traditional-article-cross-group.json','traditional-article-cross-group-review.json']],
  'traditional-article-reading-research': ['fermatmind.traditional_article_batch.v1', ['traditional-article-reading-research.json','traditional-article-reading-research-review.json']],
  'traditional-article-big-five-application': ['fermatmind.traditional_article_batch.v1', ['traditional-article-big-five-application.json','traditional-article-big-five-application-review.json']],
  'traditional-article-assessment-reading': ['fermatmind.traditional_article_batch.v1', ['traditional-article-assessment-reading.json','traditional-article-assessment-reading-review.json']],
  'traditional-article-enneagram-career-guides': ['fermatmind.traditional_article_batch.v1', ['traditional-article-enneagram-career-guides.json','traditional-article-enneagram-career-guides-review.json']],
  'traditional-article-admissions-and-reading': ['fermatmind.traditional_article_batch.v1', ['traditional-article-admissions-and-reading.json','traditional-article-admissions-and-reading-review.json']],
  'traditional-article-relationship-and-guides': ['fermatmind.traditional_article_batch.v1', ['traditional-article-relationship-and-guides.json','traditional-article-relationship-and-guides-review.json']],
  'traditional-article-featured': ['fermatmind.traditional_article_batch.v1', ['traditional-article-featured.json','traditional-article-featured-review.json']],
  'traditional-article-english': ['fermatmind.traditional_article.v1', ['traditional-article-english.json','traditional-article-english-review.json']],
  'traditional-article': ['fermatmind.traditional_article.v1', ['traditional-article.json','traditional-article-review.json']],
  'traditional-entrance': ['fermatmind.traditional_landing_surfaces.v1', ['traditional-entrance.json','traditional-entrance-review.json']],
  'traditional-pages': ['fermatmind.traditional_content_page.v1', ['traditional-pages.json','traditional-pages-review.json']],
  'zh-reading-links': ['fermatmind.public_content_zh_reading_links.v1', ['zh-reading-links.json','zh-reading-links-review.json']],
  'directory-card-copy': ['fermatmind.tests_directory_card_copy.v1', ['directory-card-copy.json','directory-card-copy-review.json']],
  'mbti-four-leaf-copy': ['fermatmind.mbti_english_four_leaf_copy.v1', ['mbti-four-leaf-copy.json','mbti-four-leaf-copy-review.json']],
  'mbti-native-leaf-copy': ['fermatmind.mbti_english_native_leaf_copy.v1', ['mbti-native-leaf-copy.json','mbti-native-leaf-copy-review.json']],
  'mbti-result-copy': ['fermatmind.mbti_english_result_copy.v1', ['mbti-result-copy.json','mbti-result-copy-review.json']],
  'english-career-guides': ['fermatmind.public_content_english_career_guides.v1', ['english-career-guides.json','english-career-guides-review.json']],
  'riasec-code-samples': ['fermatmind.riasec_code_samples.v1', ['riasec-code-samples.json','riasec-code-samples-review.json']],
  'career-planning': ['fermatmind.public_content_career_planning.v1', ['career-planning.json','career-planning-review.json']],
  'iar-amendment': ['fermatmind.public_content_article_amendment.v1', ['iar-amendment.json','iar-amendment-review.json']],
  'riasec-amendment': ['fermatmind.public_content_article_amendment.v1', ['riasec-amendment.json','riasec-amendment-review.json']],
  'seo-alignment': ['fermatmind.public_content_seo_alignment.v1', ['seo-alignment.json','seo-alignment-review.json']],
  'copy-amendment': ['fermatmind.public_content_copy_amendment.v1', ['copy-amendment.json','copy-amendment-review.json']],
  'guide-amendment': ['fermatmind.public_content_guide_amendment.v1', ['guide-amendment.json','guide-amendment-review.json']],
  'article-amendment': ['fermatmind.public_content_article_amendment.v1', ['article-amendment.json','article-amendment-review.json']],
  'translation-batch': ['fermatmind.public_content_translation.v1', ['translation-batch.json','translation-review.json']],
  assets: ['fermatmind.public_content_53.v1', ['assets.json','independent-review.json','enneagram_metrics.en.json','enneagram_public_copy.en.json','riasec_technical_note.en.json']],
};
export function selectPublicContentPackage(paths) {
  const selected=Object.entries(packages).filter(([, [, names]])=>names.some(name=>paths.includes(`backend/${packagePath}/${name}`))).map(([name])=>name);
  if (selected.length > 1) throw new Error('PUBLIC_CONTENT_53_MULTIPLE_PACKAGES_REQUIRE_SERIAL_RELEASE');
  if (!selected.length && paths.some(path=>path.startsWith(`backend/${packagePath}/`))) throw new Error('PUBLIC_CONTENT_53_PACKAGE_SELECTION_UNKNOWN');
  return selected[0] ?? null;
}
export function packageDigest(backendRoot, selection = process.env.PUBLIC_CONTENT_53_PACKAGE_NAME) {
  const entry=Object.hasOwn(packages,selection ?? '') ? packages[selection] : null;
  if (!entry) throw new Error('PUBLIC_CONTENT_53_PACKAGE_SELECTION_REQUIRED');
  const [schema,names]=entry;
  return sha(schema+'\n'+names.map(name=>`${name}\n${sha(readFileSync(resolve(backendRoot,packagePath,name)))}\n`).join(''));
}
export const executorPaths = [
  "app/Console/Commands/ContentPromoteExactPackage.php",
  "app/Filament/Ops/Resources/CareerGuideResource/Support/CareerGuideWorkspace.php",
  "app/Http/Controllers/API/V0_5/Cms/ArticleController.php",
  "app/Http/Controllers/API/V0_5/Cms/CareerGuideController.php",
  "app/Http/Controllers/API/V0_5/Cms/ContentPageController.php",
  "app/Http/Controllers/API/V0_5/Cms/LandingSurfaceController.php",
  "app/Http/Controllers/API/V0_5/Cms/TopicController.php",
  "app/Models/Article.php",
  "app/Models/ArticleSeoMeta.php",
  "app/Models/ArticleTranslationRevision.php",
  "app/Models/CareerGuide.php",
  "app/Models/CareerGuideRevision.php",
  "app/Models/CareerGuideSeoMeta.php",
  "app/Models/CmsTranslationRevision.php",
  "app/Models/ContentPage.php",
  "app/Models/LandingSurface.php",
  "app/Models/PageBlock.php",
  "app/Models/TopicProfile.php",
  "app/Models/TopicProfileEntry.php",
  "app/Models/TopicProfileRevision.php",
  "app/Models/TopicProfileSection.php",
  "app/Models/TopicProfileSeoMeta.php",
  "app/Services/Cms/ArticleBodyHeadingGuard.php",
  "app/Services/Cms/ArticleSeoGateRollout.php",
  "app/Services/Cms/CareerGuideSeoService.php",
  "app/Services/Cms/CareerGuideService.php",
  "app/Services/ContentPromotion/Adapters/PublicContent53PromotionAdapter.php",
  "app/Services/ContentPromotion/ExactPackagePathGuard.php",
  "app/Services/ContentPromotion/ExactPackagePromotionService.php",
  "app/Services/ContentPromotion/PromotionAdapterRegistry.php",
  "app/Services/ContentPromotion/PromotionAdapterResultFactory.php",
  "app/Services/ContentPromotion/PromotionContext.php",
  "app/Services/ContentPromotion/PromotionContextFactory.php",
  "app/Services/ContentPromotion/PromotionExecutionContext.php",
  "app/Services/ContentPromotion/PromotionReceiptStore.php",
  "app/Services/ContentPromotion/PromotionRollbackSnapshotService.php",
  "app/Services/ContentPromotion/PromotionTargetSet.php",
  "app/Services/ContentPromotion/PublicContent53FrontendRevalidator.php",
  "app/Services/ContentPromotion/PublicContent53Package.php",
  "app/Services/ContentPromotion/PublicContent53State.php",
  "app/Services/ContentPromotion/PublicContent53Writer.php",
  "app/Services/ContentPromotion/StagingArticleOriginals.php",
  "app/Services/ContentPromotion/TraditionalArticlePublication.php",
  "app/Services/ContentPromotion/TraditionalArticleSourcePreflight.php",
  "app/Services/ContentPromotion/TraditionalContentPagePublication.php",
  "app/Services/ContentPromotion/TraditionalLandingSurfacePublication.php",
  "app/Services/Cms/SiblingTranslationWorkflowService.php",
  "app/Support/CanonicalTranslationPayloadHash.php",
  "app/Services/Cms/RowBackedRevisionWorkspace.php",
  "app/Services/Cms/AbstractSiblingTranslationAdapter.php",
  "app/Services/Cms/ContentPageTranslationAdapter.php",
  "app/Services/ContentPromotion/PublicContent53MbtiResultCopy.php",
  "app/Services/Cms/MbtiEnglishVariantSectionEnrichmentService.php",
  "app/Services/Mbti/Adapters/MbtiPersonalityProfileAuthoritySourceAdapter.php",
  "app/Models/PersonalityProfile.php",
  "app/Models/PersonalityProfileVariant.php",
  "app/Models/PersonalityProfileVariantSection.php",
  "app/Models/PersonalityProfileVariantRevision.php",
  "app/Services/ContentPromotion/RiasecCodeSampleArticles.php",
  "config/content_promotion.php",
  "scripts/deploy/run_public_content_53_publish.php"
].sort();
const sha = bytes => createHash('sha256').update(bytes).digest('hex');
export function readCandidateRows(backendRoot, digest = packageDigest(backendRoot), environment = 'production') {
  const code = 'require $argv[1]."/app/Services/ContentPromotion/PromotionContextFactory.php"; require $argv[1]."/app/Services/ContentPromotion/RiasecCodeSampleArticles.php"; require $argv[1]."/app/Services/ContentPromotion/PublicContent53MbtiResultCopy.php"; require $argv[1]."/app/Services/ContentPromotion/StagingArticleOriginals.php"; require $argv[1]."/app/Services/ContentPromotion/TraditionalArticlePublication.php"; require $argv[1]."/app/Services/ContentPromotion/TraditionalContentPagePublication.php"; require $argv[1]."/app/Services/ContentPromotion/TraditionalLandingSurfacePublication.php"; require $argv[1]."/app/Services/ContentPromotion/PublicContent53Package.php"; echo json_encode((new App\\Services\\ContentPromotion\\PublicContent53Package)->read($argv[1],$argv[2],$argv[3]), JSON_THROW_ON_ERROR);';
  return JSON.parse(execFileSync('php', ['-r', code, realpathSync(backendRoot), digest, environment], { encoding: 'utf8', timeout: 30000, maxBuffer: 4000000, stdio: ['pipe', 'pipe', 'pipe'] }));
}
export function inspectPackage(backendRoot, selection = process.env.PUBLIC_CONTENT_53_PACKAGE_NAME) {
  const root = realpathSync(backendRoot);
  const packageSha256 = packageDigest(root,selection);
  const count = readCandidateRows(root,packageSha256).candidates.length;
  const material = executorPaths.map(path => {
    const file = resolve(root, path);
    const stat = lstatSync(file);
    if (!stat.isFile() || stat.nlink !== 1 || realpathSync(file) !== file) throw new Error('PUBLIC_CONTENT_53_EXECUTOR_INVALID');
    return `${path}\n${sha(readFileSync(file))}\n`;
  }).join('');
  return { schema: 'public.content53.binding.v1', package_path: packagePath, package_sha256: packageSha256,
    executor_release_sha256: sha(material), release_policy_sha256: sha(JSON.stringify(canonical(JSON.parse(readFileSync(resolve(root, 'config/content_promotion_release_policy.v2.json'), 'utf8'))))),
    lane: 'W3', subscope: 'PUBLIC-CONTENT-53', expected_row_count: count };
}
export function workflowSignature(binding, key, source, run, attempt) {
  if (!['staging','production'].includes(binding.environment) || typeof key !== 'string' || key.length < 32 || !/^[a-f0-9]{40}$/.test(source) || !/^[1-9][0-9]*$/.test(String(run)) || attempt !== 1) throw new Error('PUBLIC_CONTENT_53_WORKFLOW_IDENTITY_INVALID');
  const material = ['content-promotion-v2', source, run, attempt, binding.lane, binding.subscope, binding.package_sha256, binding.release_policy_sha256, binding.expected_row_count, binding.executor_release_sha256, binding.environment].join('|');
  return createHmac('sha256', key).update(material).digest('hex');
}

export function isPublic53OnlyPromotionRegistration(before, after) {
  const root = `        '${packagePath}',\n`;
  const environment = "    'public_content_53_environment' => env('PUBLIC_CONTENT_53_ENVIRONMENT', 'production'),\n";
  const lane = ", 'PUBLIC-CONTENT-53' => 'audit_compatible'";
  if (before.includes(root) && before.includes(lane) && !before.includes(environment)
    && after.includes(environment) && after.replace(environment, '') === before) return true;
  return !before.includes(root) && !before.includes(lane) && after.includes(root) && after.includes(lane)
    && after.replace(root, '').replace(lane, '').replace(environment, '') === before;
}
export function isPublic53OnlyContextFactoryChange(before, after) {
  const addition = `        if ($lane === 'W3' && $subscope === 'PUBLIC-CONTENT-53') {
            $signatureMaterial .= '|'.$executorReleaseSha256.'|'.config('content_promotion.public_content_53_environment', 'production');
        }
`;
  const readEnvironment = "config('content_promotion.public_content_53_environment', 'production')";
  const legacy = ["(getenv('PUBLIC_CONTENT_53_ENVIRONMENT') ?: 'production')", 'PublicContent53Package::environment()']
    .map(value => addition.replace(readEnvironment, value));
  return after.includes(addition) && ((!before.includes(addition) && after.replace(addition, '') === before)
    || legacy.some(value => before.includes(value) && after.replace(addition, '') === before.replace(value, '')));
}
