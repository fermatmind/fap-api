import { readFileSync, writeFileSync } from 'node:fs';
import { MISSIONS, digest, fingerprint, mayCarry } from './seo-platform-12a08-activation.mjs';
const read = path => JSON.parse(readFileSync(path, 'utf8'));
export function verifyState(before, after, sha) {
  if (after.sha !== sha || before.paused !== after.paused
    || before.generation !== after.generation
    || JSON.stringify(before.selected_missions) !== JSON.stringify(after.selected_missions)
    || (after.gate_only && (after.paused !== true || JSON.stringify(after.selected_missions) !== '[]' || JSON.stringify(before.counts) !== JSON.stringify(after.counts)))
    || !after.business_guards_closed || !after.operations_readonly) throw new Error('A08_STATE_PRESERVATION_HOLD');
  return true;
}
export function build({checks, sha, ci, jobs, staging, production, artifactDigests, nightly}) {
  const previous = production.activation;
  if (!checks && previous?.schema_version === 'seo.platform12_a08_activation.v2') {
    if (!MISSIONS.every(id => mayCarry(previous,{production_sha:sha,version_vector:production.version_vector},id))) throw new Error('A08_FOCUSED_REVALIDATION_REQUIRED');
    const rebind = check => ({...check, validated_sha:check.validated_sha ?? check.sha, sha, ancestor_verified:true});
    checks = {sha,check_scope:'a08_scoped_checks',checks:{public:rebind(previous.validation.public_checks),...Object.fromEntries(MISSIONS.map(id=>[id,rebind(previous.missions[id].checks)]))}};
  }
  if (!checks) return null;
  if (checks.sha !== sha || checks.check_scope !== 'a08_scoped_checks'
    || ci.head_sha !== sha || ci.conclusion !== 'success' || ci.status !== 'completed'
    || ci.event !== 'push' || ci.head_branch !== 'main') throw new Error('A08_CI_BINDING_HOLD');
  const prints = fingerprint(process.cwd(), sha);
  for (const id of ['public', ...MISSIONS]) {
    if (checks.checks[id]?.sha !== sha || checks.checks[id]?.status !== 'pass'
      || checks.checks[id]?.fingerprint !== prints[id]) throw new Error('A08_FINGERPRINT_HOLD');
  }
  const validation = {nightly_assessment:nightly,public_checks: checks.checks.public,
    ci: {repository: 'fermatmind/fap-api', workflow_name: 'CI', workflow_path: '.github/workflows/ci.yml',
      head_branch: 'main', event: 'push', sha, status: 'success', run_id: ci.id, run_attempt: ci.run_attempt,
      artifact_digest: artifactDigests.ci}};
  for (const [environment, state] of Object.entries({staging, production})) {
    const name = environment === 'staging' ? 'Staging exact-SHA deploy and smoke' : 'Production exact-SHA activation, smoke, and LKG fallback';
    const job = jobs.find(job => job.name === name);
    if (!job || job.status !== 'completed' || job.conclusion !== 'success' || !job.completed_at
      || state.sha !== sha || !state.business_guards_closed || !state.operations_readonly) throw new Error('A08_DEPLOY_JOB_HOLD');
    validation[environment] = {sha, environment, check_scope: 'deployment_smoke_and_readonly_state', status: 'pass',
      completed_job: true, run_id: job.run_id, job_id: job.id, completed_at: job.completed_at,
      artifact_digest: artifactDigests[environment], pause_preserved: true, business_guards_closed: true};
  }
  return {schema_version:'seo.platform12_a08_activation.v2', repository:'fermatmind/fap-api', bound_production_sha:sha,
    validation, missions: Object.fromEntries(MISSIONS.map(id => [id, {checks: checks.checks[id], source_acceptance: previous?.missions?.[id]?.source_acceptance?.status === 'pass' && mayCarry(previous,{production_sha:sha,version_vector:production.version_vector},id)
      ? {...previous.missions[id].source_acceptance,bound_sha:sha,ancestor_verified:true}
      : {status:'pending',reason:'REAL_SOURCE_ACCEPTANCE_NOT_RUN'}}])),
    runtime:{version_vector:production.version_vector,version_vector_hash:production.version_vector_hash},
    permissions:Object.fromEntries(['model_calls','tool_broker','cms_writes','publish_writes','canonical_writes','robots_writes','url_truth_writes','search_submission','business_writes'].map(key => [key,false])),
    measurement:{day_28_started:false,efficiency_claim_allowed:false}};
}
if (process.argv[2] === 'verify-state') verifyState(read(process.argv[3]), read(process.argv[4]), process.argv[5]);
if (process.argv[2] === 'build') {
  const manifest = build(read(process.argv[3])); if (!manifest) { console.log('A08_EVIDENCE_UNAVAILABLE_NO_AUTHORIZATION'); process.exit(0); } const bytes = JSON.stringify(manifest)+'\n';
  writeFileSync('activation.json',bytes);writeFileSync('activation.json.sha256',digest(bytes)+'\n');
}

export function assessNightly(run, jobs, log, checks) {
  const failed = jobs.filter(job=>job.conclusion==='failure' && job.name !== 'Final failure-domain receipt');
  if (!failed.length) return {run_id:run.id,sha:run.head_sha,check_scope:'weekly_full_checks',status:'pass',disposition:'INDEPENDENT_HEALTH_CHECK'};
  if (failed.some(job=>job.name !== 'Full PHPUnit regression and performance contracts')) throw new Error('NIGHTLY_HIGH_RISK_FOCUSED_REVALIDATION_REQUIRED');
  const failures = log.split(/\bFAILED  /).slice(1);
  if (!failures.length) throw new Error('NIGHTLY_FAILURE_RELEVANCE_UNKNOWN');
  const covered = checks?.covered_classes ?? [];
  const revalidated = failures.map(block=>{
    const path = / at (tests\/[A-Za-z0-9_/]+\.php):/.exec(block)?.[1];
    if (!path) throw new Error('NIGHTLY_FAILURE_RELEVANCE_UNKNOWN');
    let focused = path.split('/').at(-1).replace('.php','');
    if ((path === 'tests/Feature/SeoIntel/SeoPlatform09ScheduledCloseoutTest.php' && block.includes('Not to contain: runInBackground()'))
      || (path === 'tests/Feature/SeoIntel/SeoPlatform10ProductionCloseoutContractTest.php' && block.includes("To contain: after('guard:no-pending-seo-intel-migrations', 'seo:platform-10-material-backfill')"))) focused='SeoPlatform12A08LegacyScheduleContractTest';
    return {failed_test:path,focused_test:focused};
  });
  if (!revalidated.every(item=>covered.some(name=>name.endsWith(item.focused_test)))) throw new Error('NIGHTLY_FAILURE_RELEVANCE_UNKNOWN');
  return {run_id:run.id,sha:run.head_sha,check_scope:'weekly_full_checks',status:'failure',
    disposition:'CURRENT_CANDIDATE_FOCUSED_REVALIDATION',candidate_sha:checks.sha,revalidated};
}
