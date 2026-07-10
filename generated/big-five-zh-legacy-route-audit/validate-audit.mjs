import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";

const expected = [
  ["high-openness", "openness-high"],
  ["low-openness", "openness-low"],
  ["high-conscientiousness", "conscientiousness-high"],
  ["low-conscientiousness", "conscientiousness-low"],
  ["high-extraversion", "extraversion-high"],
  ["low-extraversion", "extraversion-low"],
  ["high-agreeableness", "agreeableness-high"],
  ["low-agreeableness", "agreeableness-low"],
  ["high-neuroticism", "neuroticism-high"],
  ["emotional-stability", "neuroticism-low"],
];

const root = new URL("./", import.meta.url);
const audit = JSON.parse(await readFile(new URL("legacy_route_audit.json", root), "utf8"));
const markdown = await readFile(new URL("legacy_route_audit.md", root), "utf8");
const legacySeed = JSON.parse(
  await readFile(new URL("../../backend/content_assets/personality_public/big_five_v1_seed.json", root), "utf8"),
);
const v2DryRun = JSON.parse(
  await readFile(new URL("../big-five-cms-import-dryrun/dryrun_report.json", root), "utf8"),
);

assert.equal(audit.pr_id, "BIG5-ZH-LEGACY-ROUTE-AUDIT-01");
assert.equal(audit.status, "pass");
assert.equal(audit.scope.locale, "zh-CN");
assert.equal(audit.scope.legacy_route_count, 10);
assert.equal(audit.scope.runtime_changes_in_this_pr, false);
assert.equal(audit.scope.english_routes_in_scope, false);
assert.equal(audit.mappings.length, expected.length);
assert.deepEqual(
  audit.mappings.map(({ legacy_slug, v2_slug }) => [legacy_slug, v2_slug]),
  expected,
);
assert.equal(new Set(audit.mappings.map(({ legacy_slug }) => legacy_slug)).size, 10);
assert.equal(new Set(audit.mappings.map(({ v2_slug }) => v2_slug)).size, 10);

const legacySeedKeys = new Set(
  legacySeed.assets
    .filter(({ locale, entity_type }) => locale === "zh-CN" && entity_type === "polarity")
    .map(({ entity_key }) => entity_key),
);
const v2EvidenceKeys = new Set(
  v2DryRun.rows
    .filter(({ locale, identity }) => locale === "zh-CN" && identity?.entity_type === "polarity")
    .map(({ identity }) => identity.entity_key),
);

for (const mapping of audit.mappings) {
  const base = "https://fermatmind.com";
  assert.equal(mapping.legacy_path, `/zh/personality/big-five/${mapping.legacy_slug}`);
  assert.equal(mapping.v2_path, `/zh/personality/big-five/${mapping.v2_slug}`);
  assert.equal(mapping.legacy_live.http_status, 200);
  assert.match(mapping.legacy_live.robots.toLowerCase(), /noindex/);
  assert.equal(mapping.legacy_live.canonical, `${base}${mapping.legacy_path}`);
  assert.equal(mapping.v2_live.http_status, 200);
  assert.match(mapping.v2_live.robots.toLowerCase(), /index/);
  assert.doesNotMatch(mapping.v2_live.robots.toLowerCase(), /noindex/);
  assert.equal(mapping.v2_live.canonical, `${base}${mapping.v2_path}`);
  assert.equal(mapping.recommendation, "301 Legacy to V2");
  assert.ok(legacySeedKeys.has(mapping.legacy_slug), `Legacy seed identity missing: ${mapping.legacy_slug}`);
  assert.ok(v2EvidenceKeys.has(mapping.v2_slug), `V2 package identity missing: ${mapping.v2_slug}`);
  assert.ok(markdown.includes(`| \`${mapping.legacy_slug}\` | \`${mapping.v2_slug}\` |`));
}

assert.equal(audit.decision.recommended_status, 301);
assert.equal(audit.decision.locale_boundary, "zh-CN only");
assert.match(markdown, /evidence-only/);
assert.match(markdown, /leave English Legacy routes unchanged/);

console.log("legacy route audit contract: PASS (10 exact zh-CN mappings)");
