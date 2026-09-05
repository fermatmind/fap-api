import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
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
  assert.match(summary, /schema_version: "nightly-failure-domain-summary\.v2"/);
  assert.match(summary, /production_rollback_requested: false/);
  assert.match(summary, /jq -e '\.status == "pass"'/);
});

const dailySchedule = '17 18 * * *';
const weeklySchedule = '37 18 * * 0';
const weeklyResults = ['AUTHORITY_RESULT', 'FULL_PHPUNIT_RESULT', 'DEPENDENCY_RESULT', 'WORKFLOW_RESULT', 'SECURITY_RESULT'];
const dailyResults = ['SCHEDULER_RESULT', 'GSC_RESULT'];

test('daily operations and weekly complete checks have independent schedules and concurrency', () => {
  assert.deepEqual([...workflow.matchAll(/- cron: "([^"]+)"/g)].map((match) => match[1]), [dailySchedule, weeklySchedule]);
  assert.match(workflow, /group: nightly-\$\{\{ github\.repository \}\}-\$\{\{ github\.event\.schedule \}\}/);
  for (const [job, next] of [
    ['dependency-audit', 'workflow-contracts'],
    ['workflow-contracts', 'authority-contract'],
    ['authority-contract', 'full-phpunit'],
    ['full-phpunit', 'codeql'],
    ['codeql', 'gsc-read-model-sync'],
  ]) {
    assert.ok(jobSection(job, next).includes(`if: github.event_name == 'schedule' && github.event.schedule == '${weeklySchedule}'`));
  }
  for (const [job, next] of [['scheduler-evidence-monitor', 'dependency-audit'], ['gsc-read-model-sync', 'nightly-summary']]) {
    assert.ok(jobSection(job, next).includes(`if: github.event_name == 'schedule' && github.event.schedule == '${dailySchedule}'`));
  }
  assert.ok(jobSection('nightly-summary').includes(`if: always() && github.event_name == 'schedule' && (github.event.schedule == '${dailySchedule}' || github.event.schedule == '${weeklySchedule}')`));
  assert.doesNotMatch(workflow, /workflow_dispatch:|continue-on-error:/);
});

function runSummary(schedule, overrides = {}) {
  const summary = jobSection('nightly-summary');
  const marker = '        run: |\n';
  const start = summary.indexOf(marker);
  assert.notEqual(start, -1);
  const lines = [];
  for (const line of summary.slice(start + marker.length).split('\n')) {
    if (!line.startsWith('          ')) break;
    lines.push(line.slice(10));
  }
  const root = mkdtempSync(join(tmpdir(), 'nightly-summary-'));
  const results = Object.fromEntries([...weeklyResults, ...dailyResults].map((key) => [key, 'skipped']));
  for (const key of schedule === dailySchedule ? dailyResults : weeklyResults) results[key] = 'success';
  try {
    const run = spawnSync('bash', ['-c', lines.join('\n')], {
      cwd: root,
      encoding: 'utf8',
      env: { ...process.env, GITHUB_SHA: 'a'.repeat(40), SCHEDULE: schedule, ...results, ...overrides },
    });
    assert.equal(run.status, 0, run.stderr);
    const receipt = JSON.parse(readFileSync(join(root, 'artifacts/nightly-summary/receipt.json'), 'utf8'));
    const verdict = spawnSync('bash', ['-c', "jq -e '.status == \"pass\"' artifacts/nightly-summary/receipt.json >/dev/null"], { cwd: root });
    assert.equal(verdict.status, receipt.status === 'pass' ? 0 : 1);
    return receipt;
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
}

test('daily receipt cannot be mistaken for full regression success', () => {
  const receipt = runSummary(dailySchedule);
  assert.equal(receipt.status, 'pass');
  assert.equal(receipt.check_scope, 'daily_operations');
  assert.equal(receipt.workflow_sha, 'a'.repeat(40));
  assert.equal(receipt.production_rollback_requested, false);
  assert.deepEqual(receipt.domains.full_phpunit, { result: 'skipped', required: false });
  assert.deepEqual(receipt.domains.scheduler_evidence, { result: 'success', required: true });
});

test('weekly receipt requires all five complete-check domains', () => {
  const receipt = runSummary(weeklySchedule);
  assert.equal(receipt.status, 'pass');
  assert.equal(receipt.check_scope, 'weekly_full_checks');
  assert.equal(Object.values(receipt.domains).filter((domain) => domain.required).length, 5);
  assert.deepEqual(receipt.domains.full_phpunit, { result: 'success', required: true });
  assert.deepEqual(receipt.domains.gsc_readonly_sync, { result: 'skipped', required: false });
});

test('required failures, cancellations, missing results and skips fail both schedules closed', () => {
  for (const [schedule, keys] of [[dailySchedule, dailyResults], [weeklySchedule, weeklyResults]]) {
    for (const key of keys) {
      for (const result of ['failure', 'cancelled', 'skipped', '']) {
        assert.equal(runSummary(schedule, { [key]: result }).status, 'fail', `${schedule}: ${key}=${result}`);
      }
    }
  }
});

test('unknown schedules and unexpected out-of-schedule execution fail closed', () => {
  assert.equal(runSummary('unknown').status, 'fail');
  assert.equal(runSummary(dailySchedule, { FULL_PHPUNIT_RESULT: 'success' }).status, 'fail');
  assert.equal(runSummary(weeklySchedule, { GSC_RESULT: 'failure' }).status, 'fail');
});


test('full PHPUnit has parent revision history and an empty test environment file', () => {
  const fullPhpunit = jobSection('full-phpunit', 'codeql');
  assert.match(fullPhpunit, /persist-credentials: false\s+fetch-depth: 2/);
  assert.match(fullPhpunit, /working-directory: backend\s+run: touch \.env/);
  assert.ok(fullPhpunit.indexOf('run: touch .env') < fullPhpunit.indexOf('run: php artisan test'));
});
