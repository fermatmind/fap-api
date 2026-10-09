import { readFileSync, existsSync, mkdtempSync, rmSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { resolve, join } from 'node:path';
import { packagePath } from './eq-new-source-package.mjs';

const decode = text => text.replace(/&#(x[0-9a-f]+|[0-9]+);/gi, (_, value) => String.fromCodePoint(value[0].toLowerCase() === 'x' ? parseInt(value.slice(1),16) : Number(value)))
  .replaceAll('&amp;','&').replaceAll('&quot;','"').replaceAll('&#39;',"'").replaceAll('&lt;','<').replaceAll('&gt;','>').replaceAll('&nbsp;',' ');
const plain = text => decode(text).replace(/\s+/gu,' ').trim();
function visibleArticle(html) {
  const stack=[], headings=[]; let body='', h1='', currentHeading=false;
  const cleaned=html.replace(/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/gi,'').replace(/<!--[\s\S]*?-->/g,'');
  for (const token of cleaned.match(/<[^>]*>|[^<]+/g) ?? []) {
    if (!token.startsWith('<')) {
      if (stack.some(node=>node.hidden)) continue;
      if (stack.some(node=>node.article)) body+=` ${token}`;
      if (currentHeading) h1+=token;
      continue;
    }
    const closing=/^<\/([\w-]+)/.exec(token);
    if (closing) {
      if (closing[1].toLowerCase()==='h1') { headings.push(plain(h1)); h1=''; currentHeading=false; }
      const index=stack.map(node=>node.tag).lastIndexOf(closing[1].toLowerCase());
      if(index>=0)stack.splice(index);
      continue;
    }
    const tag=/^<([\w-]+)/.exec(token)?.[1]?.toLowerCase();
    if (!tag || /\/$/.test(token.slice(0,-1)) || ['meta','link','br','hr','img','input','source','wbr'].includes(tag)) continue;
    const classes=/\bclass=["']([^"']*)["']/i.exec(token)?.[1] ?? '';
    const style=/\bstyle=["']([^"']*)["']/i.exec(token)?.[1] ?? '';
    const hidden=/\shidden(?:\s|=|>)/i.test(token) || /\baria-hidden=["']true["']/i.test(token)
      || /(?:^|\s)(?:hidden|sr-only)(?:\s|$)/.test(classes)
      || /(?:display\s*:\s*none|visibility\s*:\s*hidden)/i.test(style);
    stack.push({tag,hidden,article:tag==='article' && /\bdata-testid=["']article-detail-content["']/i.test(token)});
    if(tag==='h1') { currentHeading=true; h1=''; }
  }
  return {body:plain(body),headings:headings.filter(Boolean)};
}
export function assertSsrCandidate(html, row) {
  const {body,headings}=visibleArticle(html);
  if (headings.length!==1 || headings[0]!==plain(row.snapshot.title)) throw new Error('EQ_SSR_TITLE_MISMATCH');
  const title=decode(/<title>([\s\S]*?)<\/title>/i.exec(html)?.[1] ?? '').trim();
  const expectedTitle=row.snapshot.seo_title.replace(/(?:\s*\|\s*FermatMind)+$/i,'').trim()+' | FermatMind';
  if(title!==expectedTitle) throw new Error('EQ_SSR_SEO_TITLE_MISMATCH');
  const description = /<meta\b[^>]*name=["']description["'][^>]*content="([^"]*)"/i.exec(html)?.[1];
  if (decode(description ?? '') !== row.snapshot.seo_description) throw new Error('EQ_SSR_METADATA_MISMATCH');
  const bodyText = body.replace(/\s/gu,'');
  for (const original of row.snapshot.content_md.split('\n')) {
    if (!original.trim() || /^[\s|:-]+$/.test(original)) continue;
    const line = original.replace(/^\s*(?:#{1,6}\s+|[-*+]\s+|[0-9]+[.)]\s+)/,'')
      .replace(/\[([^\]]+)\]\([^)]+\)/g,'$1').replace(/[*`_]/g,'');
    const cells = line.includes('|') ? line.split('|') : [line];
    for (const cell of cells) if (plain(cell) && !bodyText.includes(plain(cell).replace(/\s/gu,''))) throw new Error('EQ_SSR_BODY_MISMATCH');
  }
}
export function renderedDocument(url) {
  const browser=['/usr/bin/google-chrome','/usr/bin/chromium','/usr/bin/chromium-browser','/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'].find(path=>existsSync(path));
  if (!browser) throw new Error('EQ_BROWSER_UNAVAILABLE');
  const profile=mkdtempSync(join(tmpdir(),'eq-public-reader-'));
  try {
    const args=['--headless','--disable-gpu','--no-first-run','--no-default-browser-check',
      '--disable-background-networking','--disable-component-update',`--user-data-dir=${profile}`,
      '--dump-dom','--timeout=15000','--virtual-time-budget=5000',url];
    const result=spawnSync(browser,args,{encoding:'utf8',timeout:30000,maxBuffer:3000000,stdio:['ignore','pipe','pipe']});
    if(result.status!==0 || !result.stdout?.includes('<html')) throw new Error('EQ_BROWSER_READBACK_FAILED');
    return result.stdout;
  } finally {
    rmSync(profile,{recursive:true,force:true}); // Only this invocation's new browser profile.
  }
}
export async function verifyOnlineCandidates(environment, backendRoot, fetcher = fetch, renderer = renderedDocument) {
  if (!['staging','production'].includes(environment)) throw new Error('EQ_ONLINE_ENVIRONMENT_INVALID');
  const api = environment === 'staging' ? 'https://staging-api.fermatmind.com' : 'https://api.fermatmind.com';
  const web = environment === 'staging' ? 'https://staging.fermatmind.com' : 'https://fermatmind.com';
  const rows = JSON.parse(readFileSync(resolve(backendRoot,packagePath,'assets.json'),'utf8')).candidates.filter(row=>row.identity.locale==='zh-CN');
  const read = async (url, type) => {
    const response = await fetcher(url,{redirect:'error',signal:AbortSignal.timeout(15000),headers:{'Cache-Control':'no-cache'}});
    if (response.status !== 200 || !response.headers.get('content-type')?.includes(type)) throw new Error('EQ_ONLINE_RESPONSE_INVALID');
    const body = await response.text();
    if (body.length>2000000) throw new Error('EQ_ONLINE_PAYLOAD_LIMIT');
    return body;
  };
  for (const row of rows) {
    const payload = JSON.parse(await read(`${api}/api/v0.5/articles/${row.identity.slug}?locale=zh-CN&org_id=0`,'application/json'));
    if (payload.ok !== true || payload.article?.slug !== row.identity.slug || payload.article?.locale !== 'zh-CN') throw new Error('EQ_ONLINE_IDENTITY_MISMATCH');
    for (const key of ['title','excerpt','content_md']) if (payload.article[key] !== row.snapshot[key]) throw new Error('EQ_ONLINE_BODY_MISMATCH');
    if (payload.seo_surface_v1?.title !== row.snapshot.seo_title || payload.seo_surface_v1?.description !== row.snapshot.seo_description) throw new Error('EQ_ONLINE_METADATA_MISMATCH');
    const seo = JSON.parse(await read(`${api}/api/v0.5/articles/${row.identity.slug}/seo?locale=zh-CN&org_id=0`,'application/json'));
    if (seo.seo_surface_v1?.title !== row.snapshot.seo_title || seo.seo_surface_v1?.description !== row.snapshot.seo_description) throw new Error('EQ_ONLINE_SEO_SURFACE_MISMATCH');
    const pageUrl=`${web}/zh/articles/${row.identity.slug}`;
    await read(pageUrl,'text/html');
    assertSsrCandidate(await renderer(pageUrl),row);
  }
  return { api_readback_count:3, seo_readback_count:3, ssr_readback_count:3, environment };
}
