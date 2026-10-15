import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash, createHmac } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { inspectPackage, readCandidateRows, workflowSignature, executorPaths, packageSha256, isIqEqTopicOnlyContextFactoryChange, isIqEqTopicOnlyPromotionRegistration } from './iq-eq-topic-package.mjs';
import { buildExecution, publish, recoveryFailure } from './iq-eq-topic-publish.mjs';

const backend = fileURLToPath(new URL('../../backend/', import.meta.url));
const env = { IQ_EQ_TOPIC_PUBLISH_ENVIRONMENT: 'staging', DEPLOY_PATH: '/srv/application', DEPLOY_HOST: 'example.invalid', DEPLOY_USER: 'deploy', DEPLOY_PORT: '22',
  DEPLOY_SHA: 'a'.repeat(40), GITHUB_RUN_ID: '12', GITHUB_RUN_ATTEMPT: '1', CONTENT_PROMOTION_AUTOMATION_KEY: 'synthetic-test-key-never-a-production-secret' };
const execution = () => buildExecution(env, backend);
const success = () => ({ schema: 'iq.eq.topic.publish.v1', ok: true, source_commit: env.DEPLOY_SHA, workflow_run_id: '12', workflow_run_attempt: 1,
  package_sha256: packageSha256, published_count: 2, sanitized: true,
  phases: ['preflight', 'draft-import', 'publish', 'live-qa'].map(phase => ({ phase, receipt_sha256: 'b'.repeat(64), readback_count: 2 })) });
const recovery = () => ({ ok: true, mode: 'recover', source_commit: env.DEPLOY_SHA, workflow_run_id: '12', workflow_run_attempt: 1,
  package_sha256: packageSha256, executor_release_sha256: execution().binding.executor_release_sha256, restored: true, recovery_status: 'restored', sanitized: true });

test('binding reads both reviewed Topic payloads through the sole PHP package codec', () => {
  const rows = readCandidateRows(backend);
  assert.equal(rows.length, 2);
  assert.deepEqual(rows.map(row => row.snapshot.section_candidates.find(section => section.section_key === 'faq').payload_json.items.length), [7, 7]);
  assert.equal(inspectPackage(backend).package_sha256, packageSha256);
});
test('the real PHP driver and Node workflow bind identical executor files and hashes', () => {
  const source = readFileSync(`${backend}/scripts/deploy/run_iq_eq_topic_publish.php`, 'utf8');
  const array = source.split('$executorPaths = [')[1].split('];')[0];
  const paths = [...array.matchAll(/'([^']+)'/g)].map(match => match[1]).sort();
  assert.deepEqual(paths, executorPaths);
  const material = paths.map(path => `${path}\n${createHash('sha256').update(readFileSync(`${backend}/${path}`)).digest('hex')}\n`).join('');
  assert.equal(createHash('sha256').update(material).digest('hex'), inspectPackage(backend).executor_release_sha256);
});
test('workflow HMAC binds exact executor release and cannot transfer to changed implementation', () => {
  const binding = inspectPackage(backend);
  const material = ['content-promotion-v2', env.DEPLOY_SHA, '12', 1, 'W3', 'IQ-EQ-TOPIC', packageSha256, binding.release_policy_sha256, 2, binding.executor_release_sha256].join('|');
  const signature = workflowSignature(binding, env.CONTENT_PROMOTION_AUTOMATION_KEY, env.DEPLOY_SHA, '12', 1);
  assert.equal(signature, createHmac('sha256', env.CONTENT_PROMOTION_AUTOMATION_KEY).update(material).digest('hex'));
  assert.notEqual(signature, workflowSignature({ ...binding, executor_release_sha256: 'e'.repeat(64) }, env.CONTENT_PROMOTION_AUTOMATION_KEY, env.DEPLOY_SHA, '12', 1));
});
test('transport requires the narrow driver, authenticated SSH and first attempt', () => {
  const current = execution();
  assert.ok(current.args.includes('StrictHostKeyChecking=yes'));
  assert.match(current.args.at(-1), /www-data -- php .*run_iq_eq_topic_publish\.php/);
  assert.equal(current.args.some(arg => arg.includes(env.CONTENT_PROMOTION_AUTOMATION_KEY)), false);
  assert.throws(() => buildExecution({ ...env, GITHUB_RUN_ATTEMPT: '2' }, backend), /IQ_EQ_TOPIC_WORKFLOW_IDENTITY_INVALID/);
  assert.throws(() => buildExecution({ ...env, DEPLOY_HOST: 'host;command' }, backend), /IQ_EQ_TOPIC_TRANSPORT_IDENTITY_INVALID/);
});
test('a wrong phase readback invokes one recovery and never repeats failed publication', () => {
  const modes = [];
  assert.throws(() => publish(execution(), request => {
    modes.push(request.mode);
    const result = request.mode === 'recover' ? recovery() : success();
    if (request.mode === 'publish') result.phases[3].readback_count = 1;
    return { status: 0, stdout: JSON.stringify(result) };
  }), error => error.message === 'IQ_EQ_TOPIC_PUBLICATION_FAILED' && error.receipt.recovery_completed === true);
  assert.deepEqual(modes, ['publish', 'recover']);
});
test('another execution recovery and raw transport diagnostics remain failed and sanitized', () => {
  const error = recoveryFailure(execution(), () => ({ status: 0, stdout: JSON.stringify({ ...recovery(), workflow_run_id: '9999', private_details: 'private topology' }), stderr: 'secret' }));
  assert.equal(error.receipt.recovery_completed, false);
  assert.equal(JSON.stringify(error.receipt).includes('private topology'), false);
});
test('the PHP driver rejects malformed signed input before bootstrapping or exposing values', () => {
  const response = spawnSync('php', [`${backend}/scripts/deploy/run_iq_eq_topic_publish.php`], { input: '{"workflow_signature":"do-not-output-this"}', encoding: 'utf8' });
  assert.equal(response.status, 1);
  assert.deepEqual(JSON.parse(response.stdout), { ok: false, error_code: 'iq_eq_topic_publication_failed', recovery_completed: null, sanitized: true });
});
test('a public registration exemption proves only the exact Topic root and capability', () => {
  const after = readFileSync(`${backend}/config/content_promotion.php`, 'utf8');
  const before = after.replace("        'content_assets/iq_public/topics/20261010-v1',\n", '').replace(", 'IQ-EQ-TOPIC' => 'audit_compatible'", '');
  assert.equal(isIqEqTopicOnlyPromotionRegistration(before, after), true);
  assert.equal(isIqEqTopicOnlyPromotionRegistration(before, `${after}\n`), false);
  assert.equal(isIqEqTopicOnlyPromotionRegistration(before, after.replace("'iq' => 'fail_closed_legacy_audit'", "'iq' => 'audit_compatible'")), false);
});

test('existing CI and deployment require focused verification, staging and bounded owned recovery', () => {
  const ci = readFileSync(new URL('../workflows/ci.yml', import.meta.url), 'utf8');
  const workflow = readFileSync(new URL('../workflows/deploy.yml', import.meta.url), 'utf8');
  assert.match(ci, /operations\.iq_eq_topic_checks == true/);
  assert.match(ci, /IqEqTopicPromotionAdapterTest\.php/);
  assert.match(ci, /iq-eq-topic-online-qa\.test\.mjs/);
  for (const environment of ['staging', 'production']) {
    assert.match(workflow, new RegExp('IQ_EQ_TOPIC_PUBLISH_ENVIRONMENT: '+environment));
    assert.match(workflow, new RegExp('iq-eq-topic-'+environment+'-'));
  }
  assert.equal((workflow.match(/operations\.iq_eq_topic_publish == true/g) ?? []).length, 2);
  assert.match(workflow, /steps\.baseline\.outputs\.skip != 'true' && fromJSON\(needs\.policy\.outputs\.classification\)\.operations\.iq_eq_topic_publish/);
  const recovery = workflow.slice(workflow.indexOf('      - name: Restore exact LKG after Career publisher failure'));
  assert.match(recovery, /steps\.iq-topic-publish\.outcome == 'failure'/);
  assert.match(recovery, /\.schema == "iq\.public_articles\.publish\.v1"/);
  assert.match(recovery, /iq-eq-topic-publication\/production\.json/);
  assert.match(recovery, /\.recovery_completed == true/);
  const driver = readFileSync(`${backend}/scripts/deploy/run_iq_eq_topic_publish.php`, 'utf8');
  assert.match(driver, /flock\(\$mutex, LOCK_EX \| LOCK_NB\)/);
  assert.doesNotMatch(driver, /proc_open|shell_exec|exec\(/);
});

test('only the exact IQ-only signature extension exempts unaffected private canonical consumers', () => {
  const after = readFileSync(`${backend}/app/Services/ContentPromotion/PromotionContextFactory.php`, 'utf8');
  const start = after.indexOf('        // The IQ/EQ topic executor contract');
  const end = after.indexOf('        if (strlen($workflowIdentityKey)', start);
  const before = after.slice(0, start) + after.slice(end);
  assert.equal(isIqEqTopicOnlyContextFactoryChange(before, after), true);
  assert.equal(isIqEqTopicOnlyContextFactoryChange(before, after.replace("'content-promotion-v2'", "'changed-private-signature'")), false);
  assert.equal(isIqEqTopicOnlyContextFactoryChange(before, after + '\n'), false);
  const driver = readFileSync(`${backend}/scripts/deploy/run_iq_eq_topic_publish.php`, 'utf8');
  assert.match(driver, /\$request\['mode'\] === 'recover' \? 180 : 0/);
  assert.match(driver, /microtime\(true\) >= \$lockDeadline/);
});

test('Topic registration and signature proofs handle the full unreleased Article delta but reject unrelated changes', () => {
  const config = readFileSync(`${backend}/config/content_promotion.php`, 'utf8');
  const baseline = config.replace("        'content_assets/iq_public/topics/20261010-v1',\n", '').replace(", 'IQ-EQ-TOPIC' => 'audit_compatible'", '')
    .replace("        'content_assets/iq_public/articles/20261010-v1',\n", '').replace(", 'IQ-PUBLIC-ARTICLES' => 'audit_compatible'", '');
  assert.equal(isIqEqTopicOnlyPromotionRegistration(baseline, config), true);
  assert.equal(isIqEqTopicOnlyPromotionRegistration(baseline, config.replace("'fail_closed_legacy_audit'", "'audit_compatible'")), false);
  const factory = readFileSync(`${backend}/app/Services/ContentPromotion/PromotionContextFactory.php`, 'utf8');
  const begin = factory.indexOf('        // The IQ article executor contract');
  const end = factory.indexOf('        if (strlen($workflowIdentityKey)', begin);
  const before = factory.slice(0, begin) + factory.slice(end);
  assert.equal(isIqEqTopicOnlyContextFactoryChange(before, factory), true);
  assert.equal(isIqEqTopicOnlyContextFactoryChange(before, factory + '\n'), false);
});

test('recover receipts with another or missing executor cannot authorize code LKG', () => {
  for (const executor of ['b'.repeat(64), undefined]) {
    const result = { ...recovery(), executor_release_sha256: executor };
    assert.equal(recoveryFailure(execution(), () => ({status:0,stdout:JSON.stringify(result)})).receipt.recovery_completed, false);
  }
});
