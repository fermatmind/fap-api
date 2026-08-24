import assert from "node:assert/strict";
import test from "node:test";

import { classifyPaths } from "./classify-paths.mjs";

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

test("classifies the executable path rule with its tests without application deployment", () => {
  const result = classifyPaths([
    ".github/trunk/classify-paths.mjs",
    ".github/trunk/classify-paths.test.mjs",
  ]);
  assert.deepEqual(result.categories, ["docs_rules_tests_only"]);
  assert.equal(result.deploy, false);
  assert.equal(result.tests_changed, true);
});

test("keeps SEO platform production closeout evidence docs-only", () => {
  const result = classifyPaths([
    "backend/docs/seo/generated/seo-platform-01-capability-truth.v1.json",
    "backend/docs/seo/seo-platform-01-production-capability-closeout.md",
  ]);
  assert.deepEqual(result.categories, ["docs_rules_tests_only"]);
  assert.equal(result.deploy, false);
  assert.equal(result.flags.seo_discoverability, false);
});

test("classifies application code", () => assert.equal(has(["backend/app/Models/User.php"], "application_code"), true));
test("classifies content assets", () => assert.equal(has(["backend/content_packs/BIG5/v1/manifest.json"], "content_assets"), true));
test("classifies Career Current assets", () => assert.equal(has(["backend/content_assets/career/current/assets.jsonl"], "content_assets"), true));
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
