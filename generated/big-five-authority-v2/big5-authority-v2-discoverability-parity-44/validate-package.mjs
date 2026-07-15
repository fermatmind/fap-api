import { createHash } from "node:crypto";
import { readFile } from "node:fs/promises";

const base = new URL("./", import.meta.url);
const fixtureUrl = new URL("discoverability-parity-findings.json", base);
const shaUrl = new URL("discoverability-parity-findings.sha256", base);
const raw = await readFile(fixtureUrl);
const fixture = JSON.parse(raw.toString("utf8"));
const expectedSha = (await readFile(shaUrl, "utf8")).trim();
const actualSha = createHash("sha256").update(raw).digest("hex");

const fail = (message) => {
  throw new Error(`discoverability parity fixture invalid: ${message}`);
};

if (fixture.schema_version !== "big5-discoverability-parity-findings.v1") fail("schema_version");
if (fixture.source?.artifact_sha256 !== "60ec72b708aa5876dbee90ec12fec6dade6387414ce845f2c5ef4e4795b4ac65") fail("source sha");
if (actualSha !== expectedSha) fail("fixture sha mismatch");
if (!Array.isArray(fixture.findings) || fixture.findings.length !== 6) fail("finding count");
if (new Set(fixture.findings.map((row) => row.finding_id)).size !== 6) fail("finding ids");
if (new Set(fixture.findings.map((row) => row.asset_id)).size !== 4) fail("asset count");

const hreflang = fixture.findings.filter((row) => row.surface === "hreflang");
const llms = fixture.findings.filter((row) => row.surface === "llms.txt");
if (hreflang.length !== 3 || llms.length !== 3) fail("surface counts");
if (hreflang.some((row) => row.expected_policy !== "no_hreflang" || Object.keys(row.expected_alternates ?? {}).length !== 0)) fail("hreflang policy");
if (llms.some((row) => row.expected_membership !== true || row.expected_policy !== "backend_published_indexable_public_safe_and_explicit_llms_flag")) fail("llms policy");
if (fixture.findings.some((row) => row.observed_status !== "FAIL" || !String(row.route).startsWith("/zh/articles/"))) fail("finding evidence");
if (fixture.safety?.read_only_projection !== true || fixture.safety?.sitemap_behavior_changed !== false || fixture.safety?.search_urls_submitted !== false || fixture.safety?.draft_discoverability_expanded !== false || fixture.safety?.production_mutation_executed !== false) fail("safety boundary");

console.log(`discoverability-parity package ok findings=${fixture.findings.length} sha256=${actualSha}`);
