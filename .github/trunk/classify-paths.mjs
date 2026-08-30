#!/usr/bin/env node

import { readFileSync } from "node:fs";

export const CATEGORIES = [
  "docs_rules_tests_only",
  "application_code",
  "content_assets",
  "backward_compatible_migration",
  "payment",
  "cache_runtime_projection",
  "seo_discoverability",
  "infrastructure_deployment",
];

const normalize = (path) => path.replace(/^\.\//, "").replaceAll("\\", "/");
const matches = (path, expressions) => expressions.some((expression) => expression.test(path));

export const CAREER_PUBLISHER_BOUNDARY_MATRIX = [
  ".github/workflows/ci.yml",
  ".github/workflows/deploy.yml",
  "backend/content_assets/career/career_current_authority_release.v1.json",
  "backend/content_assets/career/current/",
  "backend/app/Domain/Career/Compilation/CareerContentV3Compiler.php",
  "backend/app/Domain/Career/Compilation/CareerContentV3Projector.php",
  "backend/app/Domain/Career/Display/CareerContentV3AuthorityPackage.php",
  "backend/app/Domain/Career/Display/CareerContentV3CanonicalReader.php",
  "backend/app/Domain/Career/Display/CareerContentV3Contract.php",
  "backend/app/Domain/Career/Display/CareerCurrentAuthorityPackage.php",
  "backend/app/Domain/Career/Display/CareerCurrentAuthorityPackageLoader.php",
  "backend/app/Domain/Career/Display/CareerCurrentAuthorityCompatibilityReader.php",
  "backend/app/Domain/Career/Display/CareerShardedCurrentAuthorityPackage.php",
  "backend/app/Domain/Career/Display/CareerCurrentAuthorityPublisher.php",
  "backend/app/Domain/Career/Display/CareerCurrentAuthorityStateMachine.php",
  "backend/app/Domain/Career/Display/CareerCurrentAuthorityCacheGateway.php",
  "backend/app/Domain/Career/Display/CareerJobDetailCanonicalCacheReader.php",
  "backend/app/Providers/AppServiceProvider.php",
  "backend/app/Services/Career/CareerJobDisplaySurfaceBuilder.php",
  "backend/app/Services/Career/Bundles/CareerJobDisplaySurfaceBuilder.php",
  "backend/app/Services/Career/PublicCareerAuthorityResponseCache.php",
  "backend/config/career_current_authority_parity.php",
  "backend/scripts/ci/career_current_authority_parity.php",
  "backend/scripts/ci/verify_career_current_authority_parity.sh",
  "backend/scripts/deploy/run_career_current_authority_publisher.sh",
  "backend/scripts/operations/career_current_authority_publish.php",
];

const isCareerPublisherBoundary = (path) => CAREER_PUBLISHER_BOUNDARY_MATRIX.some(
  (boundary) => boundary.endsWith("/") ? path.startsWith(boundary) : path === boundary,
);

const isCareerAuthorityReleaseBoundary = (path) => isCareerPublisherBoundary(path)
  && path !== ".github/workflows/ci.yml"
  && path !== ".github/workflows/deploy.yml";

export const PERSONALITY_CURRENT_BOUNDARY_MATRIX = [
  "backend/content_assets/personality_public/current/",
  "backend/app/Domain/Personality/Current/",
  "backend/app/Http/Controllers/API/V0_5/Cms/PersonalityController.php",
  "backend/app/Http/Controllers/API/V0_5/Cms/PersonalityPublicContentAssetController.php",
  "backend/app/Console/Commands/PersonalityCurrentManifest.php",
  "backend/scripts/personality/export_current_baseline.mjs",
];

const isPersonalityCurrentBoundary = (path) => PERSONALITY_CURRENT_BOUNDARY_MATRIX.some(
  (boundary) => boundary.endsWith("/") ? path.startsWith(boundary) : path === boundary,
);

const SEO_COUNCIL_CONTROL_PLANE_PATHS = new Set([
  ".github/trunk/classify-paths.mjs",
  ".github/trunk/classify-paths.test.mjs",
  ".github/trunk/seo-council-workflow-contract.test.mjs",
  ".github/trunk/seo-platform-11e-runtime-dependency-contract.test.mjs",
  ".github/trunk/seo-platform-11f-measurement-contract.test.mjs",
  ".github/workflows/ci.yml",
  ".github/workflows/deploy.yml",
  "deploy.php",
]);

const isSeoCouncilOrchestrationBoundary = (path) =>
  /^backend\/(?:app\/Services\/SeoCouncil\/|app\/Console\/Commands\/SeoCouncil[^/]+\.php$|app\/Http\/Controllers\/API\/V0_5\/Ops\/SeoIntel\/SeoCouncilMissionController\.php$|app\/Http\/Middleware\/EnsureSeoCouncilMissionAuthorized\.php$|app\/Http\/Middleware\/OpsAccessControl\.php$|app\/Filament\/Ops\/Support\/SeoAgentCouncilUiContract\.php$|app\/Providers\/SeoCouncilServiceProvider\.php$|bootstrap\/(?:app|providers)\.php$|config\/seo_council\.php$|resources\/seo-agent\/council\/|resources\/views\/filament\/ops\/components\/ops-agent-council-workspace\.blade\.php$|lang\/(?:en|zh_CN)\/ops\.php$|docs\/(?:seo\/generated\/(?:seo-council-contract-manifest\.v[12]|seo-technical-diagnosis-contract-manifest\.v[12]|seo-measurement-contract-manifest\.v[12])\.json$|contracts\/openapi\.snapshot\.json$)|scripts\/seo\/(?:export_seo_council_contracts|submit_seo_council_mission)\.php$|database\/migrations\/seo_intel\/2026_08_29_030000_create_seo_council_runtime_tables\.php$|tests\/Feature\/(?:SeoIntel\/SeoPlatform11[DEF]|Ops\/SeoUxImpl06AgentCouncilTest\.php$)|routes\/(?:api|web)\.php$)/.test(path)
  || path === "backend/app/Services/SeoAgentEvidence/Sources/SeoPlatformDependencyEvidenceAdapter.php"
  || path === ".agents/skills/fermatmind-global-seo-geo-growth-scan/SKILL.md"
  || SEO_COUNCIL_CONTROL_PLANE_PATHS.has(path);

export function classifyPaths(inputPaths) {
  const paths = [...new Set(inputPaths.map(normalize).filter(Boolean))].sort();
  if (paths.length === 0) throw new Error("changed path set must not be empty");

  const result = Object.fromEntries(CATEGORIES.map((category) => [category, false]));
  const reasons = Object.fromEntries(CATEGORIES.map((category) => [category, []]));
  const publisherRequired = paths.some(isCareerPublisherBoundary);
  const operations = {
    publisher_required: publisherRequired,
    career_current_authority_release: paths.some(isCareerAuthorityReleaseBoundary),
    personality_current_authority_release: paths.some(isPersonalityCurrentBoundary),
    mbti_zh_result_authority_release: paths.includes(
      "backend/content_assets/personality_public/mbti_zh_result_authority_release.v1.json",
    ),
    seo_platform_10_closeout: paths.includes("backend/config/seo_platform_10.php"),
    seo_platform_11a_closeout: paths.includes(
      "backend/docs/seo/generated/seo-platform-11a-inventory.v3.json",
    ),
    seo_agent_evidence_boundary: paths.some((path) =>
      /^backend\/(?:app\/Services\/SeoAgentEvidence\/|app\/Console\/Commands\/SeoEvidenceBoundaryCloseout\.php$|config\/seo_agent_evidence\.php$|resources\/seo-agent\/evidence\/|docs\/seo\/generated\/seo-agent-evidence-contract-manifest\.v[12]\.json$|scripts\/seo\/export_seo_agent_evidence_contracts\.php$|database\/migrations\/seo_intel\/2026_08_29_0[12]0000_|tests\/Feature\/SeoIntel\/SeoPlatform11B)/.test(path)
      || path === "backend/app/Services/SeoIntel/GscSearchAnalyticsRowNormalizer.php"
      || path === "backend/app/Services/SeoIntel/GscReadModelSyncService.php",
    ),
    seo_agent_policy_gateway: paths.some((path) =>
      /^backend\/(?:app\/Services\/SeoAgentPolicyGateway\/|app\/Console\/Commands\/SeoPolicyGatewayCloseout\.php$|resources\/seo-agent\/policy-gateway\/|docs\/(?:seo\/generated\/seo-policy-gateway-contract-manifest\.v1\.json$|contracts\/openapi\.snapshot\.json$)|scripts\/seo\/export_seo_policy_gateway_contracts\.php$|tests\/Feature\/SeoIntel\/SeoPlatform11C|tests\/Feature\/Ops\/SeoUxImpl06AgentCouncilTest\.php$|app\/Filament\/Ops\/Support\/SeoAgentCouncilUiContract\.php$|resources\/views\/filament\/ops\/components\/ops-agent-council-workspace\.blade\.php$|app\/Http\/Controllers\/API\/V0_5\/Ops\/SeoIntel\/SeoIntelDashboardController\.php$|routes\/api\.php$)/.test(path)
    ),
    seo_council_orchestration: paths.some(isSeoCouncilOrchestrationBoundary),
  };
  let testsChanged = false;

  for (const path of paths) {
    const docsOnly = matches(path, [
      /(^|\/)AGENTS\.md$/,
      /(^|\/)README(?:\.[^/]+)?$/,
      /^(?:docs|00-plan|01-api-design|02-db-design|03-env-config|04-analytics)\//,
      /(^|\/)docs\//,
      /^\.agents\//,
      /(^|\/)(?:tests?|__tests__)\//,
      /(?:Test\.php|\.test\.[cm]?[jt]sx?)$/,
    ]);
    const testPath = matches(path, [
      /(^|\/)(?:tests?|__tests__)\//,
      /(?:Test\.php|\.test\.[cm]?[jt]sx?)$/,
    ]);
    const seoCouncilControlPlane = SEO_COUNCIL_CONTROL_PLANE_PATHS.has(path);
    testsChanged ||= testPath;
    const payment = matches(path, [
      /(^|\/)(?:Commerce|Payments?|Billing|Entitlement)(\/|\.)/i,
      /(^|\/)(?:payments?|commerce|billing|entitlements?)(?:\.php|\.json|\/)/i,
    ]);
    const careerCurrentManagedCache = isCareerPublisherBoundary(path);
    const seoAgentPolicyGatewayBoundary = path.startsWith("backend/app/Services/SeoAgentPolicyGateway/");
    const seoCouncilOrchestrationBoundary = isSeoCouncilOrchestrationBoundary(path);
    const cache = !careerCurrentManagedCache && !seoAgentPolicyGatewayBoundary && !seoCouncilOrchestrationBoundary && matches(path, [
      /(^|\/)(?:Cache|Redis|Projection)(\/|\.)/,
      /(?:cache|redis|projection|materiali[sz]ed|active_pointer|lkg)/i,
    ]);
    const retiredEqMirror = path.startsWith("backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/");
    const opsUi = matches(path, [
      /^backend\/app\/Filament\/Ops\//,
      /^backend\/app\/Services\/Ops\//,
      /^backend\/app\/Services\/SeoIntel\/OpsDashboard\//,
      /^backend\/resources\/(?:css|views)\/filament\/ops\//,
    ]);
    const opsExecutionMigration = /^backend\/database\/migrations\/seo_intel\/\d{4}_\d{2}_\d{2}_\d+_expand_seo_execution_workflow\.php$/.test(path);
    const opsReadonlyGsc = matches(path, [
      /^backend\/app\/Http\/Controllers\/API\/V0_5\/Ops\/SeoIntel\/SeoIntelDashboardController\.php$/,
      /^backend\/app\/Console\/Commands\/SeoIntelGscSyncCommand\.php$/,
      /^backend\/app\/Services\/SeoIntel\/Gsc(?:ReadModelSyncService|ReadonlyLiveAdapter)\.php$/,
      /^backend\/app\/Services\/SeoIntel\/GscRunCloseoutSummarizer\.php$/,
      /^backend\/scripts\/seo\/gsc_restricted_connect_proxy\.mjs$/,
      /^backend\/database\/migrations\/seo_intel\/\d{4}_\d{2}_\d{2}_\d+_expand_gsc_read_models\.php$/,
    ]);
    const seo = !careerCurrentManagedCache && !retiredEqMirror && !opsUi && !opsExecutionMigration && !opsReadonlyGsc && !seoCouncilOrchestrationBoundary && matches(path, [
      /(?:^|\/)(?:seo|search|discoverability|sitemap|robots|llms)(?:\/|\.|-|_)/i,
      /(?:canonical|hreflang|indexnow|indexability|gsc)/i,
      /(?:Seo|Search|Discoverability|Sitemap|Robots|Llms)/,
    ]);
    const content = matches(path, [
      /^(?:content_packages|content_baselines)\//,
      /^backend\/(?:content_assets|content_packs|content_packages)\//,
      /(?:content|authority|editorial|import-packages?)\//i,
    ]);
    const migration = /^backend\/database\/migrations\/.+\.php$/.test(path);
    const infrastructure = matches(path, [
      /^\.github\//,
      /^(?:deploy|infrastructure|infra)\//,
      /^(?:deploy\.php|docker-compose[^/]*|Dockerfile[^/]*)$/,
      /(?:supervisor|nginx|openresty|deployer|deployment)/i,
    ]);
    const application = matches(path, [
      /^backend\/(?:app|routes|config|resources|database\/(?:factories|seeders|seed_data))\//,
      /^backend\/(?:artisan|composer\.(?:json|lock))$/,
    ]);

    const selected = [];
    if (path.startsWith(".agents/") || (docsOnly && !seoCouncilControlPlane)) {
      // Repository Skills are instructions and static helpers. Domain words in
      // their names or prose must not promote a rules-only change to runtime.
      // Documentation paths remain evidence even when their filenames contain
      // SEO, content, authority, cache, or other runtime-domain keywords.
      selected.push("docs_rules_tests_only");
    } else if (infrastructure || seoCouncilControlPlane) {
      // Control-plane filenames often contain domain words such as cache or SEO.
      // They remain infrastructure changes; domain checks are selected only by
      // actual runtime/content paths in the same push. SEO Council control-plane
      // changes deploy so their exact SHA receives runtime closeout evidence.
      selected.push("infrastructure_deployment");
    } else {
      if (payment) selected.push("payment");
      if (cache) selected.push("cache_runtime_projection");
      if (seo) selected.push("seo_discoverability");
      if (content) selected.push("content_assets");
      if (migration) selected.push("backward_compatible_migration");
      if (application && !migration) selected.push("application_code");
      if (selected.length === 0 && docsOnly) selected.push("docs_rules_tests_only");
    }
    if (selected.length === 0) selected.push("application_code");

    for (const category of selected) {
      result[category] = true;
      reasons[category].push(path);
    }
  }

  const deploymentCategories = CATEGORIES.filter(
    (category) => category !== "docs_rules_tests_only" && result[category],
  );
  const categories = CATEGORIES.filter((category) => result[category]);
  return {
    schema_version: "fermatmind.trunk-path-classification.v1",
    paths,
    categories,
    mixed: categories.length > 1,
    deploy: deploymentCategories.length > 0,
    tests_changed: testsChanged,
    flags: result,
    operations,
    reasons,
  };
}

function cli() {
  const input = process.argv[2] ? readFileSync(process.argv[2], "utf8") : readFileSync(0, "utf8");
  const result = classifyPaths(input.split(/\0|\r?\n/));
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
}

if (import.meta.url === `file://${process.argv[1]}`) cli();
