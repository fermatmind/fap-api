import { readFileSync, existsSync, mkdtempSync, rmSync } from 'node:fs';
import { spawn } from 'node:child_process';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { packagePath } from './eq-new-source-package.mjs';

const decode = text => text.replace(/&#(x[0-9a-f]+|[0-9]+);/gi, (_, value) => String.fromCodePoint(value[0].toLowerCase() === 'x' ? parseInt(value.slice(1),16) : Number(value)))
  .replaceAll('&amp;','&').replaceAll('&quot;','"').replaceAll('&#39;',"'").replaceAll('&lt;','<').replaceAll('&gt;','>').replaceAll('&nbsp;',' ');
const plain = text => decode(text).replace(/\s+/gu,' ').trim();
export function expectedVisibleBody(markdown) {
  return markdown.split('\n').filter(line => line.trim() && !/^[\s|:-]+$/.test(line))
    .map(line => line.replace(/^\s*(?:#{1,6}\s+|[-*+]\s+|[0-9]+[.)]\s+)/,'')
      .replace(/\[([^\]]+)\]\([^)]+\)/g,'$1').replace(/\*\*|__|`/g,'')
      .replace(/\*([^*]+)\*/g,'$1').replace(/\|/g,' ')).join(' ').replace(/\s/gu,'');
}
export function assertSsrCandidate(document, row) {
  if (!document || typeof document !== 'object' || document.headings?.length !== 1
    || plain(document.headings[0]) !== plain(row.snapshot.title)) throw new Error('EQ_SSR_TITLE_MISMATCH');
  const title=row.snapshot.seo_title.replace(/(?:\s*\|\s*FermatMind)+$/i,'').trim()+' | FermatMind';
  if(document.title!==title) throw new Error('EQ_SSR_SEO_TITLE_MISMATCH');
  if(document.description!==row.snapshot.seo_description) throw new Error('EQ_SSR_METADATA_MISMATCH');
  if(!document.articleVisible || document.body.replace(/\s/gu,'') !== expectedVisibleBody(row.snapshot.content_md)) throw new Error('EQ_SSR_BODY_MISMATCH');
}
// This function runs inside the actual browser, where external CSS and layout exist.
async function browserVisibleDocument() {
  const start=Date.now();
  while ((document.readyState!=='complete' || !document.querySelector('article[data-testid="article-detail-content"]')) && Date.now()-start<12000) await new Promise(resolve=>setTimeout(resolve,100));
  await new Promise(resolve=>setTimeout(resolve,500));
  const visible = element => {
    for(let node=element;node instanceof Element;node=node.parentElement) {
      const style=getComputedStyle(node);
      if(node.hidden || node.getAttribute('aria-hidden')==='true' || style.display==='none'
        || ['hidden','collapse'].includes(style.visibility) || Number(style.opacity)===0 || Number.parseFloat(style.fontSize)===0
        || style.clip!=='auto' || style.clipPath!=='none') return false;
    }
    return [...element.getClientRects()].some(rect=>rect.width>0 && rect.height>0);
  };
  const unclipped = (rect, element) => {
    if(rect.width<=0 || rect.height<=0) return false;
    for(let node=element;node instanceof Element;node=node.parentElement) {
      const style=getComputedStyle(node),clip=node.getBoundingClientRect();
      if(['hidden','clip'].includes(style.overflowX) && (rect.left<clip.left-0.5 || rect.right>clip.right+0.5)) return false;
      if(['hidden','clip'].includes(style.overflowY) && (rect.top<clip.top-0.5 || rect.bottom>clip.bottom+0.5)) return false;
    }
    return true;
  };
  const text = element => {
    if(!element || !visible(element)) return '';
    const walker=document.createTreeWalker(element,NodeFilter.SHOW_TEXT);const parts=[];
    while(walker.nextNode()) {
      const node=walker.currentNode;
      if(node.parentElement.closest('script,style') || !visible(node.parentElement)) continue;
      const range=document.createRange();range.selectNodeContents(node);
      const rects=[...range.getClientRects()].filter(rect=>rect.width>0 && rect.height>0);
      if(rects.length && rects.every(rect=>unclipped(rect,node.parentElement))) parts.push(node.textContent);
    }
    return parts.join('');
  };
  const articles=[...document.querySelectorAll('article[data-testid="article-detail-content"]')];
  return {title:document.title,description:document.querySelector('meta[name="description"]')?.content ?? '',
    headings:[...document.querySelectorAll('h1')].filter(visible).map(text),
    articleVisible:articles.length===1 && visible(articles[0]),body:articles.length===1 ? text(articles[0]) : ''};
}
// Close only the browser started by this invocation; profile deletion requires exit.
export async function closeOwnedBrowser(child, close, profile) {
  const exited=()=>child.exitCode!==null || child.signalCode!==null;
  const waitExit=async(milliseconds)=>{
    if(exited()) return true;
    return await new Promise(resolve=>{
      const finish=()=>{clearTimeout(timer);child.off('exit',onExit);resolve(exited());};
      const onExit=()=>finish();
      const timer=setTimeout(finish,milliseconds);child.once('exit',onExit);
    });
  };
  if(!exited()) {
    let timer;
    try {await Promise.race([close(),new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('close timeout')),2000);})]);}
    catch { /* Exit is checked below, including a browser that rejected close. */ }
    finally {clearTimeout(timer);}
    if(!await waitExit(1500)) {
      child.kill('SIGTERM');
      if(!await waitExit(1500)) {
        child.kill('SIGKILL');
        if(!await waitExit(1500)) throw new Error('EQ_BROWSER_EXIT_UNCONFIRMED');
      }
    }
  }
  rmSync(profile,{recursive:true,force:true});
}
export async function renderedDocument(url) {
  const browser=['/usr/bin/google-chrome','/usr/bin/chromium','/usr/bin/chromium-browser','/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find(path=>existsSync(path));
  if (!browser) throw new Error('EQ_BROWSER_UNAVAILABLE');
  const profile=mkdtempSync(join(tmpdir(),'eq-public-reader-'));
  const child=spawn(browser,['--headless','--disable-gpu','--no-first-run','--no-default-browser-check',
    '--disable-background-networking','--disable-component-update',`--user-data-dir=${profile}`,'--remote-debugging-pipe'],
    {stdio:['ignore','ignore','ignore','pipe','pipe']});
  let sequence=0,buffer='',loadSession;const pending=new Map();
  const rejectAll=()=>{for(const waiter of pending.values())waiter.reject(new Error('EQ_BROWSER_READBACK_FAILED'));pending.clear();};
  child.on('error',rejectAll);child.on('exit',rejectAll);
  child.stdio[3].on('error',rejectAll);child.stdio[4].setEncoding('utf8');
  child.stdio[4].on('data',chunk=>{
    buffer+=chunk.toString();let end;
    while((end=buffer.indexOf('\0'))>=0) {
      const raw=buffer.slice(0,end);buffer=buffer.slice(end+1);
      try {
        const message=JSON.parse(raw);
        if(message.method==='Page.loadEventFired' && message.sessionId===loadSession) {pending.get('load')?.resolve();pending.delete('load');}
        const waiter=pending.get(message.id);
        if(waiter){pending.delete(message.id);message.error ? waiter.reject(new Error('EQ_BROWSER_READBACK_FAILED')) : waiter.resolve(message.result);}
      } catch {rejectAll();}
    }
  });
  const request=(method,params={},sessionId)=>new Promise((resolve,reject)=>{
    if(child.exitCode!==null || child.signalCode!==null || child.killed) {reject(new Error('EQ_BROWSER_READBACK_FAILED'));return;}
    const id=++sequence;pending.set(id,{resolve,reject});child.stdio[3].write(JSON.stringify({id,method,params,...(sessionId?{sessionId}:{})})+'\0');
  });
  const timeout=setTimeout(()=>{rejectAll();child.kill();},45000);
  try {
    const {targetId}=await request('Target.createTarget',{url:'about:blank'});
    const {sessionId}=await request('Target.attachToTarget',{targetId,flatten:true});
    await request('Page.enable',{},sessionId);loadSession=sessionId;
    const loaded=new Promise((resolve,reject)=>pending.set('load',{resolve,reject}));loaded.catch(()=>{});
    const navigation=await request('Page.navigate',{url},sessionId);
    if(navigation.errorText) throw new Error('EQ_BROWSER_READBACK_FAILED');
    await loaded;
    const result=await request('Runtime.evaluate',{expression:`(${browserVisibleDocument.toString()})()`,awaitPromise:true,returnByValue:true},sessionId);
    if(result.exceptionDetails || !result.result?.value) throw new Error('EQ_BROWSER_READBACK_FAILED');
    return result.result.value;
  } finally {
    clearTimeout(timeout);
    await closeOwnedBrowser(child,()=>request('Browser.close'),profile);
  }
}
export async function verifyOnlineCandidates(environment, backendRoot, fetcher = fetch, renderer = renderedDocument, locale = 'zh-CN') {
  if (!['staging','production'].includes(environment) || !['zh-CN','en'].includes(locale)) throw new Error('EQ_ONLINE_ENVIRONMENT_INVALID');
  const api = environment === 'staging' ? 'https://staging-api.fermatmind.com' : 'https://api.fermatmind.com';
  const web = environment === 'staging' ? 'https://staging.fermatmind.com' : 'https://fermatmind.com';
  const rows = JSON.parse(readFileSync(resolve(backendRoot,packagePath,'assets.json'),'utf8')).candidates.filter(row=>row.identity.locale===locale);
  const read = async (url, type) => {
    const response = await fetcher(url,{redirect:'error',signal:AbortSignal.timeout(15000),headers:{'Cache-Control':'no-cache'}});
    if (response.status !== 200 || !response.headers.get('content-type')?.includes(type)) throw new Error('EQ_ONLINE_RESPONSE_INVALID');
    const body = await response.text();
    if (body.length>2000000) throw new Error('EQ_ONLINE_PAYLOAD_LIMIT');
    return body;
  };
  for (const row of rows) {
    const payload = JSON.parse(await read(`${api}/api/v0.5/articles/${row.identity.slug}?locale=${locale}&org_id=0`,'application/json'));
    if (payload.ok !== true || payload.article?.slug !== row.identity.slug || payload.article?.locale !== locale) throw new Error('EQ_ONLINE_IDENTITY_MISMATCH');
    for (const key of ['title','excerpt','content_md']) if (payload.article[key] !== row.snapshot[key]) throw new Error('EQ_ONLINE_BODY_MISMATCH');
    if (payload.seo_surface_v1?.title !== row.snapshot.seo_title || payload.seo_surface_v1?.description !== row.snapshot.seo_description) throw new Error('EQ_ONLINE_METADATA_MISMATCH');
    const seo = JSON.parse(await read(`${api}/api/v0.5/articles/${row.identity.slug}/seo?locale=${locale}&org_id=0`,'application/json'));
    if (seo.seo_surface_v1?.title !== row.snapshot.seo_title || seo.seo_surface_v1?.description !== row.snapshot.seo_description) throw new Error('EQ_ONLINE_SEO_SURFACE_MISMATCH');
    const pageUrl=`${web}/${locale === 'en' ? 'en' : 'zh'}/articles/${row.identity.slug}`;
    await read(pageUrl,'text/html');
    assertSsrCandidate(await renderer(pageUrl),row);
  }
  return { api_readback_count:3, seo_readback_count:3, ssr_readback_count:3, environment };
}
