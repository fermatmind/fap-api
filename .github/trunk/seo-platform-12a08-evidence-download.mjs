import { relevantNightlyFailures } from './nightly-relevance.mjs';
import {parseJUnitNightlyFailures,parseLegacyNightlyFailures} from './seo-platform-12a08-release.mjs';
import { execFileSync } from 'node:child_process';
import { writeFileSync, readFileSync, mkdirSync, existsSync } from 'node:fs';
import { digest, mayCarry, MISSIONS } from './seo-platform-12a08-activation.mjs';
import { verifyState, hasActivationEvidence, assessNightly, completedNightlyFullJob, selectNightlyArtifact, nightlyRevalidationPaths, readNightlyArtifactEvidence } from './seo-platform-12a08-release.mjs';
const repo = process.env.GITHUB_REPOSITORY;
if (repo !== 'fermatmind/fap-api') throw new Error('REPOSITORY_HOLD');
const sha = process.env.DEPLOY_SHA;
const api = path => JSON.parse(execFileSync('gh', ['api', `repos/${repo}/${path}`], {maxBuffer:16*1024*1024}));
const loadNightly = run => {
  const jobs = api(`actions/runs/${run.id}/jobs?per_page=100`).jobs;
  const fullJob = completedNightlyFullJob(jobs);
  if (!fullJob) return null;
  const artifact = selectNightlyArtifact(api(`actions/runs/${run.id}/artifacts?per_page=100`).artifacts, run);
  let evidence;
  if (artifact) {
    const bytes = execFileSync('gh', ['api', `repos/${repo}/actions/artifacts/${artifact.id}/zip`], {maxBuffer:64*1024*1024});
    if (`sha256:${digest(bytes)}` !== artifact.digest) throw new Error('NIGHTLY_ARTIFACT_DIGEST_HOLD');
    const path = `${process.env.RUNNER_TEMP ?? '.'}/a08-nightly-${run.id}.zip`;
    writeFileSync(path, bytes);
    evidence = readNightlyArtifactEvidence(path, artifact.digest);
  } else {
    evidence = {log:fullJob.conclusion === 'success' ? '' : execFileSync('gh', ['api', '--allow-escape-sequences', `repos/${repo}/actions/jobs/${fullJob.id}/logs`], {maxBuffer:32*1024*1024}).toString()};
  }
  if (fullJob.conclusion === 'failure') {
    const failures = evidence.junit !== undefined ? parseJUnitNightlyFailures(evidence.junit) : parseLegacyNightlyFailures(evidence.log);
    evidence.relevance = relevantNightlyFailures(failures,run.head_sha,process.env.GITHUB_SHA === sha || !sha ? process.env.GITHUB_SHA : sha);
  }
  return {jobs, evidence, source:{run_id:run.id, sha:run.head_sha, artifact_digest:artifact?.digest ?? null}};
};
if (process.argv[2] === '--nightly-revalidation-paths') {
  const runs = api('actions/workflows/nightly.yml/runs?status=completed&per_page=100').workflow_runs;
  let paths = [], source = null;
  for (const run of runs) {
    const loaded = loadNightly(run);
    if (!loaded) continue;
    const available = execFileSync('git', ['ls-files', 'tests'], {encoding:'utf8'}).trim().split('\n');
    paths = nightlyRevalidationPaths(run, loaded.jobs, loaded.evidence, available, process.env.GITHUB_SHA);
    source = loaded.source;
    break;
  }
  writeFileSync(process.argv[3], paths.length ? paths.join('\n')+'\n' : '');
  writeFileSync(process.argv[4], JSON.stringify(source)+'\n');
  process.exit(0);
}
const ci = api(`actions/runs/${process.env.CI_RUN_ID}`);
const jobs = api(`actions/runs/${process.env.GITHUB_RUN_ID}/jobs?per_page=100`).jobs;
const artifactDigests = {};
for (const [kind,run,name] of [['checks',ci.id,`a08-scoped-checks-${sha}`],['staging',process.env.GITHUB_RUN_ID,`trunk-staging-${sha}`],['production',process.env.GITHUB_RUN_ID,`trunk-production-${sha}`]]) {
  const artifacts = api(`actions/runs/${run}/artifacts?per_page=100`).artifacts.filter(a => a.name === name && !a.expired);
  if (kind === 'checks' && artifacts.length === 0) { artifactDigests.checks = null; continue; }
  if (artifacts.length !== 1 || !/^sha256:[a-f0-9]{64}$/.test(artifacts[0].digest)) throw new Error('ARTIFACT_BINDING_HOLD');
  const bytes = execFileSync('gh',['api',`repos/${repo}/actions/artifacts/${artifacts[0].id}/zip`],{maxBuffer:16*1024*1024});
  if (`sha256:${digest(bytes)}` !== artifacts[0].digest) throw new Error('ARTIFACT_DIGEST_HOLD');
  artifactDigests[kind] = artifacts[0].digest;
  writeFileSync(`${kind}.zip`,bytes);mkdirSync(kind,{recursive:true});
  execFileSync('unzip',['-q',`${kind}.zip`,'-d',kind]);
}
const read = path => JSON.parse(readFileSync(path,'utf8'));
const staging = read('staging/a08-staging-after.json');
const production = read('production/a08-production-after.json');
const safety=read('staging/a08-staging-safety.json');
if (safety.sha !== sha || safety.environment !== 'staging' || (safety.state !== 'OPERATOR_STATE_CHANGED'
  && (safety.pause_resume_verified !== true || safety.shared_cache_contention_verified !== true
    || safety.transaction_rollback_verified !== true || safety.fencing_verified !== true))) throw new Error('A08_STAGING_SAFETY_HOLD');
production.activation = read('production/a08-production-before.json').activation;
verifyState(read('staging/a08-staging-before.json'),staging,sha);
verifyState(read('production/a08-production-before.json'),production,sha);
const ciArtifact = api(`actions/runs/${ci.id}/artifacts?per_page=100`).artifacts.find(a=>a.name===`trunk-validation-${sha}` && !a.expired);
if (!ciArtifact || !/^sha256:[a-f0-9]{64}$/.test(ciArtifact.digest)) throw new Error('CI_ARTIFACT_HOLD');
artifactDigests.ci = ciArtifact.digest;
const checks = artifactDigests.checks ? read('checks/a08-scoped-checks.json') : null;
const activationEvidence = hasActivationEvidence(checks, production);
const nightlyRuns = activationEvidence ? api('actions/workflows/nightly.yml/runs?status=completed&per_page=100').workflow_runs : [];
let nightly = null;
for (const run of nightlyRuns) {
  const loaded = loadNightly(run);
  if (!loaded) continue;
  if (checks?.nightly_source && (run.status !== 'completed'
    || JSON.stringify(loaded.source) !== JSON.stringify(checks.nightly_source))) throw new Error('NIGHTLY_ARTIFACT_BINDING_HOLD');
  const {jobs:nightlyJobs, evidence} = loaded;
  if (!checks && production.activation?.validation?.nightly_assessment?.run_id === run.id
    && MISSIONS.every(id=>mayCarry(production.activation,{production_sha:sha,version_vector:production.version_vector},id))) {
    nightly={...production.activation.validation.nightly_assessment,candidate_sha:sha,compatible_source_sha:production.activation.bound_production_sha};
  } else { nightly = assessNightly(run,nightlyJobs,evidence,checks); }
  break;
}
if (!nightly && activationEvidence && checks?.nightly_source) throw new Error('NIGHTLY_ARTIFACT_BINDING_HOLD');
if (!nightly && activationEvidence) nightly = {status:'unavailable',disposition:'CURRENT_CANDIDATE_SCOPED_CHECKS_ONLY',candidate_sha:sha};
const sources = Object.fromEntries(MISSIONS.map((id,index)=>[id,existsSync(`production/a08-production-sources/source-${index}.json`) ? read(`production/a08-production-sources/source-${index}.json`) : null]));
writeFileSync('a08-release-input.json',JSON.stringify({stagingSafety:safety,sources,nightly,checks:artifactDigests.checks ? read('checks/a08-scoped-checks.json') : null,sha,ci,jobs,staging,production,artifactDigests}));
