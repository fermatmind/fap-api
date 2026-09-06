#!/usr/bin/env node

import { createHash } from "node:crypto";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";

export const SCOPE_VERSION = "seo-council-a08-runtime.v1";
export const REQUIRED_DOMAINS = [
  "authority_contract", "full_phpunit", "dependency_audit", "workflow_contracts", "security_scan",
];

export function inRuntimeScope(path) {
  return path === "deploy.php"
    || path === "backend/composer.lock"
    || /^(?:backend\/(?:app|bootstrap|config|routes|database\/migrations|resources\/seo-agent)\/)/.test(path)
    || /^backend\/resources\/(?:views|lang)\//.test(path)
    || /^backend\/(?:content_assets|content_packs)\//.test(path)
    || /^content_packages\//.test(path)
    || /^backend\/scripts\/(?:ci|deploy|seo)\//.test(path)
    || /^\.github\/trunk\//.test(path)
    || /^\.github\/workflows\/(?:ci|deploy|nightly)\.yml$/.test(path);
}

export function fingerprint(root = process.cwd()) {
  const entries = execFileSync("git", ["ls-files", "-s", "-z"], { cwd: root, maxBuffer: 32 * 1024 * 1024 })
    .toString("utf8").split("\0").filter(Boolean).map((entry) => {
      const match = /^(\d+) ([a-f0-9]{40,64}) \d+\t(.+)$/.exec(entry);
      if (!match) throw new Error("RUNTIME_FINGERPRINT_INDEX_INVALID");
      return { mode: match[1], object: match[2], path: match[3] };
    }).filter(({ path }) => inRuntimeScope(path)).sort((left, right) => left.path.localeCompare(right.path));
  const hash = createHash("sha256");
  for (const entry of entries) {
    hash.update(`${entry.path}\0${entry.mode}\0${entry.object}\0`);
  }
  return { scope_version: SCOPE_VERSION, sha256: hash.digest("hex"), file_count: entries.length };
}

export function validateNightly(receipt, metadata) {
  const valid = metadata.repository === "fermatmind/fap-api"
    && metadata.workflow_name === "Nightly"
    && metadata.workflow_path === ".github/workflows/nightly.yml"
    && metadata.head_branch === "main"
    && metadata.event === "schedule"
    && metadata.run_attempt === 1
    && Number.isInteger(metadata.run_id) && metadata.run_id > 0
    && /^[a-f0-9]{40}$/.test(metadata.sha)
    && /^sha256:[a-f0-9]{64}$/.test(metadata.artifact_digest)
    && receipt.schema_version === "nightly-failure-domain-summary.v2"
    && receipt.workflow_sha === metadata.sha
    && receipt.check_scope === "weekly_full_checks"
    && receipt.status === "pass"
    && REQUIRED_DOMAINS.every((domain) => receipt.domains?.[domain]?.required === true
      && receipt.domains?.[domain]?.result === "success");
  if (!valid) throw new Error("FULL_NIGHTLY_EVIDENCE_HOLD");
  return true;
}

export function mayCarry(manifest, candidate) {
  return manifest?.schema_version === "seo.platform12_a08_activation.v1"
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
  if (command === "verify-nightly") {
    const receipt = JSON.parse(readFileSync(args[0], "utf8"));
    const metadata = JSON.parse(readFileSync(args[1], "utf8"));
    validateNightly(receipt, metadata);
    process.stdout.write("FULL_NIGHTLY_EVIDENCE_READY\n");
    return;
  }
  throw new Error("SEO_COUNCIL_A08_COMMAND_DENIED");
}

if (import.meta.url === `file://${process.argv[1]}`) main();
