import {createHash,createHmac} from 'node:crypto';
import {readFileSync,mkdirSync,writeFileSync} from 'node:fs';
import {resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {execFileSync,spawnSync} from 'node:child_process';
import {isDeepStrictEqual} from 'node:util';
import {canonical} from './iq-public-scale-package.mjs';
import {read,assertDom,domOf} from './public-content-53-online-qa.mjs';
import {renderPage} from './iq-public-article-online-qa.mjs';

export const packagePath='content_assets/science_pages/reviewed-existing/20261108-item-design-notes.json';
export const packageSha='ca8194ac16d6adf795fedee16444fff637bd846b448b90053ab8fc5280edb88b';
export const transportTimeout=180000;
const hash=b=>createHash('sha256').update(b).digest('hex');
const equal=(a,b)=>isDeepStrictEqual(a,b);
const quote=x=>`'${String(x).replaceAll("'","'\\''")}'`;
export function candidate(root='backend') {
  const bytes=readFileSync(resolve(root,packagePath));
  if(hash(bytes)!==packageSha) throw Error('SCIENCE_CANDIDATE_HASH_INVALID');
  const data=JSON.parse(bytes);
  if(data.candidates.length!==2 || data.candidates.map(r=>r.identity.locale).sort().join('|')!=='en|zh-CN'
    || data.candidates.some(r=>r.identity.org_id!==0 || r.identity.slug!=='item-design-notes')) throw Error('SCIENCE_CANDIDATE_SCOPE_INVALID');
  return data;
}
export function execution(env) {
  if(!['staging','production'].includes(env.SCIENCE_PUBLISH_ENVIRONMENT)
    || !/^\/[a-zA-Z0-9_./-]+$/.test(env.DEPLOY_PATH??'') || !/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/.test(env.DEPLOY_USER??'')
    || !/^[a-zA-Z0-9][a-zA-Z0-9.:-]*$/.test(env.DEPLOY_HOST??'')
    || !/^[1-9][0-9]{0,4}$/.test(env.DEPLOY_PORT??'') || Number(env.DEPLOY_PORT)>65535
    || !/^[a-f0-9]{40}$/.test(env.DEPLOY_SHA??'') || !/^[1-9][0-9]*$/.test(env.GITHUB_RUN_ID??'')
    || env.GITHUB_RUN_ATTEMPT!=='1' || (env.CONTENT_PROMOTION_AUTOMATION_KEY??'').length<32) throw Error('SCIENCE_WORKFLOW_INVALID');
  const binding={source_commit:env.DEPLOY_SHA,workflow_run_id:env.GITHUB_RUN_ID,workflow_run_attempt:1,
    environment:env.SCIENCE_PUBLISH_ENVIRONMENT,package_sha256:packageSha};
  const signature=createHmac('sha256',env.CONTENT_PROMOTION_AUTOMATION_KEY)
    .update('reviewed-science-pages-v1|'+JSON.stringify(canonical(binding))).digest('hex');
  const args=['-o','BatchMode=yes','-o','StrictHostKeyChecking=yes','-o','ConnectTimeout=10','-o','ConnectionAttempts=1'];
  const identity=env.SCIENCE_PUBLISH_ENVIRONMENT==='staging'?env.DEPLOY_IDENTITY_FILE_STG:env.DEPLOY_IDENTITY_FILE_PROD;
  if(identity) args.push('-o','IdentitiesOnly=yes','-i',identity);
  args.push('-p',env.DEPLOY_PORT,`${env.DEPLOY_USER}@${env.DEPLOY_HOST}`,
    `sudo -n -u www-data -- php ${quote(env.DEPLOY_PATH+'/current/backend/scripts/deploy/run_reviewed_science_pages.php')}`);
  return {binding,signature,args};
}
export function invoke(ex,mode,run=spawnSync) {
  const out=run('ssh',ex.args,{input:JSON.stringify({binding:ex.binding,signature:ex.signature,mode}),encoding:'utf8',
    timeout:transportTimeout,maxBuffer:262144,stdio:['pipe','pipe','pipe']});
  let value;
  try {value=JSON.parse(out.stdout??'');}
  catch {
    const denied=/not allowed to execute|a password is required|not in the sudoers/i.test(out.stderr??'');
    throw Error(denied?'SCIENCE_TRANSPORT_SUDO_DENIED':out.error?.code==='ETIMEDOUT'?'SCIENCE_TRANSPORT_TIMEOUT':'SCIENCE_TRANSPORT_RESPONSE_INVALID');
  }
  if(out.status!==0 || value.ok!==true || value.schema!=='science.reviewed_pages.delivery.v1'
    || value.mode!==mode || !equal(value.binding,ex.binding) || value.sanitized!==true
    || (mode==='publish' ? value.published_count!==2 : !((value.restored===true && value.recovery_status==='restored') || (value.restored===false && value.recovery_status==='not_required'))))
    {
      const error=Error(/^(?:science_|existing_science_)[a-z0-9_:]{1,140}$/.test(value.error_code??'')?value.error_code:'SCIENCE_NATIVE_OPERATION_FAILED');
      error.bindingDiagnostics=sanitizeBindingDiagnostics(value.binding_diagnostics);
      throw error;
    }
  return value;
}
export function sanitizeBindingDiagnostics(value) {
  if(value?.capture!=='after_failure_recovery' || !value.pages || typeof value.pages!=='object') return undefined;
  const pages={};
  const booleanFields=['page_present','working_equals_published','revision_identity_valid','source_id_present','source_found_in_org0',
    'source_topology_valid','source_status_is_source','source_published','source_public','source_slug_matches','source_locale_matches',
    'source_group_matches','source_hash_present','revision_source_id_matches','revision_source_locale_matches','provenance_hash_matches'];
  for(const locale of ['zh-CN','en']) {
    const row=value.pages[locale];if(!row || typeof row!=='object' || Array.isArray(row)) continue;
    const result={};
    for(const key of booleanFields) if(typeof row[key]==='boolean' || row[key]==='not_evaluated') result[key]=row[key];
    if(['source','linked_translation','unlinked_translation','invalid'].includes(row.topology)) result.topology=row.topology;
    if(['source','published','draft','machine_draft','human_review','approved','stale','archived','other'].includes(row.translation_status)) result.translation_status=row.translation_status;
    pages[locale]=result;
  }
  return {capture:'after_failure_recovery',pages};
}
const hosts=environment=>environment==='staging'
  ? ['https://staging-api.fermatmind.com','https://staging.fermatmind.com']
  : ['https://api.fermatmind.com','https://fermatmind.com'];
export async function prestate(environment,data,fetcher=fetch) {
  const [api,web]=hosts(environment), states=[];
  for(const row of data.candidates) {
    const locale=row.identity.locale, path=`/${locale==='en'?'en':'zh'}/item-design-notes`;
    const url=`${api}/api/v0.5/content-pages/item-design-notes?locale=${locale}&org_id=0`;
    const payload=JSON.parse(await read(url,fetcher));const page=payload.page;
    const baseline=environment==='staging'?data.staging_baselines[locale]:row;
    if(payload.ok!==true || page?.slug!=='item-design-notes' || page.locale!==locale || page.status!=='published' || page.is_public!==true
      || !((hash(page.content_md)===baseline.before_body_sha256 && hash(JSON.stringify(canonical(page.faq_items)))===baseline.before_faq_sha256)
        || (page.content_md===row.content_md && equal(page.faq_items,row.faq_items)))) throw Error('SCIENCE_PUBLIC_PRESTATE_DRIFT');
    const dom=domOf(await read(web+path,fetcher));
    states.push({row,page,dom,path,url,web});
  }
  return states;
}
export function assertApi(expected,payload) {
  if(payload.ok!==true || !payload.page) throw Error('SCIENCE_PUBLIC_READBACK_INVALID');
  for(const [key,value] of Object.entries(expected)) {
    if(['updated_at','last_reviewed_at'].includes(key)) continue;
    if(!equal(payload.page[key],value)) throw Error('SCIENCE_PUBLIC_READBACK_DRIFT');
  }
}
export async function verify(states,environment,recover=false,fetcher=fetch,renderer=renderPage) {
  const deadline=Date.now()+360000;
  while(true) {
    try {
      for(const state of states) {
        const expected=recover?state.page:{...state.page,content_md:state.row.content_md,faq_items:state.row.faq_items};
        const payload=JSON.parse(await read(state.url,fetcher));
        assertApi(expected,payload);
        const row={kind:'page',page_id:state.row.identity.locale,identity:state.row.identity,before:state.page,snapshot:expected};
        const verifyDOM=html=>assertDom(row,html,{dom:state.dom},environment);
        verifyDOM(await renderer(state.web+state.path,{surface:'public_page',verifyDOM}));
      }
      return {api_count:2,html_count:2,seo_count:2};
    } catch {
      if(Date.now()>=deadline) throw Error('SCIENCE_PUBLIC_ACCEPTANCE_FAILED');
      await new Promise(done=>setTimeout(done,10000));
    }
  }
}
async function cli() {
  const env=process.env;let ex,states,started=false;
  const receipt={failure_phase:'prestate',transport_started:false,schema:'science.reviewed_pages.delivery.v1',ok:false,sanitized:true,binding:{source_commit:env.DEPLOY_SHA,workflow_run_id:env.GITHUB_RUN_ID,workflow_run_attempt:1,environment:env.SCIENCE_PUBLISH_ENVIRONMENT,package_sha256:packageSha}};
  const directory=resolve(env.RUNNER_TEMP,'reviewed-science-pages');mkdirSync(directory,{recursive:true,mode:0o700});
  try {
    if(execFileSync('git',['rev-parse','HEAD'],{encoding:'utf8'}).trim()!==env.DEPLOY_SHA) throw Error('SCIENCE_CHECKOUT_MISMATCH');
    const data=candidate();ex=execution(env);receipt.binding=ex.binding;
    states=await prestate(env.SCIENCE_PUBLISH_ENVIRONMENT,data);
    started=true;receipt.transport_started=true;receipt.failure_phase='native_publish';invoke(ex,'publish');
    receipt.failure_phase='online_acceptance';
    receipt.acceptance=await verify(states,ex.binding.environment);receipt.ok=true;receipt.published_count=2;
  } catch(error) {
    receipt.failure_code=/^(?:SCIENCE_|PUBLIC_CONTENT_53_|science_|existing_science_)[A-Za-z0-9_:.-]{1,140}$/.test(error.message??'')?error.message:'SCIENCE_DELIVERY_FAILED';
    if(error.bindingDiagnostics) receipt.binding_diagnostics=error.bindingDiagnostics;
    receipt.recovery_completed=!started;
    if(started) {
      try {invoke(ex,'recover');await verify(states,ex.binding.environment,true);receipt.recovery_completed=true;}
      catch {receipt.recovery_completed=false;}
    }
  }
  writeFileSync(resolve(directory,`${env.SCIENCE_PUBLISH_ENVIRONMENT}.json`),JSON.stringify(receipt)+'\n',{flag:'wx',mode:0o600});
  if(!receipt.ok) throw Error('SCIENCE_DELIVERY_FAILED');
}
if(process.argv[1] && fileURLToPath(import.meta.url)===resolve(process.argv[1])) cli().catch(()=>{process.stderr.write('SCIENCE_DELIVERY_FAILED\n');process.exitCode=1;});
