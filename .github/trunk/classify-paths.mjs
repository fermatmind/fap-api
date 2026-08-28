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
  "backend/content_assets/career/career_current_authority_release.v1.json",
  "backend/content_assets/career/current/",
  "backend/app/Domain/Career/Compilation/CareerContentV3Projector.php",
  "backend/app/Domain/Career/Display/CareerContentV3Contract.php",
  "backend/app/Domain/Career/Display/CareerCurrentAuthorityPackage.php",
  "backend/app/Domain/Career/Display/CareerCurrentAuthorityPackageLoader.php",
  "backend/app/Domain/Career/Display/CareerShardedCurrentAuthorityPackage.php",
  "backend/app/Domain/Career/Display/CareerCurrentAuthorityPublisher.php",
  "backend/app/Domain/Career/Display/CareerCurrentAuthorityStateMachine.php",
  "backend/app/Domain/Career/Display/CareerCurrentAuthorityCacheGateway.php",
  "backend/app/Domain/Career/Display/CareerJobDetailCanonicalCacheReader.php",
  "backend/app/Services/Career/CareerJobDisplaySurfaceBuilder.php",
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

export function classifyPaths(inputPaths) {
  const paths = [...new Set(inputPaths.map(normalize).filter(Boolean))].sort();
  if (paths.length === 0) throw new Error("changed path set must not be empty");

  const result = Object.fromEntries(CATEGORIES.map((category) => [category, false]));
  const reasons = Object.fromEntries(CATEGORIES.map((category) => [category, []]));
  const publisherRequired = paths.some(isCareerPublisherBoundary);
  const operations = {
    publisher_required: publisherRequired,
    career_current_authority_release: publisherRequired,
    mbti_zh_result_authority_release: paths.includes(
      "backend/content_assets/personality_public/mbti_zh_result_authority_release.v1.json",
    ),
    seo_platform_10_closeout: paths.includes("backend/config/seo_platform_10.php"),
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
    const executableRulePath = path === ".github/trunk/classify-paths.mjs";
    testsChanged ||= testPath;
    const payment = matches(path, [
      /(^|\/)(?:Commerce|Payments?|Billing|Entitlement)(\/|\.)/i,
      /(^|\/)(?:payments?|commerce|billing|entitlements?)(?:\.php|\.json|\/)/i,
    ]);
    const careerCurrentManagedCache = [
      "backend/app/Domain/Career/Display/CareerCurrentAuthorityCacheGateway.php",
      "backend/app/Services/Career/PublicCareerAuthorityResponseCache.php",
    ].includes(path);
    const cache = !careerCurrentManagedCache && matches(path, [
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
    const seo = !retiredEqMirror && !opsUi && !opsExecutionMigration && !opsReadonlyGsc && matches(path, [
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
    if (path.startsWith(".agents/") || executableRulePath || docsOnly) {
      // Repository Skills are instructions and static helpers. Domain words in
      // their names or prose must not promote a rules-only change to runtime.
      // The classifier itself is an executable delivery rule whose tests run
      // unconditionally; changing it does not change deployed application bits.
      // Documentation paths remain evidence even when their filenames contain
      // SEO, content, authority, cache, or other runtime-domain keywords.
      selected.push("docs_rules_tests_only");
    } else if (infrastructure) {
      // Control-plane filenames often contain domain words such as cache or SEO.
      // They remain infrastructure changes; domain checks are selected only by
      // actual runtime/content paths in the same push.
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
