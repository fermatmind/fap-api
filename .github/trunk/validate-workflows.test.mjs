import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { chmodSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import test from "node:test";

import { validateWorkflowSet, templatedRunLengths } from "./validate-workflows.mjs";

const ciWorkflowPath = resolve(import.meta.dirname, "../workflows/ci.yml");

function changedPhpTestStep() {
  const source = readFileSync(ciWorkflowPath, "utf8");
  const stepName = "      - name: Execute changed PHP tests for tests-only and mixed scopes";
  const stepStart = source.indexOf(stepName);
  assert.notEqual(stepStart, -1);
  const runMarker = "        run: |\n";
  const runStart = source.indexOf(runMarker, stepStart);
  assert.notEqual(runStart, -1);

  const lines = [];
  for (const line of source.slice(runStart + runMarker.length).split("\n")) {
    if (!line.startsWith("          ")) break;
    lines.push(line.slice(10));
  }

  return {
    header: source.slice(stepStart, runStart),
    script: lines.join("\n"),
  };
}

function git(root, ...args) {
  return spawnSync("git", args, { cwd: root, encoding: "utf8" }).stdout.trim();
}

function changedPathFixture(changedPath, contents) {
  const root = mkdtempSync(join(tmpdir(), "changed-php-selector-"));
  mkdirSync(join(root, "backend"), { recursive: true });
  spawnSync("git", ["init", "--quiet"], { cwd: root });
  spawnSync("git", ["config", "user.email", "ci@example.test"], { cwd: root });
  spawnSync("git", ["config", "user.name", "CI Contract"], { cwd: root });
  writeFileSync(join(root, "README.md"), "selector fixture\n");
  spawnSync("git", ["add", "README.md"], { cwd: root });
  spawnSync("git", ["commit", "--quiet", "-m", "base"], { cwd: root });
  const before = git(root, "rev-parse", "HEAD");

  const target = join(root, changedPath);
  mkdirSync(dirname(target), { recursive: true });
  writeFileSync(target, contents);
  spawnSync("git", ["add", changedPath], { cwd: root });
  spawnSync("git", ["commit", "--quiet", "-m", "change"], { cwd: root });
  const after = git(root, "rev-parse", "HEAD");

  const bin = join(root, "bin");
  mkdirSync(bin);
  const php = join(bin, "php");
  writeFileSync(php, "#!/usr/bin/env bash\nprintf '%s\\n' \"$*\"\n");
  chmodSync(php, 0o755);

  return { root, before, after, bin };
}

function runChangedPhpTestStep(fixture, script) {
  const compatibility = `
if ! command -v mapfile >/dev/null 2>&1; then
  mapfile() {
    [ "$1" = "-t" ] && shift
    local target="$1" line
    eval "$target=()"
    while IFS= read -r line; do eval "$target+=(\"\$line\")"; done
  }
fi
`;
  const rendered = compatibility + script
    .replaceAll("'${{ needs.classify.outputs.base_sha }}'", `'${fixture.before}'`)
    .replaceAll("'${{ github.sha }}'", `'${fixture.after}'`);

  return spawnSync("bash", ["-c", rendered], {
    cwd: join(fixture.root, "backend"),
    encoding: "utf8",
    env: { ...process.env, PATH: `${fixture.bin}:${process.env.PATH}` },
  });
}

function fixture(extra = false) {
  const root = mkdtempSync(join(tmpdir(), "trunk-workflows-"));
  const dir = join(root, ".github/workflows");
  mkdirSync(dir, { recursive: true });
  writeFileSync(join(dir, "ci.yml"), "on:\n  push:\n");
  writeFileSync(join(dir, "deploy.yml"), "on:\n  workflow_run:\n");
  writeFileSync(join(dir, "nightly.yml"), "on:\n  schedule:\n");
  writeFileSync(join(dir, "recovery.yml"), "on:\n  workflow_dispatch:\n");
  if (extra) writeFileSync(join(dir, "legacy.yml"), "on:\n  workflow_dispatch:\n");
  return root;
}

test("accepts exactly four final workflows with recovery as the only manual entry", () => {
  assert.equal(validateWorkflowSet(fixture(), "final").valid, true);
});

test("allows legacy entries only during transition", () => {
  const root = fixture(true);
  assert.equal(validateWorkflowSet(root, "transition").valid, true);
  assert.equal(validateWorkflowSet(root, "final").valid, false);
});

test("changed language runner uses exact range from repo root and inherits the mode", () => {
  const source=readFileSync(ciWorkflowPath,'utf8');
  assert.match(source,/run: node \.github\/trunk\/run-changed-tests\.mjs '\$\{\{ needs\.classify\.outputs\.base_sha \}\}' '\$\{\{ github\.sha \}\}' '\$\{\{ matrix\.mode \}\}'/);
});

test('checks the actual templated run length that GitHub rejects before job creation',()=>{const sample="        run: |\n          echo '${{ github.sha }}'\n"+'          x\n'.repeat(11000);assert.ok(templatedRunLengths(sample)[0].length>21000);const result=validateWorkflowSet(resolve(import.meta.dirname,'../..'),'final');assert.equal(result.valid,true,result.errors.join('\n'));});
