import { execFileSync } from 'node:child_process';
import { writeFileSync, readFileSync, mkdirSync } from 'node:fs';
import { digest, mayCarry, MISSIONS } from './seo-platform-12a08-activation.mjs';
import { verifyState, assessNightly } from './seo-platform-12a08-release.mjs';
const repo = process.env.GITHUB_REPOSITORY;
if (repo !== 'fermatmind/fap-api') throw new Error('REPOSITORY_HOLD');
const sha = process.env.DEPLOY_SHA;
const api = path => JSON.parse(execFileSync('gh', ['api', `repos/${repo}/${path}`], {maxBuffer:16*1024*1024}));
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
production.activation = read('production/a08-production-before.json').activation;
verifyState(read('staging/a08-staging-before.json'),staging,sha);
verifyState(read('production/a08-production-before.json'),production,sha);
const ciArtifact = api(`actions/runs/${ci.id}/artifacts?per_page=100`).artifacts.find(a=>a.name===`trunk-validation-${sha}` && !a.expired);
if (!ciArtifact || !/^sha256:[a-f0-9]{64}$/.test(ciArtifact.digest)) throw new Error('CI_ARTIFACT_HOLD');
artifactDigests.ci = ciArtifact.digest;
const checks = artifactDigests.checks ? read('checks/a08-scoped-checks.json') : null;
const nightlyRuns = api('actions/workflows/nightly.yml/runs?status=completed&per_page=10').workflow_runs;
let nightly = null;
for (const run of nightlyRuns) {
  const nightlyJobs = api(`actions/runs/${run.id}/jobs?per_page=100`).jobs;
  if (!nightlyJobs.some(job=>job.name==='Full PHPUnit regression and performance contracts' && job.conclusion!=='skipped')) continue;
  const log = run.conclusion==='success' ? '' : execFileSync('gh',['run','view',String(run.id),'--log-failed'],{maxBuffer:32*1024*1024}).toString();
  if (!checks && production.activation?.validation?.nightly_assessment?.run_id === run.id
    && MISSIONS.every(id=>mayCarry(production.activation,{production_sha:sha,version_vector:production.version_vector},id))) {
    nightly={...production.activation.validation.nightly_assessment,candidate_sha:sha,compatible_source_sha:production.activation.bound_production_sha};
  } else { nightly = assessNightly(run,nightlyJobs,log,checks); }
  break;
}
if (!nightly) nightly = {status:'unavailable',disposition:'CURRENT_CANDIDATE_SCOPED_CHECKS_ONLY',candidate_sha:sha};
writeFileSync('a08-release-input.json',JSON.stringify({nightly,checks:artifactDigests.checks ? read('checks/a08-scoped-checks.json') : null,sha,ci,jobs,staging,production,artifactDigests}));
