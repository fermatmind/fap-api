import { readFile } from "node:fs/promises";

const dir = "generated/big-five-authority-v2/big5-authority-v2-public-contract-03";
const fixtures = JSON.parse(await readFile(`${dir}/contract-fixtures.json`, "utf8"));
const schema = JSON.parse(await readFile(
  "backend/docs/seo/personality/big-five-authority-v2/big5-authority-v2-public-contract-03/public-contract-v2.schema.json",
  "utf8",
));
const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

assert(schema.$schema === "https://json-schema.org/draft/2020-12/schema", "schema draft");
assert(schema.properties.contract_version.const === "personality_public_asset.v2", "v2 const");
assert(schema.properties.compatible_v1_contract_version.const === "personality_public_asset.v1", "v1 compatibility const");

for (const [name, fixture] of Object.entries({
  valid_v2: fixtures.valid_v2,
  legacy_fail_closed: fixtures.legacy_fail_closed,
})) {
  assert(fixture.contract_version === "personality_public_asset.v2", `${name} version`);
  assert(fixture.compatible_v1_contract_version === "personality_public_asset.v1", `${name} compatibility`);
  assert(fixture.framework === "big_five", `${name} framework`);
  assert(Array.isArray(fixture.visible_evidence.sources), `${name} sources`);
  assert(Array.isArray(fixture.visible_evidence.claim_mapping), `${name} mapping`);
  assert(Array.isArray(fixture.visible_evidence.limitations), `${name} limitations`);
  assert(typeof fixture.visible_evidence.eligible === "boolean", `${name} visible gate`);
  assert(typeof fixture.schema_eligible === "boolean", `${name} schema gate`);
}

const valid = fixtures.valid_v2;
const sourceIds = new Set(valid.visible_evidence.sources.map((source) => source.id));
assert(sourceIds.size === valid.visible_evidence.sources.length, "unique source ids");
for (const source of valid.visible_evidence.sources) {
  assert(source.title && source.author_or_organization && source.year, `source identity ${source.id}`);
  assert(source.public_url === null || source.public_url.startsWith("https://"), `source URL ${source.id}`);
  assert(Array.isArray(source.claim_ids), `source claim ids ${source.id}`);
}
for (const mapping of valid.visible_evidence.claim_mapping) {
  assert(mapping.source_ids.length > 0, `mapping source ids ${mapping.claim_id}`);
  assert(mapping.source_ids.every((sourceId) => sourceIds.has(sourceId)), `mapping resolution ${mapping.claim_id}`);
}
assert(valid.visible_evidence.eligible === true, "explicit visible evidence gate");
assert(valid.schema_eligible === false, "schema remains disabled in fixture");
assert(valid.editorial_authority.author === null, "author is not fabricated");
assert(valid.editorial_authority.reviewer === null, "reviewer is not fabricated");
assert(valid.media_authority.hero.url.startsWith("https://assets.fermatmind.com/"), "media authority host");
assert(valid.media_authority.hero.alt.length > 0, "media alt");

const legacy = fixtures.legacy_fail_closed;
assert(legacy.visible_evidence.sources.length === 0, "legacy sources empty");
assert(legacy.visible_evidence.eligible === false, "legacy evidence fail closed");
assert(legacy.editorial_authority.author === null, "legacy author null");
assert(legacy.editorial_authority.reviewer === null, "legacy reviewer null");
assert(legacy.schema_eligible === false, "legacy schema fail closed");

assert(Object.values(fixtures.forbidden_behaviors).every((value) => value === false), "forbidden behavior flags");

console.log(JSON.stringify({
  artifact: fixtures.artifact,
  outcome: "pass",
  source_fields: [
    "title",
    "author_or_organization",
    "year",
    "source_type",
    "doi",
    "public_url",
    "accessed_at",
    "claim_ids",
    "limitation",
  ],
  v1_compatible: true,
  legacy_fail_closed: true,
  production_actions: false,
}, null, 2));
