import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workflow = readFileSync(new URL('../workflows/nightly.yml', import.meta.url), 'utf8');

function jobSection(id, nextId) {
  const start = workflow.indexOf(`\n  ${id}:`);
  assert.notEqual(start, -1, `missing nightly job ${id}`);
  const end = nextId ? workflow.indexOf(`\n  ${nextId}:`, start + 1) : workflow.length;
  assert.notEqual(end, -1, `missing following nightly job ${nextId}`);

  return workflow.slice(start, end);
}

test('nightly executes authority and full PHPUnit as independent failure domains', () => {
  const authority = jobSection('authority-contract', 'full-phpunit');
  const fullPhpunit = jobSection('full-phpunit', 'codeql');

  assert.match(authority, /bash backend\/scripts\/ci_verify_mbti\.sh/);
  assert.doesNotMatch(authority, /php artisan test --no-ansi/);
  assert.match(fullPhpunit, /php artisan test --no-ansi/);
  assert.doesNotMatch(fullPhpunit, /needs:\s*authority-contract/);
  assert.equal((workflow.match(/bash backend\/scripts\/ci_verify_mbti\.sh/g) ?? []).length, 1);
  assert.equal((workflow.match(/php artisan test --no-ansi/g) ?? []).length, 1);
});

test('nightly keeps dependency, workflow, security, scheduler, and GSC evidence separate', () => {
  assert.match(jobSection('dependency-audit', 'workflow-contracts'), /composer audit --locked/);
  assert.match(jobSection('workflow-contracts', 'authority-contract'), /node --test \.github\/trunk\/\*\.test\.mjs/);
  assert.match(jobSection('codeql', 'gsc-read-model-sync'), /Blocking Semgrep scan/);
  assert.match(jobSection('scheduler-evidence-monitor', 'dependency-audit'), /ops:scheduler-evidence-monitor --json/);
  assert.match(jobSection('gsc-read-model-sync', 'nightly-summary'), /gsc-read-model-nightly-sync\.v2/);
});

test('final receipt reports every domain and fails closed without rolling back production', () => {
  const summary = jobSection('nightly-summary');
  for (const domain of [
    'authority_contract',
    'full_phpunit',
    'dependency_audit',
    'workflow_contracts',
    'security_scan',
    'scheduler_evidence',
    'gsc_readonly_sync',
  ]) {
    assert.match(summary, new RegExp(`${domain}: \\{result:`));
  }
  assert.match(summary, /schema_version: "nightly-failure-domain-summary\.v1"/);
  assert.match(summary, /production_rollback_requested: false/);
  assert.match(summary, /jq -e '\.status == "pass"'/);
});
