import test from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { readFileSync, writeFileSync, mkdtempSync, rmSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { inspectPackage, packageSha256, isIqOnlyPromotionRegistration, workflowSignature } from './iq-public-scale-package.mjs';
import { classifyPaths } from './classify-paths.mjs';
import { buildExecution, publish, recoveryFailure } from './iq-public-scale-publish.mjs';

const backend = fileURLToPath(new URL('../../backend/', import.meta.url));
const env = { IQ_PUBLISH_ENVIRONMENT: 'staging', DEPLOY_PATH: '/srv/application', DEPLOY_HOST: 'example.invalid', DEPLOY_USER: 'deploy', DEPLOY_PORT: '22',
  DEPLOY_SHA: 'a'.repeat(40), GITHUB_RUN_ID: '12', GITHUB_RUN_ATTEMPT: '1', CONTENT_PROMOTION_AUTOMATION_KEY: 'test-only-key-that-is-not-a-production-key' };
const execution = () => buildExecution(env, backend);
const success = () => ({ schema: 'iq.public_scale.publish.v1', ok: true, source_commit: env.DEPLOY_SHA, workflow_run_id: '12', workflow_run_attempt: 1,
  package_sha256: packageSha256, published_count: 2, sanitized: true,
  phases: ['preflight', 'draft-import', 'publish', 'live-qa'].map(phase => ({ phase, receipt_sha256: 'b'.repeat(64), readback_count: 2 })) });

test('transport binds the reviewed package and exact workflow to the deployed narrow driver', () => {
  const current = execution();
  assert.equal(inspectPackage(backend).package_sha256, packageSha256);
  assert.equal(current.request.package_sha256, packageSha256);
  assert.equal(current.request.workflow_signature.length, 64);
  assert.ok(current.args.includes('StrictHostKeyChecking=yes'));
  assert.match(current.args.at(-1), /www-data -- php .*run_iq_public_scale_publish\.php/);
  assert.equal(current.args.some(arg => arg.includes(env.CONTENT_PROMOTION_AUTOMATION_KEY)), false);
});
test('an operation retry and unsafe transport identity fail before SSH', () => {
  assert.throws(() => buildExecution({ ...env, GITHUB_RUN_ATTEMPT: '2' }, backend), /IQ_WORKFLOW_IDENTITY_INVALID/);
  assert.throws(() => buildExecution({ ...env, DEPLOY_HOST: 'host;command' }, backend), /IQ_TRANSPORT_IDENTITY_INVALID/);
});
test('accepted transport output is restricted to immutable receipt identifiers', () => {
  const output = publish(execution(), () => ({ status: 0, stdout: JSON.stringify({ ...success(), private_details: 'must not leak' }) }));
  assert.equal(output.published_count, 2);
  assert.equal(JSON.stringify(output).includes('must not leak'), false);
});
test('a wrong exact SHA invokes only one recovery and never retries publication', () => {
  const modes = [];
  assert.throws(() => publish(execution(), request => {
    modes.push(request.mode);
    return request.mode === 'publish'
      ? { status: 0, stdout: JSON.stringify({ ...success(), source_commit: 'c'.repeat(40) }) }
      : { status: 0, stdout: JSON.stringify({ ok: true, mode: 'recover', source_commit: env.DEPLOY_SHA, workflow_run_id:'12', workflow_run_attempt:1, package_sha256:packageSha256, executor_release_sha256:execution().binding.executor_release_sha256, sanitized:true, restored: true, recovery_status: 'restored' }) };
  }), error => error.message === 'IQ_PUBLICATION_FAILED' && error.receipt.recovery_completed === true);
  assert.deepEqual(modes, ['publish', 'recover']);
});
test('an ambiguous recovery remains failed and redacts transport diagnostics', () => {
  const error = recoveryFailure(execution(), () => ({ status: 1, stdout: 'private topology or raw body', stderr: 'secret' }));
  assert.equal(error.receipt.recovery_completed, false);
  assert.equal(JSON.stringify(error.receipt).includes('private topology'), false);
});
test('the PHP driver rejects an invalid request before application bootstrap', () => {
  const response = spawnSync('php', [`${backend}/scripts/deploy/run_iq_public_scale_publish.php`], { input: '{"workflow_signature":"do-not-output-this"}', encoding: 'utf8' });
  assert.equal(response.status, 1);
  assert.deepEqual(JSON.parse(response.stdout), { ok: false, error_code: 'iq_public_publication_failed', recovery_completed: null, sanitized: true });
  assert.equal(response.stdout.includes('do-not-output-this'), false);
});

test('only the exact IQ entry package selects publication; implementation-only changes select checks', () => {
  const body = classifyPaths(['backend/content_assets/iq_public/entry/20261009-v1/IQ-01-en.md']);
  assert.equal(body.operations.iq_public_scale_publish, true);
  const implementation = classifyPaths(['backend/app/Services/ContentPromotion/Adapters/IqPublicScalePromotionAdapter.php']);
  assert.equal(implementation.operations.iq_public_scale_publish, false);
  assert.equal(implementation.operations.iq_public_scale_checks, true);
  assert.equal(classifyPaths(['backend/content_packs/IQ_RAVEN/private.json']).operations.iq_public_scale_publish, false);
});
test('config exemption proves the exact IQ delta or the known unreleased EQ plus IQ delta', () => {
  const after = readFileSync(`${backend}/config/content_promotion.php`, 'utf8')
    .replace("        'content_assets/iq_public/articles/20261010-v1',\n", '').replace(", 'IQ-PUBLIC-ARTICLES' => 'audit_compatible'", '')
    .replace("        'content_assets/iq_public/topics/20261010-v1',\n", '').replace(", 'IQ-EQ-TOPIC' => 'audit_compatible'", '');
  const before = after.replace("        'content_assets/iq_public/entry/20261009-v1',\n", '').replace(", 'iq-public-scale' => 'audit_compatible'", '');
  assert.equal(isIqOnlyPromotionRegistration(before, after), true);
  const earlier = before.replace("        'content_assets/eq_public/candidate/20261009-new-articles',\n", '').replace(", 'EQ-NEW-SOURCE-ARTICLES' => 'audit_compatible'", '');
  assert.equal(isIqOnlyPromotionRegistration(earlier, after), true);
  assert.equal(isIqOnlyPromotionRegistration(before, after.replace("'iq' => 'fail_closed_legacy_audit'", "'iq' => 'audit_compatible'")), false);
  assert.equal(isIqOnlyPromotionRegistration(before, `${after}\n`), false);
});
test('both environments publish in the existing workflow and LKG requires completed exact business recovery', () => {
  const workflow = readFileSync(new URL('../workflows/deploy.yml', import.meta.url), 'utf8');
  const staging = workflow.split('  staging:')[1].split('  production:')[0];
  const production = workflow.split('  production:')[1];
  // policy.operations is Base64 for transport; classification is raw JSON.
  // Exercise the actual referenced output, including a false publish flag.
  for (const block of [staging, production]) {
    const condition = block.split('name: Publish exact reviewed bilingual IQ public entry')[1].split('\n')[1];
    const match = condition.match(/fromJSON\(needs\.policy\.outputs\.(\w+)\)\.operations\.iq_public_scale_publish/);
    assert.ok(match, 'publisher must parse the raw classification output');
    for (const selected of [true, false]) {
      const classification = JSON.stringify({ operations: { iq_public_scale_publish: selected } });
      const outputs = { classification, operations: Buffer.from(JSON.stringify({ iq_public_scale_publish: selected })).toString('base64') };
      assert.equal(JSON.parse(outputs[match[1]]).operations.iq_public_scale_publish, selected);
    }
  }
  assert.ok(staging.indexOf('id: iq-public-publish') < staging.indexOf('Record staging timing'));
  assert.ok(production.indexOf('id: iq-public-publish') < production.indexOf('Restore exact LKG after Career publisher failure'));
  const block = production.split("if [ '${{ steps.iq-public-publish.outcome }}' = failure ]; then")[1];
  const predicate = block.split("jq -e --arg sha \"$DEPLOY_SHA\" --arg run \"$GITHUB_RUN_ID\" '")[1].split("' \"$RUNNER_TEMP/iq-public-scale-publication/production.json\"")[0];
  const receipt = { schema: 'iq.public_scale.publish.v1', ok: false, source_commit: env.DEPLOY_SHA, workflow_run_id: '12', workflow_run_attempt: 1, sanitized: true, recovery_completed: true };
  const admitted = value => spawnSync('jq', ['-e', '--arg', 'sha', env.DEPLOY_SHA, '--arg', 'run', '12', predicate], { input: JSON.stringify(value), encoding: 'utf8' }).status === 0;
  assert.equal(admitted(receipt), true);
  assert.equal(admitted({ ...receipt, recovery_completed: false }), false);
  assert.equal(admitted({ ...receipt, source_commit: 'c'.repeat(40) }), false);
});

for (const started of [false, true]) test(`real CLI failure ${started ? 'after' : 'before'} transport records the correct LKG recovery boundary`, () => {
  const directory = mkdtempSync(join(tmpdir(), 'iq-cli-failure-'));
  try {
    const marker = join(directory, 'ssh-called');
    writeFileSync(join(directory, 'ssh'), '#!/bin/sh\nprintf "called\\n" >> "$IQ_TEST_SSH_MARKER"\nexit 1\n', { mode: 0o700 });
    const root = fileURLToPath(new URL('../../', import.meta.url));
    const source = spawnSync('git', ['rev-parse', 'HEAD'], { cwd: root, encoding: 'utf8' }).stdout.trim();
    const response = spawnSync(process.execPath, ['.github/trunk/iq-public-scale-publish.mjs'], { cwd: root, encoding: 'utf8',
      env: { ...process.env, ...env, DEPLOY_SHA: source, RUNNER_TEMP: directory,
        CONTENT_PROMOTION_AUTOMATION_KEY: started ? env.CONTENT_PROMOTION_AUTOMATION_KEY : 'short',
        IQ_TEST_SSH_MARKER: marker, PATH: `${directory}:${process.env.PATH}` } });
    assert.equal(response.status, 1);
    assert.equal(response.stderr.trim(), 'IQ_PUBLIC_SCALE_PUBLICATION_FAILED');
    const receipt = JSON.parse(readFileSync(join(directory, 'iq-public-scale-publication/staging.json'), 'utf8'));
    assert.equal(receipt.source_commit, source);
    assert.equal(receipt.workflow_run_id, '12');
    assert.equal(receipt.workflow_run_attempt, 1);
    assert.equal(receipt.transport_started, started);
    assert.equal(receipt.recovery_completed, !started);
    assert.equal(existsSync(marker), started);
    if (started) assert.equal(readFileSync(marker, 'utf8').trim().split('\n').length, 2);
    else assert.equal(receipt.recovery_status, 'not_required');
  } finally { rmSync(directory, { recursive: true, force: true }); }
});

test('entry HMAC changes with executor bytes and another recovery execution cannot authorize LKG',()=>{
  const binding=inspectPackage(backend);const sign=b=>workflowSignature(b,env.CONTENT_PROMOTION_AUTOMATION_KEY,env.DEPLOY_SHA,'12',1);
  assert.notEqual(sign(binding),sign({...binding,executor_release_sha256:'e'.repeat(64)}));
  const good={ok:true,mode:'recover',source_commit:env.DEPLOY_SHA,workflow_run_id:'12',workflow_run_attempt:1,package_sha256:packageSha256,executor_release_sha256:binding.executor_release_sha256,sanitized:true,restored:true,recovery_status:'restored'};
  assert.equal(recoveryFailure(execution(),()=>({status:0,stdout:JSON.stringify(good)})).receipt.recovery_completed,true);
  for(const patch of [{workflow_run_id:'13'},{workflow_run_attempt:2},{package_sha256:'f'.repeat(64)},{executor_release_sha256:'e'.repeat(64)},{sanitized:false}])
    assert.equal(recoveryFailure(execution(),()=>({status:0,stdout:JSON.stringify({...good,...patch})})).receipt.recovery_completed,false);
});


test('online failure receipts retain only allowlisted failure codes and still recover once', () => {
  for (const message of ['IQ_ONLINE_API_TIMEOUT', 'IQ_SSR_BODY_MISMATCH', 'secret https://private.invalid/raw']) {
    let calls = 0;
    const error = recoveryFailure(execution(), () => { calls++; return { status: 1 }; }, new Error(message));
    assert.equal(calls, 1);
    assert.equal(error.receipt.failure_code, message.startsWith('IQ_') ? message : 'IQ_ONLINE_ACCEPTANCE_FAILED');
    assert.equal(JSON.stringify(error.receipt).includes('private.invalid'), false);
    assert.equal(error.receipt.recovery_completed, false);
  }
});
