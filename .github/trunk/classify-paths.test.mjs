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

test("classifies application code", () => assert.equal(has(["backend/app/Models/User.php"], "application_code"), true));
test("classifies content assets", () => assert.equal(has(["backend/content_packs/BIG5/v1/manifest.json"], "content_assets"), true));
test("classifies Career Current assets", () => assert.equal(has(["backend/content_assets/career/current/assets.jsonl"], "content_assets"), true));
test("classifies migrations", () => assert.equal(has(["backend/database/migrations/2026_01_01_add_flag.php"], "backward_compatible_migration"), true));
test("classifies payments", () => assert.equal(has(["backend/app/Services/Payments/StripeService.php"], "payment"), true));
test("classifies cache projections", () => assert.equal(has(["backend/app/Services/Cache/ActiveProjection.php"], "cache_runtime_projection"), true));
test("classifies SEO and discoverability", () => assert.equal(has(["backend/app/Console/Commands/SeoWarmSitemap.php"], "seo_discoverability"), true));
test("classifies deployment infrastructure", () => assert.equal(has([".github/workflows/ci.yml"], "infrastructure_deployment"), true));

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
