import { execFileSync, spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { inspectPackage, workflowSignature } from './eq-new-source-package.mjs';
import { verifyOnlineCandidates } from './eq-new-source-online-qa.mjs';

const quote = value => `'${String(value).replaceAll("'", "'\\''")}'`;
export function buildExecution(env, backendRoot) {
  if (!['staging', 'production'].includes(env.EQ_PUBLISH_ENVIRONMENT)
    || !/^\/[a-zA-Z0-9_./-]+$/.test(env.DEPLOY_PATH ?? '')
    || !/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/.test(env.DEPLOY_USER ?? '')
    || !/^[a-zA-Z0-9][a-zA-Z0-9.:-]*$/.test(env.DEPLOY_HOST ?? '')
    || !/^[1-9][0-9]{0,4}$/.test(env.DEPLOY_PORT ?? '') || Number(env.DEPLOY_PORT) > 65535) throw new Error('EQ_TRANSPORT_IDENTITY_INVALID');
  const binding = inspectPackage(backendRoot);
  const attempt = Number(env.GITHUB_RUN_ATTEMPT);
  const signature = workflowSignature(binding, env.CONTENT_PROMOTION_AUTOMATION_KEY, env.DEPLOY_SHA, env.GITHUB_RUN_ID, attempt);
  const request = {
    source_commit: env.DEPLOY_SHA, workflow_run_id: env.GITHUB_RUN_ID,
    workflow_run_attempt: attempt, package_sha256: binding.package_sha256,
    executor_release_sha256: binding.executor_release_sha256,
    release_policy_sha256: binding.release_policy_sha256,
    workflow_signature: signature, mode: 'publish',
  };
  const backend = `${env.DEPLOY_PATH}/current/backend`;
  // The transport invokes the deployed script as the existing application user.
  // Signature and exact active REVISION are revalidated there before any writes.
  const command = `sudo -n -u www-data -- php ${quote(`${backend}/scripts/deploy/run_eq_new_source_article_publish.php`)}`;
  const args = ['-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=10', '-o', 'ConnectionAttempts=1'];
  const identity = env.EQ_PUBLISH_ENVIRONMENT === 'staging' ? env.DEPLOY_IDENTITY_FILE_STG : env.DEPLOY_IDENTITY_FILE_PROD;
  if (identity) args.push('-o', 'IdentitiesOnly=yes', '-i', identity);
  args.push('-p', env.DEPLOY_PORT, `${env.DEPLOY_USER}@${env.DEPLOY_HOST}`, command);
  return { binding, request, args };
}
const transport = (execution, request) => spawnSync('ssh', execution.args, {
  input: JSON.stringify(request), encoding: 'utf8', timeout: 800000, maxBuffer: 262144,
  stdio: ['pipe', 'pipe', 'pipe'],
});
export function recoveryFailure(execution, execute = request => transport(execution, request)) {
  let recoveryCompleted = false;
  try {
    const recovered = execute({ ...execution.request, mode: 'recover' });
    const result = JSON.parse(recovered.stdout ?? '');
    recoveryCompleted = recovered.status === 0 && result.ok === true && result.mode === 'recover'
      && result.source_commit === execution.request.source_commit
      && ((result.recovery_status === 'restored' && result.restored === true)
        || (result.recovery_status === 'not_required' && result.restored === false));
  } catch { /* Unavailable recovery remains a failed delivery. */ }
  const error = new Error('EQ_PUBLICATION_FAILED');
  error.receipt = { schema: 'eq.new_source_articles.publish.v1', ok: false,
    source_commit: execution.request.source_commit, workflow_run_id: execution.request.workflow_run_id,
    workflow_run_attempt: 1, package_sha256: execution.binding.package_sha256,
    recovery_completed: recoveryCompleted, sanitized: true };
  return error;
}
export function publish(execution, execute = request => transport(execution, request)) {
  try {
    const result = execute(execution.request);
    const output = JSON.parse(result.stdout ?? '');
    if (result.status !== 0 || output.ok !== true
      || output.source_commit !== execution.request.source_commit
      || output.workflow_run_id !== execution.request.workflow_run_id
      || output.workflow_run_attempt !== 1
      || output.package_sha256 !== execution.binding.package_sha256
      || output.published_count !== 3 || output.sanitized !== true
      || output.phases?.map(row => row.phase).join('|') !== 'preflight|draft-import|publish|live-qa'
      || output.phases.some(row => row.readback_count !== 3 || !/^[a-f0-9]{64}$/.test(row.receipt_sha256))) throw new Error('EQ_PUBLICATION_RESPONSE_INVALID');
    return { schema: 'eq.new_source_articles.publish.v1', ok: true,
      source_commit: output.source_commit, workflow_run_id: output.workflow_run_id,
      workflow_run_attempt: 1, package_sha256: output.package_sha256,
      published_count: 3, phases: output.phases.map(row => ({ phase: row.phase, receipt_sha256: row.receipt_sha256, readback_count: 3 })),
      sanitized: true };
  } catch {
    throw recoveryFailure(execution, execute);
  }
}
async function cli() {
  const env = process.env;
  if (execFileSync('git', ['rev-parse', 'HEAD'], { encoding: 'utf8' }).trim() !== env.DEPLOY_SHA) throw new Error('EQ_CHECKOUT_SHA_MISMATCH');
  const execution = buildExecution(env, resolve('backend'));
  const directory = resolve(env.RUNNER_TEMP, 'eq-new-source-publication');
  mkdirSync(directory, { recursive: true, mode: 0o700 });
  try {
    const receipt = publish(execution);
    try {
      receipt.online_acceptance = await verifyOnlineCandidates(env.EQ_PUBLISH_ENVIRONMENT, resolve('backend'));
    } catch {
      throw recoveryFailure(execution);
    }
    writeFileSync(resolve(directory, `${env.EQ_PUBLISH_ENVIRONMENT}.json`), `${JSON.stringify(receipt)}\n`, { flag: 'wx', mode: 0o600 });
  } catch (original) {
    const error = original.receipt ? original : recoveryFailure(execution);
    if (error.receipt) writeFileSync(resolve(directory, `${env.EQ_PUBLISH_ENVIRONMENT}.json`), `${JSON.stringify(error.receipt)}\n`, { flag: 'wx', mode: 0o600 });
    throw error;
  }
}
if (import.meta.url === `file://${process.argv[1]}`) {
  cli().catch(() => { process.stderr.write('EQ_NEW_SOURCE_PUBLICATION_FAILED\n'); process.exitCode = 1; });
}
