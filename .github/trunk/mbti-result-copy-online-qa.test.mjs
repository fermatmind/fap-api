import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { assertMbtiFourLeafDom, assertMbtiNativeLeafDom, assertMbtiResultCopyDom, readMbtiResultCopySample } from './mbti-result-copy-online-qa.mjs';
import { readOnlinePrestate, verifyOnlineCandidates } from './public-content-53-online-qa.mjs';
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

function fixtures(copy=row.before, mutate=()=>{}, fourCopy=null, nativeCopy=null) {
  const attempt='aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
  const token='fm_synthetic_own_sample_only';
  const calls=[];let session;
  const sections=Array.from({length:18},(_,i)=>({key:'control.'+i,title:'Keep '+i,render:'rich_text',body_md:'Retain '+i,payload:null,is_enabled:true,source:'variant'}));
  sections.push({key:'growth.motivators',title:'Motivators',render:'premium_teaser',body_md:copy.body_md,payload:{teaser:copy.teaser,is_premium:true,future:'retain'},is_enabled:true,source:'variant'});
  sections.at(-1).payload.blocks=[
    {id:'growth.motivators.type_skeleton',kind:'type_skeleton',label:'Type skeleton',text:copy.body_md},
    {id:'growth.motivators.identity.t',kind:'identity',label:'Identity layer',text:'Retain the identity explanation.'},
  ];
  if(fourCopy) {
    sections.splice(0,4,
      {key:'trait_overview',render:'trait_dimension_grid',body_md:fourCopy.trait_overview,payload:{blocks:[{id:'trait_overview.type_skeleton',kind:'type_skeleton',text:fourCopy.trait_overview}]},is_enabled:true,source:'variant'},
      {key:'career.advantages',render:'bullets',payload:{items:[{body:'Keep first'},{body:fourCopy.career_advantage}]},is_enabled:true,source:'variant'},
      {key:'growth.weaknesses',render:'bullets',payload:{items:[{body:fourCopy.growth_weakness}]},is_enabled:true,source:'variant'},
      {key:'faq',render:'faq',payload:{items:[...Array.from({length:5},()=>({answer:'Keep'})),{question:'What stresses INTJ-T?',intent:'stress',answer:fourCopy.stress_answer}]},is_enabled:true,source:'variant'});
  }
  if(nativeCopy) sections[0]={key:'growth.strengths',render:'bullets',payload:{items:[{body:nativeCopy.growth_strength},{body:'Keep second strength'}],future:'retain'},is_enabled:true,source:'variant'};
  mutate(sections);
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
    const body=nativeCopy?documentOf(`<section id="mbti-desktop-growth"><p>${nativeCopy.growth_strength}</p></section>`):fourCopy?fourHtml(fourCopy):html(copy.body_md);options.verifyDOM(body);return body;
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

test('native batch selects 32 exact rows but runs the fixed browser anchor only once per phase',async()=>{
  assert.equal(selectPublicContentPackage(['backend/content_assets/public_content_53/mbti-native-leaf-copy.json']),'mbti-native-leaf-copy');
  const binding=inspectPackage(root,'mbti-native-leaf-copy');
  const rows=readCandidateRows(root,binding.package_sha256).candidates;
  assert.equal(binding.expected_row_count,32);
  assert.equal(rows.reduce((count,row)=>count+Object.keys(row.snapshot).length,0),187);
  const anchor=rows.find(row=>row.identity.slug==='intj-t');
  assert.deepEqual(Object.keys(anchor.snapshot),['growth_strength']);
  const before=fixtures(row.before,()=>{},null,anchor.before);
  const prestate=await readOnlinePrestate('staging',root,before.fetcher,before.renderer,binding.package_sha256);
  assert.equal(before.calls.length,5);
  assert.equal(Object.values(prestate).filter(row=>row.native_adapter_phase_only).length,31);
  const after=fixtures(row.before,()=>{},null,anchor.snapshot);
  const accepted=await verifyOnlineCandidates('staging',root,after.fetcher,after.renderer,prestate,binding.package_sha256);
  assert.equal(after.calls.length,1);
  assert.equal(before.calls.filter(path=>path.endsWith('/attempts/start')).length,1);
  assert.equal(before.calls.filter(path=>path.endsWith('/attempts/submit')).length,1);
  assert.equal(after.calls.filter(path=>path.endsWith('/attempts/start')||path.endsWith('/attempts/submit')).length,0);
  assert.equal(after.calls[0],before.calls.at(-1));
  assert.deepEqual(after.session,before.session);
  assert.deepEqual([accepted.api_readback_count,accepted.dom_readback_count,accepted.native_adapter_phase_only_row_count,accepted.package_row_count],[1,1,31,32]);
  const saved=JSON.stringify(prestate);
  for(const privateValue of [before.token,before.attempt,before.session.anonId])assert.equal(saved.includes(privateValue),false);
});

test('native strength must be visible exactly once in the growth chapter and protects all other fields',async()=>{
  const anchor=readCandidateRows(root,inspectPackage(root,'mbti-native-leaf-copy').package_sha256).candidates.find(row=>row.identity.slug==='intj-t');
  const body=anchor.snapshot.growth_strength;
  for(const snippet of [`<section id="mbti-desktop-growth"></section><p>${body}</p>`,`<section id="mbti-desktop-growth"><p hidden>${body}</p></section>`,`<section id="mbti-desktop-growth"><p>${body}${body}</p></section>`])
    assert.throws(()=>assertMbtiNativeLeafDom(documentOf(snippet),anchor.snapshot),/NATIVE_VISIBLE_MISMATCH/);
  const before=fixtures(row.before,()=>{},null,anchor.before),after=fixtures(row.before,()=>{},null,anchor.snapshot);
  const a=await readMbtiResultCopySample('staging',anchor,before.fetcher,before.renderer);
  const b=await readMbtiResultCopySample('staging',anchor,after.fetcher,after.renderer);
  assert.equal(a.controls_sha256,b.controls_sha256);
  const changed=fixtures(row.before,sections=>{sections[0].payload.items[1].body='Unowned sibling changed';},null,anchor.snapshot);
  const c=await readMbtiResultCopySample('staging',anchor,changed.fetcher,changed.renderer);
  assert.notEqual(c.controls_sha256,a.controls_sha256);
});

test('the candidate copy retains the same hash of the eighteen unchanged sections and protected target payload',async()=>{
  const a=fixtures(),b=fixtures(row.snapshot);
  const before=await readMbtiResultCopySample('staging',row,a.fetcher,a.renderer);
  const after=await readMbtiResultCopySample('staging',row,b.fetcher,b.renderer);
  assert.equal(after.controls_sha256,before.controls_sha256);
  assert.deepEqual(after.copy,row.snapshot);
});

test('the source-derived body cannot hide stale, invented or duplicate skeleton text',async()=>{
  for (const mutate of [
    sections=>{sections.at(-1).payload.blocks[0].text=row.before.body_md;},
    sections=>{sections.at(-1).payload.blocks[0].kind='invented';},
    sections=>{sections.at(-1).payload.blocks.push({...sections.at(-1).payload.blocks[0]});},
    sections=>{sections.at(-1).payload.blocks={text:row.snapshot.body_md};},
  ]) {
    const f=fixtures(row.snapshot,mutate);
    await assert.rejects(readMbtiResultCopySample('staging',row,f.fetcher,()=>{throw new Error('must not render');}),/DERIVED_BODY_MISMATCH/);
  }
});

test('the other eighteen sections, skeleton metadata and other personalized text stay protected',async()=>{
  const a=fixtures();const before=await readMbtiResultCopySample('staging',row,a.fetcher,a.renderer);
  for (const mutate of [
    sections=>{sections[0].body_md='An unrelated edit.';},
    sections=>{sections.at(-1).payload.blocks[0].label='A changed label.';},
    sections=>{sections.at(-1).payload.blocks[1].text='A changed identity explanation.';},
  ]) {
    const f=fixtures(row.snapshot,mutate);const after=await readMbtiResultCopySample('staging',row,f.fetcher,f.renderer);
    assert.notEqual(after.controls_sha256,before.controls_sha256);
  }
});

test('a source without optional personalization blocks still follows the legacy two-copy contract',async()=>{
  const a=fixtures(row.before,sections=>{delete sections.at(-1).payload.blocks;});
  const b=fixtures(row.snapshot,sections=>{delete sections.at(-1).payload.blocks;});
  const before=await readMbtiResultCopySample('staging',row,a.fetcher,a.renderer);
  const after=await readMbtiResultCopySample('staging',row,b.fetcher,b.renderer);
  assert.equal(after.controls_sha256,before.controls_sha256);
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

const fourRow=JSON.parse(readFileSync(new URL('../../backend/content_assets/public_content_53/mbti-four-leaf-copy.json',import.meta.url),'utf8')).candidates[0];
const fourHtml=copy=>documentOf(`<section id="mbti-desktop-traits"><p>${copy.trait_overview}</p></section><section id="mbti-desktop-career"><p>${copy.career_advantage}</p></section><section id="mbti-desktop-growth"><p>${copy.growth_weakness}</p></section><section><p>${copy.stress_answer}</p></section>`);

test('four native leaves select the existing lane with their own immutable package identity',()=>{
  assert.equal(selectPublicContentPackage(['backend/content_assets/public_content_53/mbti-four-leaf-copy.json']),'mbti-four-leaf-copy');
  const binding=inspectPackage(root,'mbti-four-leaf-copy');
  assert.equal(binding.expected_row_count,1);
  assert.deepEqual(readCandidateRows(root,binding.package_sha256).candidates,[fourRow]);
});

test('four-leaf before and after retain all unowned text including the accepted motivators',async()=>{
  const a=fixtures(row.snapshot,()=>{},fourRow.before),b=fixtures(row.snapshot,()=>{},fourRow.snapshot);
  const before=await readMbtiResultCopySample('staging',fourRow,a.fetcher,a.renderer);
  const after=await readMbtiResultCopySample('staging',fourRow,b.fetcher,b.renderer);
  assert.deepEqual(after.copy,fourRow.snapshot);assert.equal(after.visible_copy_occurrences,4);
  assert.equal(before.controls_sha256,after.controls_sha256);
  const changed=fixtures(row.snapshot,sections=>{sections.at(-1).body_md='Changed accepted motivator';},fourRow.snapshot);
  const result=await readMbtiResultCopySample('staging',fourRow,changed.fetcher,changed.renderer);
  assert.notEqual(result.controls_sha256,before.controls_sha256);
  for(const secret of [a.attempt,a.token,a.session.anonId,'Never return this'])assert.equal(JSON.stringify(before).includes(secret),false);
});

test('four-leaf reader rejects another FAQ identity, malformed fields and stale derived text',async()=>{
  for(const [mutate,error] of [
    [sections=>{sections[3].payload.items[5].question='Different question';},/FAQ_IDENTITY_DRIFT/],
    [sections=>{sections[3].payload.items[5].intent='other';},/FAQ_IDENTITY_DRIFT/],
    [sections=>{delete sections[1].payload.items[1].body;},/SECTION_INVALID/],
    [sections=>{sections[0].payload.blocks[0].text='Old skeleton';},/DERIVED_BODY_MISMATCH/],
  ]) {
    const f=fixtures(row.snapshot,mutate,fourRow.snapshot);
    await assert.rejects(readMbtiResultCopySample('staging',fourRow,f.fetcher,()=>{throw new Error('must not render');}),error);
  }
});

test('four-leaf visible acceptance binds each sentence once to its actual result chapter',()=>{
  const body=fourHtml(fourRow.snapshot);assert.doesNotThrow(()=>assertMbtiFourLeafDom(body,fourRow.snapshot));
  for(const changed of [
    body.replace('mbti-desktop-career','another-chapter'),
    body.replace(`<p>${fourRow.snapshot.stress_answer}</p>`,`<p hidden>${fourRow.snapshot.stress_answer}</p>`),
    body.replace('</main>',`<p>${fourRow.snapshot.trait_overview}</p></main>`),
    body.replace('noindex, nofollow','index, follow'),
  ])assert.throws(()=>assertMbtiFourLeafDom(changed,fourRow.snapshot),/FOUR_VISIBLE_MISMATCH/);
});
