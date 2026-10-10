import {createHash, createHmac} from 'node:crypto';
import {readFileSync, realpathSync, lstatSync} from 'node:fs';
import {resolve} from 'node:path';
import {canonical, isEqOnlyPromotionRegistration} from './eq-new-source-package.mjs';
import {isIqEqTopicOnlyPromotionRegistration, isIqEqTopicOnlyContextFactoryChange} from './iq-eq-topic-package.mjs';

export const packagePath='content_assets/eq_public/candidate/20261010-existing-pages';
export const executorPaths=[
  'app/Console/Commands/ContentPromoteExactPackage.php',
  'app/Services/ContentPromotion/Adapters/EqExistingPublicPagePromotionAdapter.php',
  'app/Services/ContentPromotion/EqExistingPublicPageFrontendRevalidator.php',
  'app/Services/ContentPromotion/EqExistingPublicPagePackage.php',
  'app/Services/ContentPromotion/EqExistingPublicPageState.php',
  'app/Services/ContentPromotion/EqExistingPublicPageWriter.php',
  'app/Services/ContentPromotion/EqPublicRegistryTextPatch.php',
  'app/Services/ContentPromotion/EqSourceExecutionMutex.php',
  'app/Services/ContentPromotion/ExactPackagePromotionService.php',
  'app/Services/ContentPromotion/PromotionContextFactory.php',
  'scripts/deploy/run_eq_existing_public_page_publish.php',
].sort();
const sha=bytes=>createHash('sha256').update(bytes).digest('hex');
export function readCandidateRows(root) {
  const packageData=JSON.parse(readFileSync(resolve(root,packagePath,'assets.json')));
  if(packageData.schema!=='fermatmind.eq_existing_public_pages.v1' || packageData.candidates?.length!==6) throw new Error('EQ_EXISTING_PACKAGE_SCHEMA');
  return packageData.candidates;
}
export function inspectPackage(backendRoot) {
  const root=realpathSync(backendRoot);
  const bytes=path=>{
    if(path.startsWith('/') || path.split('/').includes('..')) throw new Error('EQ_EXISTING_PACKAGE_PATH');
    const file=resolve(root,path);
    const stat=lstatSync(file);
    if(!stat.isFile() || stat.nlink!==1 || realpathSync(file)!==file) throw new Error('EQ_EXISTING_PACKAGE_PATH');
    return readFileSync(file);
  };
  const assets=bytes(`${packagePath}/assets.json`),packageData=JSON.parse(assets),rows=packageData.candidates,seen=new Set(),proofs=new Map();
  if(packageData.schema!=='fermatmind.eq_existing_public_pages.v1' || !Array.isArray(rows) || rows.length!==6) throw new Error('EQ_EXISTING_PACKAGE_SCHEMA');
  const slugs={'EQ-01':'eq-test-emotional-intelligence-assessment','EQ-02':'eq-test-tool-guide','SH-02':'iq-eq-balance-at-work'};
  for(const row of rows) {
    const {locale,org_id:org,slug}=row.identity??{},key=`${row.page_id}:${locale}`;
    if(org!==0 || !['en','zh-CN'].includes(locale) || slug!==slugs[row.page_id] || seen.has(key)) throw new Error('EQ_EXISTING_PACKAGE_SCOPE');
    seen.add(key);
    const fields=['independent_review_input','independent_review_output',...(row.page_id==='SH-02'?['iq_review_input','iq_review_output']:[])];
    for(const field of fields) {
      const proof=row[field];
      if(!proof?.path?.startsWith('content_assets/eq_public/reviews/20261010-existing-pages/') || sha(bytes(proof.path))!==proof.sha256) throw new Error('EQ_EXISTING_PACKAGE_PROOF');
      proofs.set(proof.path,proof.sha256);
    }
  }
  let material=`fermatmind.eq_existing_public_pages.v1\n${sha(assets)}\n`;
  for(const [path,digest] of [...proofs].sort(([a],[b])=>a<b?-1:a>b?1:0)) material+=`${path}\n${digest}\n`;
  return {schema:'eq.existing_public_pages.binding.v1',package_path:packagePath,package_sha256:sha(material),
    executor_release_sha256:sha(executorPaths.map(path=>`${path}\n${sha(bytes(path))}\n`).join('')),
    release_policy_sha256:sha(JSON.stringify(canonical(JSON.parse(bytes('config/content_promotion_release_policy.v2.json'))))),
    lane:'W3',subscope:'EQ-EXISTING-PUBLIC-PAGES',expected_row_count:6};
}
export function workflowSignature(binding,key,source,run,attempt) {
  if(typeof key!=='string' || key.length<32 || !/^[a-f0-9]{40}$/.test(source) || !/^[1-9][0-9]{0,19}$/.test(String(run)) || attempt!==1
    || binding.lane!=='W3' || binding.subscope!=='EQ-EXISTING-PUBLIC-PAGES' || binding.expected_row_count!==6
    || ['package_sha256','release_policy_sha256','executor_release_sha256'].some(field=>!/^[a-f0-9]{64}$/.test(binding[field]??''))) throw new Error('EQ_EXISTING_WORKFLOW_IDENTITY');
  return createHmac('sha256',key).update(['content-promotion-v2',source,run,attempt,binding.lane,binding.subscope,binding.package_sha256,binding.release_policy_sha256,6,binding.executor_release_sha256].join('|')).digest('hex');
}
export function isEqExistingOnlyPromotionRegistration(before,after) {
  const root=`        '${packagePath}',\n`,cap=", 'EQ-EXISTING-PUBLIC-PAGES' => 'audit_compatible'";
  if(after.split(root).length!==2 || after.split(cap).length!==2) return false;
  const roots=after.match(/    'authority_roots' => \[\n([\s\S]*?)    \],/);
  const w3=after.match(/        'W3' => \[[^\n]*\],\n/);
  if(!roots?.[1].includes(root) || !w3?.[0].includes(cap)) return false;
  if(before.includes('EQ-EXISTING-PUBLIC-PAGES') || before.includes(packagePath)) {
    const oldPosition="        'content_assets/eq_public/candidate/20261009-new-articles',\n"+root+"        'content_assets/iq_public/entry/20261009-v1',\n";
    const newPosition="        'content_assets/iq_public/topics/20261010-v1',\n"+root+"        'content_packs',\n";
    return before.split(root).length===2 && before.split(cap).length===2
      && before.includes(oldPosition) && after.includes(newPosition) && before.replace(root,'')===after.replace(root,'');
  }
  const without=after.replace(root,'').replace(cap,'');
  return without===before || isIqEqTopicOnlyPromotionRegistration(before,without) || isEqOnlyPromotionRegistration(before,without);
}
export function isEqExistingOnlyContextFactoryChange(before,after) {
  const block="        if ($lane === 'W3' && $subscope === 'EQ-EXISTING-PUBLIC-PAGES') {\n            $signatureMaterial .= '|'.$executorReleaseSha256;\n        }\n";
  if(after.split(block).length!==2) return false;
  if(!after.includes(`${block}        // The IQ entry executor contract binds its exact implementation bytes.\n`)) return false;
  if(before.includes('EQ-EXISTING-PUBLIC-PAGES')) {
    return before.split(block).length===2 && before.includes(`${block}        if (strlen($workflowIdentityKey) < 32\n`)
      && before.replace(block,'')===after.replace(block,'');
  }
  const without=after.replace(block,'');
  return without===before || isIqEqTopicOnlyContextFactoryChange(before,without);
}
