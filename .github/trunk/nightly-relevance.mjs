import {execFileSync,spawnSync} from 'node:child_process';
import {phpConsumers} from './impact-consumers.mjs';
export function relevantNightlyFailures(failures,sourceSha,candidateSha,root=process.cwd()) {
  if(![sourceSha,candidateSha].every(s=>/^[a-f0-9]{40}$/.test(s??''))||spawnSync('git',['merge-base','--is-ancestor',sourceSha,candidateSha],{cwd:root}).status!==0) throw new Error('NIGHTLY_SOURCE_ANCESTRY_HOLD');
  const changed=execFileSync('git',['diff','--name-only','-z',sourceSha,candidateSha],{cwd:root}).toString().split('\0').filter(Boolean);
  const graph=phpConsumers(root);
  const global=changed.some(p=>/^backend\/(?:composer\.|bootstrap\/|database\/|config\/(?:database|auth|cache)\.php|app\/Http\/Middleware\/)/.test(p));
  return failures.map(item=>{
    const file='backend/'+item.failed_test,body=graph.sources.get(file),deps=graph.closure([file]);
    // Missing/removed classes, security failures, dynamic inputs and unresolved
    // consumers cannot be waived. New failures on the same SHA are related.
    const unknown=!body||deps.size<=1||/app\(\s*\$|(?:base|resource|storage)_path\(\s*\$|require\s*\$/.test(body);
    const required=sourceSha===candidateSha||global||unknown||/(?:Security|Payment|Auth|Permission|Tenant)/.test(file)||changed.some(p=>deps.has(p));
    return {...item,disposition:required?'candidate_revalidation':'independent_nightly_failure',consumer_inputs:[...deps].sort()};
  });
}
