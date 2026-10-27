import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { assertMbtiResultCopyDom, readMbtiResultCopySample } from './mbti-result-copy-online-qa.mjs';
import { renderPage } from './iq-public-article-online-qa.mjs';
import { inspectPackage, readCandidateRows, selectPublicContentPackage } from './public-content-53-package.mjs';

const root=fileURLToPath(new URL('../../backend',import.meta.url));
const row=JSON.parse(readFileSync(new URL('../../backend/content_assets/public_content_53/mbti-result-copy.json',import.meta.url),'utf8')).candidates[0];
const documentOf=body=>`<html><head><meta name="robots" content="noindex, nofollow"></head><body><main><h1>INTJ-T result</h1>${body}</main></body></html>`;
const html=copy=>documentOf(`<section id="mbti-desktop-growth"><p>${copy}</p><p>${copy}</p></section>`);

test('the exact one-row package is selected by changed source paths and its immutable digest',()=>{
  assert.equal(selectPublicContentPackage(['backend/content_assets/public_content_53/mbti-result-copy.json']),'mbti-result-copy');
  const binding=inspectPackage(root,'mbti-result-copy');
  assert.equal(binding.expected_row_count,1);
  assert.deepEqual(readCandidateRows(root,binding.package_sha256).candidates,[row]);
  assert.equal(binding.lane,'W3');assert.equal(binding.subscope,'PUBLIC-CONTENT-53');
});

test('the real result chapter needs both visible copies; scripts, hidden markup and other chapters cannot supply them',()=>{
  assert.doesNotThrow(()=>assertMbtiResultCopyDom(html(row.snapshot.body_md),row.snapshot.body_md));
  const copy=row.snapshot.body_md;
  const existingPrivateShape=html(copy).replace('</main>','<h1>Existing private heading</h1><h1>Existing print heading</h1></main>');
  assert.equal(assertMbtiResultCopyDom(existingPrivateShape,copy).h1_count,3);
  assert.doesNotThrow(()=>assertMbtiResultCopyDom(html(copy).replace('</main>','<aside hidden><h1>Hidden print copy</h1><h1>Hidden transport copy</h1></aside></main>'),copy));
  for(const body of [
    `<section id="mbti-desktop-growth"><p>${copy}</p></section>`,
    `<section id="mbti-desktop-growth"><script>${copy}${copy}</script></section>`,
    `<section id="mbti-desktop-growth"><div hidden>${copy}${copy}</div></section>`,
    `<section id="mbti-desktop-growth"><div data-iq-qa-hidden="true">${copy}${copy}</div></section>`,
    `<section id="mbti-desktop-growth"></section><p>${copy}${copy}</p>`,
  ])assert.throws(()=>assertMbtiResultCopyDom(documentOf(body),copy),/VISIBLE_BODY_MISMATCH/);
  assert.throws(()=>assertMbtiResultCopyDom(html(copy).replace('noindex, nofollow','index, follow'),copy),/VISIBLE_BODY_MISMATCH/);
});

function fixtures(copy=row.before) {
  const attempt='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
  const token='fm_synthetic_own_sample_only';
  const calls=[];let session;
  const sections=Array.from({length:18},(_,i)=>({key:'control.'+i,title:'Keep '+i,render:'rich_text',body_md:'Retain '+i,payload:null,is_enabled:true,source:'variant'}));
  sections.push({key:'growth.motivators',title:'Motivators',render:'premium_teaser',body_md:copy.body_md,payload:{teaser:copy.teaser,is_premium:true,future:'retain'},is_enabled:true,source:'variant'});
  const fetcher=async(url,options)=>{
    const u=new URL(url);assert.equal(u.origin,'https://staging-api.fermatmind.com');
    assert.equal(options.redirect,'error');assert.match(options.headers['X-Anon-Id'],/^codex_probe_/);
    assert.equal(options.headers['X-FermatMind-Internal-Probe'],'mbti-result-copy');calls.push(u.pathname);
    let data;
    if(u.pathname.endsWith('/scales/MBTI/questions'))data={ok:true,form_code:'mbti_93',locale:'en',questions:{items:Array.from({length:93},(_,i)=>({question_id:'Q'+i,options:'ABCDE'.split('').map(code=>({code}))}))}};
    else if(u.pathname.endsWith('/auth/guest'))data={ok:true,fm_token:token};
    else if(u.pathname.endsWith('/attempts/start'))data={ok:true,attempt_id:attempt};
    else if(u.pathname.endsWith('/attempts/submit')){
      const body=JSON.parse(options.body);assert.equal(body.answers.length,93);assert.equal(body.answers[92].code,'C');data={ok:true};
    }else if(u.pathname.endsWith('/report'))data={ok:true,generating:false,private_answer:'Never return this',mbti_public_projection_v1:{runtime_type_code:'INTJ-T',sections}};
    else throw new Error('Unexpected network target');
    return new Response(JSON.stringify(data),{status:200,headers:{'content-type':'application/json'}});
  };
  const renderer=async(url,options)=>{
    assert.equal(url,'https://staging.fermatmind.com/en/result/'+attempt);assert.equal(options.surface,'mbti_result');
    assert.equal(options.mbtiSample.token,token);session=options.mbtiSample;
    const body=html(copy.body_md);options.verifyDOM(body);return body;
  };
  return {fetcher,renderer,attempt,token,calls,get session(){return session;}};
}

test('a complete synthetic flow returns only source copy, control hashes and sanitized step names',async()=>{
  const f=fixtures();const result=await readMbtiResultCopySample('staging',row,f.fetcher,f.renderer);
  assert.deepEqual(result.copy,row.before);assert.equal(result.variant_section_count,19);assert.equal(result.visible_copy_occurrences,2);
  assert.match(result.controls_sha256,/^[a-f0-9]{64}$/);assert.equal(f.calls.length,5);
  const saved=JSON.stringify(result);
  for(const privateValue of [f.attempt,f.token,f.session.anonId,'Never return this','/en/result/'])assert.equal(saved.includes(privateValue),false);
});

test('the candidate copy retains the same hash of the eighteen unchanged sections and protected target payload',async()=>{
  const a=fixtures(),b=fixtures(row.snapshot);
  const before=await readMbtiResultCopySample('staging',row,a.fetcher,a.renderer);
  const after=await readMbtiResultCopySample('staging',row,b.fetcher,b.renderer);
  assert.equal(after.controls_sha256,before.controls_sha256);
  assert.deepEqual(after.copy,row.snapshot);
});

test('unreviewed reader copy fails before a browser can consume a private session',async()=>{
  const f=fixtures({body_md:'Unreviewed source',teaser:'Unreviewed source'});
  await assert.rejects(readMbtiResultCopySample('staging',row,f.fetcher,()=>{throw new Error('must not render');}),/PRESTATE_DRIFT/);
});

test('synthetic result-session bootstrapping is confined to the exact protected result route',async()=>{
  const mbtiSample={anonId:'codex_probe_aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',token:'fm_synthetic_only'};
  for(const url of ['https://example.com/en/result/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','https://fermatmind.com/en/articles/anything','https://fermatmind.com/en/result/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa?token=unsafe'])
    await assert.rejects(renderPage(url,{surface:'mbti_result',mbtiSample}),/QA_SESSION_INVALID/);
  await assert.rejects(renderPage('https://fermatmind.com/en/articles/anything',{surface:'article',mbtiSample}),/QA_SESSION_INVALID/);
});
