#!/usr/bin/env node

import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";

export const SCOPE_VERSION = "seo-council-a08-runtime.v2";

const EXACT_FILES = new Set([
  ".github/workflows/ci.yml",
  ".github/workflows/deploy.yml",
  "deploy.php",
  "backend/composer.json",
  "backend/composer.lock",
  "backend/bootstrap/app.php",
  "backend/bootstrap/providers.php",
  "backend/config/cache.php",
  "backend/config/database.php",
  "backend/config/ops.php",
  "backend/config/seo_agent_evidence.php",
  "backend/config/seo_council.php",
  "backend/config/seo_intel.php",
  "backend/app/Services/Ops/OpsAlertService.php",
  "backend/app/Services/Ops/PublicContentDeliveryProbeService.php",
  "backend/app/Services/SEO/SitemapCache.php",
]);

export function inRuntimeScope(path) {
  return EXACT_FILES.has(path)
    || /^\.github\/trunk\/(?:classify-paths|seo-council|seo-platform-1[12]).*\.(?:mjs|json)$/.test(path)
    || /^backend\/app\/Services\/(?:SeoCouncil|SeoAgentPolicyGateway|SeoAgentGovernance)\//.test(path)
    || /^backend\/app\/Services\/SeoAgentEvidence\//.test(path)
    || /^backend\/app\/Services\/SeoIntel\/(?:Runtime|UrlTruth)\//.test(path)
    || /^backend\/app\/Console\/Commands\/SeoCouncil[^/]+\.php$/.test(path)
    || /^backend\/app\/Providers\/SeoCouncilServiceProvider\.php$/.test(path)
    || /^backend\/database\/migrations\/seo_intel\/.*seo_council_.*\.php$/.test(path)
    || /^backend\/resources\/seo-agent\/(?:council|policy-gateway)\//.test(path)
    || /^backend\/scripts\/(?:ci|deploy|seo)\/.*(?:council|platform12|runtime).*\.(?:php|sh|mjs)$/.test(path);
}

export function fingerprint(root = process.cwd()) {
  const entries = execFileSync("git", ["ls-files", "-s", "-z"], { cwd: root, maxBuffer: 32 * 1024 * 1024 })
    .toString("utf8").split("\0").filter(Boolean).map((entry) => {
      const match = /^(\d+) ([a-f0-9]{40,64}) \d+\t(.+)$/.exec(entry);
      if (!match) throw new Error("RUNTIME_FINGERPRINT_INDEX_INVALID");
      return { mode: match[1], object: match[2], path: match[3] };
    }).filter(({ path }) => inRuntimeScope(path)).sort((left, right) => left.path.localeCompare(right.path));
  const hash = createHash("sha256");
  for (const entry of entries) hash.update(`${entry.path}\0${entry.mode}\0${entry.object}\0`);
  return { scope_version: SCOPE_VERSION, sha256: hash.digest("hex"), file_count: entries.length };
}

export function validateScopedAcceptance(receipt, sha) {
  const valid = receipt?.schema_version === "seo.platform12_a08_staging_acceptance.v2"
    && receipt?.sha === sha
    && receipt?.status === "pass"
    && receipt?.source_connected === true
    && receipt?.mission_count === 3
    && receipt?.trigger_mode === "controlled_acceptance"
    && receipt?.natural_slot_receipt === false
    && receipt?.notification_delivery_verified === true
    && receipt?.pause_resume_verified === true
    && receipt?.receipt_to_ui_verified === true
    && receipt?.model_calls === 0
    && receipt?.tool_calls === 0
    && receipt?.external_calls === 0
    && receipt?.business_writes === 0
    && Number.isInteger(receipt?.deploy_run_id) && receipt.deploy_run_id > 0
    && receipt?.deploy_run_attempt === 1;
  if (!valid) throw new Error("SCOPED_ACCEPTANCE_EVIDENCE_HOLD");
  return true;
}

export function mayCarry(manifest, candidate) {
  return manifest?.schema_version === "seo.platform12_a08_activation.v2"
    && manifest?.activation_state === "ACTIVE_READ_ONLY"
    && manifest?.activation_basis === "A08_SCOPED_READ_ONLY_ACCEPTANCE"
    && manifest?.repository === "fermatmind/fap-api"
    && manifest?.compatibility?.fingerprint?.scope_version === SCOPE_VERSION
    && manifest?.compatibility?.fingerprint?.sha256 === candidate.fingerprint.sha256
    && manifest?.compatibility?.fingerprint?.file_count === candidate.fingerprint.file_count
    && JSON.stringify(manifest?.runtime?.version_vector) === JSON.stringify(candidate.version_vector)
    && /^[a-f0-9]{40}$/.test(candidate.production_sha)
    && manifest.bound_production_sha !== candidate.production_sha;
}

function main() {
  const [command, ...args] = process.argv.slice(2);
  if (command === "fingerprint") {
    process.stdout.write(`${JSON.stringify(fingerprint(args[0] || process.cwd()))}\n`);
    return;
  }
  throw new Error("SEO_COUNCIL_A08_COMMAND_DENIED");
}

if (import.meta.url === `file://${process.argv[1]}`) main();
