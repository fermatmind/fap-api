import assert from "node:assert/strict";
import test from "node:test";

import {
  CAREER_PUBLISHER_BOUNDARY_MATRIX,
  PERSONALITY_CURRENT_BOUNDARY_MATRIX,
  classifyPaths,
} from "./classify-paths.mjs";

const has = (paths, flag) => classifyPaths(paths).flags[flag];

test("classifies docs, rules, and tests without deployment", () => {
  const result = classifyPaths(["AGENTS.md", "docs/ops/trunk.md", "backend/tests/Feature/FooTest.php"]);
  assert.deepEqual(result.categories, ["docs_rules_tests_only"]);
  assert.equal(result.deploy, false);
  assert.equal(result.tests_changed, true);
});

test("keeps repository Skills rules-only when their names contain domain keywords", () => {
  const result = classifyPaths([
    ".agents/skills/fap-api-career-canonical-builder/SKILL.md",
    ".agents/skills/fermatmind-career-editorial-qa/references/review-rubric.md",
  ]);
  assert.deepEqual(result.categories, ["docs_rules_tests_only"]);
  assert.equal(result.deploy, false);
  assert.equal(result.flags.seo_discoverability, false);
});

test("deploys SEO Council classifier changes for exact-SHA runtime closeout", () => {
  const result = classifyPaths([
    ".github/trunk/classify-paths.mjs",
    ".github/trunk/classify-paths.test.mjs",
  ]);
  assert.deepEqual(result.categories, ["infrastructure_deployment"]);
  assert.equal(result.deploy, true);
  assert.equal(result.tests_changed, true);
  assert.equal(result.operations.seo_council_orchestration, true);
});

test("keeps all SEO platform closeout evidence docs-only", () => {
  const result = classifyPaths([
    "backend/docs/seo/generated/seo-platform-01-capability-truth.v1.json",
    "backend/docs/seo/seo-platform-01-production-capability-closeout.md",
    "backend/docs/seo/generated/seo-platform-02-page-family-policy-coverage.v1.json",
    "backend/docs/seo/seo-platform-02-page-family-policy-closeout.md",
  ]);
  assert.deepEqual(result.categories, ["docs_rules_tests_only"]);
  assert.equal(result.deploy, false);
  assert.equal(result.flags.seo_discoverability, false);
});

test("classifies application code", () => assert.equal(has(["backend/app/Models/User.php"], "application_code"), true));
test("classifies content assets", () => assert.equal(has(["backend/content_packs/BIG5/v1/manifest.json"], "content_assets"), true));
test("classifies Career Current legacy and sharded assets", () => {
  assert.equal(has(["backend/content_assets/career/current/assets.jsonl"], "content_assets"), true);
  assert.equal(has(["backend/content_assets/career/current/identity/shard-00.jsonl"], "content_assets"), true);
  assert.equal(has(["backend/content_assets/career/current/careers/actors/en.json"], "content_assets"), true);
});
test("binds every Personality Current package and reader boundary to package validation", () => {
  for (const boundary of PERSONALITY_CURRENT_BOUNDARY_MATRIX) {
    const path = boundary.endsWith("/") ? `${boundary}pages/mbti/profile/intj/en.json` : boundary;
    assert.equal(classifyPaths([path]).operations.personality_current_authority_release, true, path);
  }
  assert.equal(
    classifyPaths(["backend/content_assets/personality_public/big_five_v1_seed.json"])
      .operations.personality_current_authority_release,
    false,
  );
});
test("requires the Career publisher for per-page canonical reader changes", () => {
  for (const path of [
    "backend/app/Domain/Career/Display/CareerContentV3AuthorityPackage.php",
    "backend/app/Domain/Career/Display/CareerContentV3CanonicalReader.php",
    "backend/app/Domain/Career/Display/CareerCurrentAuthorityCompatibilityReader.php",
    "backend/app/Providers/AppServiceProvider.php",
    "backend/app/Services/Career/Bundles/CareerJobDisplaySurfaceBuilder.php",
  ]) {
    assert.equal(classifyPaths([path]).operations.publisher_required, true, path);
  }
});
test("binds Career Current release and runtime projection dependencies to its operation", () => {
  const exact = classifyPaths([
    "backend/content_assets/career/career_current_authority_release.v1.json",
  ]);
  const projector = classifyPaths([
    "backend/app/Domain/Career/Compilation/CareerContentV3Projector.php",
  ]);
  const contract = classifyPaths([
    "backend/app/Domain/Career/Display/CareerContentV3Contract.php",
  ]);
  const builder = classifyPaths([
    "backend/app/Services/Career/CareerJobDisplaySurfaceBuilder.php",
  ]);
  const cacheGateway = classifyPaths([
    "backend/app/Domain/Career/Display/CareerCurrentAuthorityCacheGateway.php",
  ]);
  const responseCache = classifyPaths([
    "backend/app/Services/Career/PublicCareerAuthorityResponseCache.php",
  ]);
  const adjacent = classifyPaths([
    "backend/content_assets/career/career_current_authority_review.json",
  ]);
  assert.equal(exact.flags.content_assets, true);
  assert.equal(exact.operations.career_current_authority_release, true);
  assert.equal(projector.operations.career_current_authority_release, true);
  assert.equal(contract.operations.career_current_authority_release, true);
  assert.equal(builder.operations.career_current_authority_release, true);
  assert.equal(cacheGateway.operations.career_current_authority_release, true);
  assert.equal(responseCache.operations.career_current_authority_release, true);
  assert.equal(cacheGateway.flags.cache_runtime_projection, false);
  assert.equal(responseCache.flags.application_code, true);
  assert.equal(responseCache.flags.cache_runtime_projection, false);
  assert.equal(adjacent.operations.career_current_authority_release, false);
});

test("requires publisher parity for every centralized Career publisher boundary", () => {
  for (const boundary of CAREER_PUBLISHER_BOUNDARY_MATRIX) {
    const path = boundary.endsWith("/") ? `${boundary}identity/shard-00.jsonl` : boundary;
    const result = classifyPaths([path]);
    assert.equal(result.operations.publisher_required, true, path);
    assert.equal(
      result.operations.career_current_authority_release,
      ![".github/workflows/ci.yml", ".github/workflows/deploy.yml"].includes(path),
      path,
    );
  }
});

test("keeps 11B workflow assertions on zero-write Career parity without authorizing publication", () => {
  const result = classifyPaths([
    ".github/workflows/ci.yml",
    ".github/workflows/deploy.yml",
    "backend/tests/Feature/SeoIntel/SeoPlatform11BProductionCloseoutTest.php",
  ]);
  assert.equal(result.operations.publisher_required, true);
  assert.equal(result.operations.career_current_authority_release, false);
  assert.equal(result.operations.seo_agent_evidence_boundary, true);
});

test("routes Career publisher cache boundaries through parity instead of the generic cache suite", () => {
  for (const path of [
    "backend/app/Domain/Career/Display/CareerCurrentAuthorityCacheGateway.php",
    "backend/app/Domain/Career/Display/CareerJobDetailCanonicalCacheReader.php",
    "backend/app/Services/Career/PublicCareerAuthorityResponseCache.php",
  ]) {
    const result = classifyPaths([path]);
    assert.equal(result.operations.publisher_required, true, path);
    assert.equal(result.flags.cache_runtime_projection, false, path);
    assert.equal(result.flags.seo_discoverability, false, path);
  }
});

test("does not require publisher parity for adjacent unrelated Career paths", () => {
  for (const path of [
    "backend/app/Services/Career/CareerRecommendationCompiler.php",
    "backend/content_assets/career/career_current_authority_review.json",
    "backend/tests/Feature/Career/CareerRecommendationCompilerTest.php",
  ]) {
    assert.equal(classifyPaths([path]).operations.publisher_required, false, path);
  }
});
test("binds only the exact MBTI zh authority release manifest to its operation", () => {
  const exact = classifyPaths([
    "backend/content_assets/personality_public/mbti_zh_result_authority_release.v1.json",
  ]);
  const adjacent = classifyPaths([
    "backend/content_assets/personality_public/mbti_zh_result_authority_review.json",
  ]);
  assert.equal(exact.flags.content_assets, true);
  assert.equal(exact.operations.mbti_zh_result_authority_release, true);
  assert.equal(adjacent.operations.mbti_zh_result_authority_release, false);
});
test("binds SEO Platform 10 closeout only to its exact controlled operation config", () => {
  const exact = classifyPaths(["backend/config/seo_platform_10.php"]);
  const adjacent = classifyPaths(["backend/config/seo_platform_10_notes.php"]);
  assert.equal(exact.flags.application_code, true);
  assert.equal(exact.operations.seo_platform_10_closeout, true);
  assert.equal(adjacent.operations.seo_platform_10_closeout, false);
});
test("binds SEO Platform 11A closeout only to the canonical v3 reconciliation", () => {
  const exact = classifyPaths([
    "backend/docs/seo/generated/seo-platform-11a-inventory.v3.json",
  ]);
  const adjacent = classifyPaths([
    "backend/docs/seo/generated/seo-platform-11a-inventory.v3.notes.json",
  ]);
  assert.equal(exact.flags.docs_rules_tests_only, true);
  assert.equal(exact.operations.seo_platform_11a_closeout, true);
  assert.equal(adjacent.operations.seo_platform_11a_closeout, false);
});

test("binds the persistent SEO agent evidence boundary to every 11B layer", () => {
  for (const path of [
    "backend/app/Services/SeoAgentEvidence/Bundle/SeoEvidenceBundleFactory.php",
    "backend/resources/seo-agent/evidence/schemas/seo-evidence-bundle.v1.schema.json",
    "backend/docs/seo/generated/seo-agent-evidence-contract-manifest.v2.json",
    "backend/config/seo_agent_evidence.php",
    "backend/database/migrations/seo_intel/2026_08_29_010000_create_seo_evidence_tables.php",
    "backend/app/Services/SeoIntel/GscSearchAnalyticsRowNormalizer.php",
    "backend/app/Console/Commands/SeoEvidenceBoundaryCloseout.php",
    "backend/tests/Feature/SeoIntel/SeoPlatform11BProductionCloseoutTest.php",
  ]) {
    assert.equal(classifyPaths([path]).operations.seo_agent_evidence_boundary, true, path);
  }
  assert.equal(classifyPaths(["backend/docs/seo/seo-platform-10-closeout.md"]).operations.seo_agent_evidence_boundary, false);
});
test("binds the deny-only SEO agent Policy Gateway to every 11C layer", () => {
  for (const path of [
    "backend/app/Services/SeoAgentPolicyGateway/AdmissionPolicy.php",
    "backend/app/Console/Commands/SeoPolicyGatewayCloseout.php",
    "backend/resources/seo-agent/policy-gateway/schemas/seo.policy_decision.v1.schema.json",
    "backend/docs/seo/generated/seo-policy-gateway-contract-manifest.v1.json",
    "backend/docs/contracts/openapi.snapshot.json",
    "backend/scripts/seo/export_seo_policy_gateway_contracts.php",
    "backend/app/Filament/Ops/Support/SeoAgentCouncilUiContract.php",
    "backend/resources/views/filament/ops/components/ops-agent-council-workspace.blade.php",
    "backend/app/Http/Controllers/API/V0_5/Ops/SeoIntel/SeoIntelDashboardController.php",
    "backend/routes/api.php",
    "backend/tests/Feature/SeoIntel/SeoPlatform11CAdmissionPolicyTest.php",
  ]) {
    assert.equal(classifyPaths([path]).operations.seo_agent_policy_gateway, true, path);
  }
  assert.equal(classifyPaths(["backend/docs/seo/seo-platform-11a-inventory-preflight.md"]).operations.seo_agent_policy_gateway, false);
  assert.equal(
    classifyPaths(["backend/app/Services/SeoAgentPolicyGateway/PolicyGatewayStatusProjection.php"]).categories.includes("cache_runtime_projection"),
    false,
  );
});
test("binds deterministic SEO Council orchestration to every 11D through 11F layer", () => {
  for (const path of [
    "backend/app/Services/SeoCouncil/SeoCouncilOrchestrator.php",
    "backend/app/Services/SeoAgentEvidence/Sources/SeoPlatformDependencyEvidenceAdapter.php",
    "backend/app/Console/Commands/SeoCouncilCloseoutCommand.php",
    "backend/app/Http/Controllers/API/V0_5/Ops/SeoIntel/SeoCouncilMissionController.php",
    "backend/app/Http/Middleware/EnsureSeoCouncilMissionAuthorized.php",
    "backend/app/Providers/SeoCouncilServiceProvider.php",
    "backend/bootstrap/providers.php",
    "backend/config/seo_council.php",
    "backend/resources/seo-agent/council/schemas/seo.mission_request.v1.schema.json",
    "backend/docs/seo/generated/seo-council-contract-manifest.v1.json",
    "backend/docs/seo/generated/seo-council-contract-manifest.v2.json",
    "backend/docs/seo/generated/seo-technical-diagnosis-contract-manifest.v1.json",
    "backend/docs/seo/generated/seo-technical-diagnosis-contract-manifest.v2.json",
    "backend/docs/seo/generated/seo-measurement-contract-manifest.v1.json",
    "backend/docs/seo/generated/seo-measurement-contract-manifest.v2.json",
    "backend/docs/seo/generated/seo-measurement-contract-manifest.v3.json",
    "backend/routes/web.php",
    "backend/scripts/seo/export_seo_council_contracts.php",
    "backend/database/migrations/seo_intel/2026_08_29_030000_create_seo_council_runtime_tables.php",
    "backend/tests/Feature/SeoIntel/SeoPlatform11DOrchestratorTest.php",
    "backend/tests/Feature/SeoIntel/SeoPlatform11EDiagnosisEvaluationTest.php",
    "backend/tests/Feature/SeoIntel/SeoPlatform11FRoutingCloseoutTest.php",
    ".agents/skills/fermatmind-global-seo-geo-growth-scan/SKILL.md",
    ".github/trunk/classify-paths.mjs",
    ".github/trunk/classify-paths.test.mjs",
    ".github/trunk/seo-council-workflow-contract.test.mjs",
    ".github/trunk/seo-platform-11e-runtime-dependency-contract.test.mjs",
    ".github/trunk/seo-platform-11f-measurement-contract.test.mjs",
    ".github/workflows/ci.yml",
    ".github/workflows/deploy.yml",
    "deploy.php",
  ]) {
    const result = classifyPaths([path]);
    assert.equal(result.operations.seo_council_orchestration, true, path);
    assert.equal(result.flags.seo_discoverability, false, path);
    assert.equal(result.flags.cache_runtime_projection, false, path);
  }
  assert.equal(classifyPaths(["backend/docs/seo/seo-platform-11c-closeout.md"]).operations.seo_council_orchestration, false);
});
test("classifies migrations", () => assert.equal(has(["backend/database/migrations/2026_01_01_add_flag.php"], "backward_compatible_migration"), true));
test("keeps the Ops SEO execution migration out of discoverability", () => {
  const result = classifyPaths([
    "backend/database/migrations/seo_intel/2026_08_23_120000_expand_seo_execution_workflow.php",
  ]);
  assert.equal(result.flags.backward_compatible_migration, true);
  assert.equal(result.flags.seo_discoverability, false);
  assert.equal(result.deploy, true);
});
test("classifies payments", () => assert.equal(has(["backend/app/Services/Payments/StripeService.php"], "payment"), true));
test("classifies cache projections", () => assert.equal(has(["backend/app/Services/Cache/ActiveProjection.php"], "cache_runtime_projection"), true));
test("classifies SEO and discoverability", () => assert.equal(has(["backend/app/Console/Commands/SeoWarmSitemap.php"], "seo_discoverability"), true));
test("keeps Ops SEO dashboards in the application lane", () => {
  const result = classifyPaths([
    "backend/app/Filament/Ops/Pages/SeoOperationsPage.php",
    "backend/app/Services/Ops/SeoOperationsService.php",
    "backend/app/Services/SeoIntel/OpsDashboard/SeoDashboardApiReadService.php",
    "backend/app/Services/SeoIntel/OpsDashboard/SeoIssueWorkflowService.php",
    "backend/resources/views/filament/ops/pages/seo-operations.blade.php",
  ]);
  assert.equal(result.flags.application_code, true);
  assert.equal(result.flags.seo_discoverability, false);
  assert.equal(result.deploy, true);
});
test("keeps bounded readonly Ops GSC ingestion out of discoverability writes", () => {
  const result = classifyPaths([
    "backend/app/Http/Controllers/API/V0_5/Ops/SeoIntel/SeoIntelDashboardController.php",
    "backend/app/Console/Commands/SeoIntelGscSyncCommand.php",
    "backend/app/Services/SeoIntel/GscReadModelSyncService.php",
    "backend/app/Services/SeoIntel/GscReadonlyLiveAdapter.php",
    "backend/app/Services/SeoIntel/GscRunCloseoutSummarizer.php",
    "backend/scripts/seo/gsc_restricted_connect_proxy.mjs",
    "backend/database/migrations/seo_intel/2026_08_23_140000_expand_gsc_read_models.php",
  ]);
  assert.equal(result.flags.application_code, true);
  assert.equal(result.flags.backward_compatible_migration, true);
  assert.equal(result.flags.seo_discoverability, false);
  assert.equal(result.deploy, true);
});
test("keeps adjacent GSC discoverability behavior in the SEO lane", () => {
  assert.equal(
    has(["backend/app/Services/SeoIntel/GscIndexSubmissionService.php"], "seo_discoverability"),
    true,
  );
});
test("classifies deployment infrastructure", () => assert.equal(has([".github/workflows/ci.yml"], "infrastructure_deployment"), true));

test("11G competitive evidence changes select the controlled ingestion operation", () => {
  const result = classifyPaths([
    "backend/app/Services/SeoAgentEvidence/Competitive/CompetitiveEvidenceIngestionService.php",
  ]);
  assert.equal(result.operations.seo_competitive_evidence, true);
  assert.equal(result.flags.application_code, true);
  assert.equal(classifyPaths([
    "backend/app/Services/SeoAgentEvidence/External/ExternalContentGateway.php",
  ]).operations.seo_competitive_evidence, true);
});

test("retired EQ mirror cleanup is content-only and cannot trigger search submission", () => {
  const result = classifyPaths([
    "backend/content_packs/EQ_EMOTIONAL_INTELLIGENCE/v1/raw/report_assets/seo_geo_authority.json",
  ]);
  assert.equal(result.flags.content_assets, true);
  assert.equal(result.flags.seo_discoverability, false);
});

test("does not infer cache or SEO runtime work from retired workflow filenames", () => {
  const result = classifyPaths([
    ".github/workflows/career-cache-repair.yml",
    ".github/workflows/seo-indexnow-submit.yml",
  ]);
  assert.deepEqual(result.categories, ["infrastructure_deployment"]);
});

test("mixed scope is the validation union", () => {
  const result = classifyPaths([
    "backend/app/Http/Controllers/API/V0_3/ProfileController.php",
    "backend/database/migrations/2026_01_01_add_profile_flag.php",
    "backend/app/Services/Payments/StripeService.php",
  ]);
  assert.equal(result.mixed, true);
  assert.equal(result.flags.application_code, true);
  assert.equal(result.flags.backward_compatible_migration, true);
  assert.equal(result.flags.payment, true);
});

test("refuses an indeterminate empty diff", () => assert.throws(() => classifyPaths([]), /must not be empty/));
