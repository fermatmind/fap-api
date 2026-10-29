import { createHash, randomUUID } from 'node:crypto';
import { canonical } from './iq-public-scale-package.mjs';
import { renderPage } from './iq-public-article-online-qa.mjs';

const equal = (a,b) => JSON.stringify(canonical(a)) === JSON.stringify(canonical(b));
const compact = value => String(value ?? '').normalize('NFKC').replace(/[\s\p{P}\p{S}]/gu,'');
const hash = value => createHash('sha256').update(JSON.stringify(canonical(value))).digest('hex');

/** One newly created QA guest only. Private identifiers never leave this function. */
export async function readMbtiResultCopySample(environment, row, fetcher = fetch, renderer = renderPage) {
  if (!['staging','production'].includes(environment) || row.kind !== 'mbti-result'
    || row.identity?.org_id !== 0 || row.identity.locale !== 'en' || row.identity.slug !== 'intj-t')
    throw new Error('MBTI_RESULT_COPY_QA_IDENTITY_INVALID');
  const api = environment === 'staging' ? 'https://staging-api.fermatmind.com/api/v0.3' : 'https://api.fermatmind.com/api/v0.3';
  const web = environment === 'staging' ? 'https://staging.fermatmind.com' : 'https://fermatmind.com';
  const anonId = 'codex_probe_'+randomUUID(); let token = ''; const steps = []; const began = Date.now();
  const request = async (path, body, stage = path) => {
    let response, data;
    try {
      response = await fetcher(api+path,{method:body === undefined?'GET':'POST',redirect:'error',signal:AbortSignal.timeout(15000),
        headers:{Accept:'application/json','Content-Type':'application/json','X-Anon-Id':anonId,'X-FAP-Locale':'en','X-FermatMind-Internal-Probe':'mbti-result-copy',...(token?{Authorization:'Bearer '+token}:{})},
        ...(body === undefined?{}:{body:JSON.stringify(body)})});
      data = await response.json();
    } catch { throw new Error('MBTI_RESULT_COPY_QA_TRANSPORT_FAILED'); }
    steps.push({stage,status:response.status});
    if (![200,202].includes(response.status) || data?.ok !== true) throw new Error('MBTI_RESULT_COPY_QA_API_FAILED');
    return data;
  };
  const bank = await request('/scales/MBTI/questions?form_code=mbti_93&locale=en&region=CN_MAINLAND');
  const questions = bank.questions?.items;
  if (bank.form_code !== 'mbti_93' || bank.locale !== 'en' || !Array.isArray(questions) || questions.length !== 93
    || questions.some(q=>typeof q.question_id !== 'string' || !Array.isArray(q.options) || !'ABCDE'.split('').every(code=>q.options.some(o=>o.code===code))))
    throw new Error('MBTI_RESULT_COPY_QA_BANK_INVALID');
  const guest = await request('/auth/guest',{anon_id:anonId});
  token = [guest.fm_token,guest.token,guest.auth_token,guest.data?.fm_token,guest.data?.token,guest.data?.auth_token].find(x=>typeof x==='string' && /^fm_[A-Za-z0-9._-]+$/.test(x)) ?? '';
  if (!token) throw new Error('MBTI_RESULT_COPY_QA_GUEST_INVALID');
  const started = await request('/attempts/start',{scale_code:'MBTI',form_code:'mbti_93',anon_id:anonId,locale:'en',region:'CN_MAINLAND',channel:'product_qa'});
  const attempt = started.attempt_id;
  if (typeof attempt !== 'string' || !/^[a-f0-9-]{36}$/.test(attempt)) throw new Error('MBTI_RESULT_COPY_QA_ATTEMPT_INVALID');
  await request('/attempts/submit',{attempt_id:attempt,answers:questions.map((q,i)=>({question_id:q.question_id,code:'ABCDE'[i%5]})),duration_ms:Date.now()-began});
  let projection;
  for (let n=0;n<8;n++) {
    const report = await request('/attempts/'+attempt+'/report?locale=en',undefined,'/attempts/OWN_QA_SAMPLE/report');
    projection = report.mbti_public_projection_v1;
    if (projection && !report.generating) break;
    await new Promise(resolve=>setTimeout(resolve,1000));
  }
  const sections = projection?.sections;
  if (projection?.runtime_type_code !== 'INTJ-T' || !Array.isArray(sections)) throw new Error('MBTI_RESULT_COPY_QA_RESULT_INVALID');
  const variants = sections.filter(s=>s.source === 'variant');
  const selected = variants.filter(s=>s.key === 'growth.motivators');
  if (selected.length !== 1 || variants.length < 19 || selected[0].is_enabled !== true || selected[0].render !== 'premium_teaser')
    throw new Error('MBTI_RESULT_COPY_QA_SECTION_INVALID');
  const target = selected[0]; const four = row.page_id === 'MBTI-EN-INTJ-T-FOUR-LEAVES';
  const copy = four ? readFourLeafCopy(variants) : {body_md:target.body_md,teaser:target.payload?.teaser};
  const before = environment === 'staging' ? row.staging_before : row.before;
  if (!equal(copy,before) && !equal(copy,row.snapshot)) throw new Error('MBTI_RESULT_COPY_QA_PRESTATE_DRIFT');
  const controls = variants.map(section=>{
    if (four) {
      const clone=structuredClone(section);
      if (section.key === 'trait_overview') {
        delete clone.body_md;
        protectDerivedBody(clone,copy.trait_overview);
      } else if (section.key === 'career.advantages') delete clone.payload.items[1].body;
      else if (section.key === 'growth.weaknesses') delete clone.payload.items[0].body;
      else if (section.key === 'faq') delete clone.payload.items[5].answer;
      return clone;
    }
    if (section.key !== 'growth.motivators') return section;
    const clone=structuredClone(section); delete clone.body_md; delete clone.payload.teaser;
    // The native personalization service copies this body into its skeleton.
    // Verify that exact derived leaf, then protect every other block field.
    if (clone.payload.blocks !== undefined) {
      if (!Array.isArray(clone.payload.blocks)) throw new Error('MBTI_RESULT_COPY_QA_DERIVED_BODY_MISMATCH');
      const skeletons=clone.payload.blocks.filter(block=>block?.id === 'growth.motivators.type_skeleton');
      if (skeletons.length !== 1 || skeletons[0].kind !== 'type_skeleton' || skeletons[0].text !== copy.body_md)
        throw new Error('MBTI_RESULT_COPY_QA_DERIVED_BODY_MISMATCH');
      delete skeletons[0].text;
    }
    return clone;
  });
  const privateUrl = web+'/en/result/'+attempt;
  const verifyDOM = html => four ? assertMbtiFourLeafDom(html,copy) : assertMbtiResultCopyDom(html,copy.body_md);
  let html;
  try { html = await renderer(privateUrl,{surface:'mbti_result',mbtiSample:{anonId,token},verifyDOM}); }
  catch { throw new Error('MBTI_RESULT_COPY_QA_BROWSER_FAILED'); }
  const dom = verifyDOM(html);
  // No raw report, answer, guest credential, attempt identity or private URL is returned.
  return {runtime_type:'INTJ-T',copy,controls_sha256:hash(controls),variant_section_count:variants.length,visible_copy_occurrences:four?4:2,h1_count:dom.h1_count,headings_sha256:hash(dom.headings),steps};
}

function readFourLeafCopy(variants) {
  const section=(key,render)=>{
    const rows=variants.filter(s=>s.key === key);
    if (rows.length !== 1 || rows[0].render !== render || rows[0].is_enabled !== true) throw new Error('MBTI_RESULT_COPY_QA_SECTION_INVALID');
    return rows[0];
  };
  const trait=section('trait_overview','trait_dimension_grid'),career=section('career.advantages','bullets'),growth=section('growth.weaknesses','bullets');
  const faq=section('faq','faq').payload?.items?.[5];
  if (faq?.question !== 'What stresses INTJ-T?' || faq?.intent !== 'stress') throw new Error('MBTI_RESULT_COPY_QA_FAQ_IDENTITY_DRIFT');
  const copy={trait_overview:trait.body_md,career_advantage:career.payload?.items?.[1]?.body,growth_weakness:growth.payload?.items?.[0]?.body,stress_answer:faq.answer};
  if (Object.values(copy).some(v=>typeof v !== 'string' || !v.trim())) throw new Error('MBTI_RESULT_COPY_QA_SECTION_INVALID');
  return copy;
}

function protectDerivedBody(section, expected) {
  if (section.payload?.blocks === undefined) return;
  if (!Array.isArray(section.payload.blocks)) throw new Error('MBTI_RESULT_COPY_QA_DERIVED_BODY_MISMATCH');
  const skeletons=section.payload.blocks.filter(b=>b?.id === section.key+'.type_skeleton');
  if (skeletons.length !== 1 || skeletons[0].kind !== 'type_skeleton' || skeletons[0].text !== expected)
    throw new Error('MBTI_RESULT_COPY_QA_DERIVED_BODY_MISMATCH');
  delete skeletons[0].text;
}

export function assertMbtiFourLeafDom(html, copy) {
  if (typeof html !== 'string' || !copy || Object.values(copy).length !== 4 || Object.values(copy).some(v=>typeof v !== 'string' || !v.trim()))
    throw new Error('MBTI_RESULT_COPY_QA_DOM_INVALID');
  const value=visibleMainText(html);
  if (!value.growth || !value.noindex || value.main_count !== 1 || value.h1_count < 1) throw new Error('MBTI_RESULT_COPY_QA_FOUR_VISIBLE_MISMATCH');
  const chapters={trait_overview:value.traits,career_advantage:value.career,growth_weakness:value.body,stress_answer:value.main_body};
  for (const [name,text] of Object.entries(copy)) {
    if (compact(value.main_body).split(compact(text)).length-1 !== 1 || compact(chapters[name]).split(compact(text)).length-1 !== 1)
      throw new Error('MBTI_RESULT_COPY_QA_FOUR_VISIBLE_MISMATCH');
  }
  return {h1_count:value.h1_count,headings:value.headings};
}

export function assertMbtiResultCopyDom(html, expected) {
  // The renderer marks computed-hidden subtrees. Read visible text with the
  // same native DOM parser as the existing publication acceptance.
  if (typeof html !== 'string' || typeof expected !== 'string' || !expected.trim()) throw new Error('MBTI_RESULT_COPY_QA_DOM_INVALID');
  const value = visibleMainText(html);
  const occurrences = compact(value.body).split(compact(expected)).length-1;
  if (!value.growth || !value.noindex || value.main_count !== 1 || value.h1_count < 1 || occurrences !== 2)
    throw new Error(`MBTI_RESULT_COPY_QA_VISIBLE_BODY_MISMATCH_MAIN${value.main_count}_H1${value.h1_count}_GROWTH${Number(value.growth)}_NOINDEX${Number(value.noindex)}_COPIES${occurrences}`);
  return {h1_count:value.h1_count,headings:value.headings};
}

import { execFileSync } from 'node:child_process';
function visibleMainText(html) {
  const code = String.raw`
$doc=new DOMDocument;libxml_use_internal_errors(true);if(!$doc->loadHTML(stream_get_contents(STDIN),LIBXML_NONET))throw new RuntimeException('invalid');
$xp=new DOMXPath($doc);$visible=function($node){for($p=$node;$p!==null;$p=$p->parentNode)if($p instanceof DOMElement&&($p->getAttribute('data-iq-qa-hidden')==='true'||$p->hasAttribute('hidden')||$p->getAttribute('aria-hidden')==='true'))return false;return true;};$select=function($query)use($xp,$visible){return array_values(array_filter(iterator_to_array($xp->query($query)),$visible));};$m=$select('//main[not(ancestor::main)]');$g=$select('//main//*[@id="mbti-desktop-growth"]');$h=$select('//main//h1');$noindex=false;foreach($xp->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="robots"]')as$meta){if(preg_match('/(?:^|[,\s])noindex(?:$|[,\s])/i',$meta->getAttribute('content')))$noindex=true;}
$walk=function($node)use(&$walk){if($node instanceof DOMElement&&(in_array($node->tagName,['script','style','template','noscript'])||$node->getAttribute('data-iq-qa-hidden')==='true'||$node->hasAttribute('hidden')||$node->getAttribute('aria-hidden')==='true'))return '';if($node instanceof DOMText)return $node->nodeValue;$s='';foreach($node->childNodes as $c)$s.=' '.$walk($c);return $s;};
echo json_encode(['noindex'=>$noindex,'main_count'=>count($m),'h1_count'=>count($h),'headings'=>array_map($walk,$h),'growth'=>count($g)===1,'body'=>count($g)===1?$walk($g[0]):'','main_body'=>count($m)===1?$walk($m[0]):'','traits'=>implode(' ',array_map($walk,$select('//main//*[@id="mbti-desktop-traits"]'))),'career'=>implode(' ',array_map($walk,$select('//main//*[@id="mbti-desktop-career"]')))],JSON_THROW_ON_ERROR);`;
  try { return JSON.parse(execFileSync('php',['-r',code],{input:html,encoding:'utf8',timeout:15000,maxBuffer:2000000,stdio:['pipe','pipe','pipe']})); }
  catch { throw new Error('MBTI_RESULT_COPY_QA_DOM_INVALID'); }
}
