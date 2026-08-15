#!/usr/bin/env node

import { readdirSync, readFileSync } from "node:fs";
import { resolve } from "node:path";

export const REQUIRED_WORKFLOWS = ["ci.yml", "deploy.yml", "nightly.yml", "recovery.yml"];

const containsDispatch = (source) => /(^|\n)\s*workflow_dispatch\s*:/m.test(source);

export function validateWorkflowSet(root = process.cwd(), mode = "transition") {
  if (!["transition", "final"].includes(mode)) throw new Error(`unsupported mode: ${mode}`);
  const workflowDir = resolve(root, ".github/workflows");
  const actual = readdirSync(workflowDir).filter((name) => name.endsWith(".yml")).sort();
  const missing = REQUIRED_WORKFLOWS.filter((name) => !actual.includes(name));
  const errors = missing.map((name) => `missing required workflow: ${name}`);
  const sources = Object.fromEntries(
    REQUIRED_WORKFLOWS.filter((name) => actual.includes(name)).map((name) => [
      name,
      readFileSync(resolve(workflowDir, name), "utf8"),
    ]),
  );

  if (mode === "final") {
    const unexpected = actual.filter((name) => !REQUIRED_WORKFLOWS.includes(name));
    if (unexpected.length > 0) errors.push(`unexpected workflows: ${unexpected.join(",")}`);
  }
  if (sources["ci.yml"] && !/(^|\n)\s*push\s*:/m.test(sources["ci.yml"])) errors.push("ci.yml must consume pushes");
  if (sources["deploy.yml"] && !/(^|\n)\s*workflow_run\s*:/m.test(sources["deploy.yml"])) errors.push("deploy.yml must consume CI workflow_run");
  if (sources["nightly.yml"] && !/(^|\n)\s*schedule\s*:/m.test(sources["nightly.yml"])) errors.push("nightly.yml must be scheduled");
  if (sources["recovery.yml"] && !containsDispatch(sources["recovery.yml"])) errors.push("recovery.yml must be manually dispatchable");
  for (const name of ["ci.yml", "deploy.yml", "nightly.yml"]) {
    if (sources[name] && containsDispatch(sources[name])) errors.push(`${name} must not be manually dispatchable`);
  }
  if (mode === "final" && sources["ci.yml"] && /(^|\n)\s*pull_request\s*:/m.test(sources["ci.yml"])) {
    errors.push("final ci.yml must not consume pull requests");
  }

  return { mode, actual, required: REQUIRED_WORKFLOWS, errors, valid: errors.length === 0 };
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const mode = process.argv[2] ?? "transition";
  const result = validateWorkflowSet(process.cwd(), mode);
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
  if (!result.valid) process.exitCode = 1;
}
