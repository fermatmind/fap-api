import { execFileSync, spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { inspectPackage, workflowSignature, packageSha256 } from './iq-eq-topic-package.mjs';
import { verifyOnlineCandidates, readOnlinePrestate } from './iq-eq-topic-online-qa.mjs';

const quote = value => `'${String(value).replaceAll("'", "'\\''")}'`;
export function buildExecution(env, backendRoot) {
  if (!['staging', 'production'].includes(env.IQ_EQ_TOPIC_PUBLISH_ENVIRONMENT)
    || !/^\/[a-zA-Z0-9_./-]+$/.test(env.DEPLOY_PATH ?? '')
    || !/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/.test(env.DEPLOY_USER ?? '')
    || !/^[a-zA-Z0-9][a-zA-Z0-9.:-]*$/.test(env.DEPLOY_HOST ?? '')
    || !/^[1-9][0-9]{0,4}$/.test(env.DEPLOY_PORT ?? '') || Number(env.DEPLOY_PORT) > 65535) throw new Error('IQ_EQ_TOPIC_TRANSPORT_IDENTITY_INVALID');
  const binding = inspectPackage(backendRoot);
  const request = {
    source_commit: env.DEPLOY_SHA, workflow_run_id: env.GITHUB_RUN_ID,
    workflow_run_attempt: Number(env.GITHUB_RUN_ATTEMPT), package_sha256: binding.package_sha256,
    executor_release_sha256: binding.executor_release_sha256, release_policy_sha256: binding.release_policy_sha256,
    workflow_signature: workflowSignature(binding, env.CONTENT_PROMOTION_AUTOMATION_KEY, env.DEPLOY_SHA, env.GITHUB_RUN_ID, Number(env.GITHUB_RUN_ATTEMPT)),
    mode: 'publish',
  };
  const command = `sudo -n -u www-data -- php ${quote(`${env.DEPLOY_PATH}/current/backend/scripts/deploy/run_iq_eq_topic_publish.php`)}`;
  const args = ['-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=10', '-o', 'ConnectionAttempts=1'];
  const identity = env.IQ_EQ_TOPIC_PUBLISH_ENVIRONMENT === 'staging' ? env.DEPLOY_IDENTITY_FILE_STG : env.DEPLOY_IDENTITY_FILE_PROD;
  if (identity) args.push('-o', 'IdentitiesOnly=yes', '-i', identity);
  args.push('-p', env.DEPLOY_PORT, `${env.DEPLOY_USER}@${env.DEPLOY_HOST}`, command);
  return { binding, request, args };
}
const transport = (execution, request) => spawnSync('ssh', execution.args, {
  input: JSON.stringify(request), encoding: 'utf8', timeout: 800000, maxBuffer: 262144,
  stdio: ['pipe', 'pipe', 'pipe'],
});
export function recoveryFailure(execution, execute = request => transport(execution, request)) {
  let completed = false;
  try {
    const response = execute({ ...execution.request, mode: 'recover' });
    const result = JSON.parse(response.stdout ?? '');
    completed = response.status === 0 && result.ok === true && result.mode === 'recover'
      && result.source_commit === execution.request.source_commit
      && result.workflow_run_id === execution.request.workflow_run_id && result.workflow_run_attempt === 1
      && result.package_sha256 === execution.binding.package_sha256
      && result.executor_release_sha256 === execution.binding.executor_release_sha256 && result.sanitized === true
      && ((result.restored === true && result.recovery_status === 'restored') || (result.restored === false && result.recovery_status === 'not_required'));
  } catch { /* Failed recovery keeps the release failed. */ }
  const error = new Error('IQ_EQ_TOPIC_PUBLICATION_FAILED');
  error.receipt = { schema: 'iq.eq.topic.publish.v1', ok: false,
    source_commit: execution.request.source_commit, workflow_run_id: execution.request.workflow_run_id,
    workflow_run_attempt: 1, package_sha256: execution.binding.package_sha256,
    recovery_completed: completed, transport_started: true, sanitized: true };
  return error;
}
export function publish(execution, execute = request => transport(execution, request)) {
  try {
    const response = execute(execution.request);
    const output = JSON.parse(response.stdout ?? '');
    if (response.status !== 0 || output.ok !== true || output.schema !== 'iq.eq.topic.publish.v1'
      || output.source_commit !== execution.request.source_commit || output.workflow_run_id !== execution.request.workflow_run_id
      || output.workflow_run_attempt !== 1 || output.package_sha256 !== execution.binding.package_sha256
      || output.published_count !== 2 || output.sanitized !== true
      || output.phases?.map(row => row.phase).join('|') !== 'preflight|draft-import|publish|live-qa'
      || output.phases.some(row => row.readback_count !== 2 || !/^[a-f0-9]{64}$/.test(row.receipt_sha256))) throw new Error('IQ_EQ_TOPIC_PUBLICATION_RESPONSE_INVALID');
    return { schema: output.schema, ok: true, source_commit: output.source_commit, workflow_run_id: output.workflow_run_id,
      workflow_run_attempt: 1, package_sha256: output.package_sha256, published_count: 2,
      phases: output.phases.map(row => ({ phase: row.phase, receipt_sha256: row.receipt_sha256, readback_count: 2 })), sanitized: true };
  } catch {
    throw recoveryFailure(execution, execute);
  }
}
function failedBeforeTransport(env) {
  if (!/^[a-f0-9]{40}$/.test(env.DEPLOY_SHA ?? '') || !/^[1-9][0-9]*$/.test(env.GITHUB_RUN_ID ?? '')
    || env.GITHUB_RUN_ATTEMPT !== '1') throw new Error('IQ_EQ_TOPIC_WORKFLOW_IDENTITY_INVALID');
  const error = new Error('IQ_EQ_TOPIC_PUBLICATION_FAILED');
  error.receipt = { schema: 'iq.eq.topic.publish.v1', ok: false, source_commit: env.DEPLOY_SHA,
    workflow_run_id: env.GITHUB_RUN_ID, workflow_run_attempt: 1, package_sha256: packageSha256,
    transport_started: false, recovery_completed: true, recovery_status: 'not_required', sanitized: true };
  return error;
}
async function cli() {
  const env = process.env;
  let execution = null;
  let transportStarted = false;
  const directory = resolve(env.RUNNER_TEMP, 'iq-eq-topic-publication');
  try {
    mkdirSync(directory, { recursive: true, mode: 0o700 });
    if (execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim() !== env.DEPLOY_SHA) throw new Error('IQ_EQ_TOPIC_CHECKOUT_SHA_MISMATCH');
    execution = buildExecution(env, resolve('backend'));
    const prestate = await readOnlinePrestate(env.IQ_EQ_TOPIC_PUBLISH_ENVIRONMENT, resolve('backend'));
    transportStarted = true;
    const receipt = publish(execution);
    try {
      receipt.online_acceptance = await verifyOnlineCandidates(env.IQ_EQ_TOPIC_PUBLISH_ENVIRONMENT, resolve('backend'), fetch, undefined, prestate);
    } catch {
      throw recoveryFailure(execution);
    }
    writeFileSync(resolve(directory, `${env.IQ_EQ_TOPIC_PUBLISH_ENVIRONMENT}.json`), `${JSON.stringify(receipt)}\n`, { flag: 'wx', mode: 0o600 });
  } catch (original) {
    const error = original.receipt ? original : transportStarted ? recoveryFailure(execution) : failedBeforeTransport(env);
    mkdirSync(directory, { recursive: true, mode: 0o700 });
    writeFileSync(resolve(directory, `${env.IQ_EQ_TOPIC_PUBLISH_ENVIRONMENT}.json`), `${JSON.stringify(error.receipt)}\n`, { flag: 'wx', mode: 0o600 });
    throw error;
  }
}
if (process.argv[1] && fileURLToPath(import.meta.url) === resolve(process.argv[1])) {
  cli().catch(() => { process.stderr.write('IQ_EQ_TOPIC_PUBLIC_SCALE_PUBLICATION_FAILED\n'); process.exitCode = 1; });
}
