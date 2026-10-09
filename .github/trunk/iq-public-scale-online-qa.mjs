import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { isDeepStrictEqual } from 'node:util';
import { packageSha256 } from './iq-public-scale-package.mjs';

const slug = 'iq-test-intelligence-quotient-assessment';
const decode = text => text.replace(/&#(x[0-9a-f]+|[0-9]+);/gi, (_, value) => String.fromCodePoint(value[0].toLowerCase() === 'x' ? parseInt(value.slice(1), 16) : Number(value)))
  .replaceAll('&amp;', '&').replaceAll('&quot;', '"').replaceAll('&#39;', "'").replaceAll('&lt;', '<').replaceAll('&gt;', '>').replaceAll('&nbsp;', ' ');
const plain = text => decode(text).replace(/\s+/gu, ' ').trim();
const markdownText = text => text.replace(/^\s*(?:#{1,6}\s+|[-*+]\s+|[0-9]+[.)]\s+)/, '')
  .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1').replace(/[*`_]/g, '');

export function ssrMainText(html) {
  const code = '$doc=new DOMDocument; libxml_use_internal_errors(true); if(!$doc->loadHTML(stream_get_contents(STDIN), LIBXML_NONET))throw new RuntimeException("invalid html"); $xp=new DOMXPath($doc); $main=$xp->query("//main[@data-test-landing-read-source]"); if($main->length!==1)throw new RuntimeException("main missing"); $walk=function($node)use(&$walk){ if($node instanceof DOMElement){ $style=preg_replace("/\\s+/", "", strtolower($node->getAttribute("style"))); if(in_array(strtolower($node->tagName),["script","style","template","noscript"]) || $node->hasAttribute("hidden") || preg_match("/(?:^|\\s)hidden(?:\\s|$)/",$node->getAttribute("class")) || strtolower($node->getAttribute("aria-hidden"))==="true" || str_contains($style,"display:none") || str_contains($style,"visibility:hidden"))return ""; } if($node instanceof DOMText)return $node->nodeValue; $text=""; foreach($node->childNodes as $child)$text.=" ".$walk($child); return $text; }; echo $walk($main->item(0));';
  try {
    return execFileSync('php', ['-r', code], { input: html, encoding: 'utf8', timeout: 15000, maxBuffer: 2000000, stdio: ['pipe', 'pipe', 'pipe'] });
  } catch {
    throw new Error('IQ_SSR_MAIN_INVALID');
  }
}

export function assertSsrCandidate(html, row) {
  const visible = plain(ssrMainText(html)).replace(/\s/gu, '');
  const sections = [row.patch.landing_copy, row.patch.why_choose.intro,
    ...row.patch.why_choose.items.flatMap(item => [item.title, item.body]),
    ...row.patch.faq.flatMap(item => [item.q, item.a])];
  for (const section of sections) {
    for (const original of section.split('\n')) {
      if (!original.trim() || /^[\s|:-]+$/.test(original)) continue;
      const line = markdownText(original);
      for (const cell of line.includes('|') ? line.split('|') : [line]) {
        const expected = plain(cell).replace(/\s/gu, '');
        if (expected && !visible.includes(expected)) throw new Error('IQ_SSR_BODY_MISMATCH');
      }
    }
  }
}

export function readCandidateRows(backendRoot) {
  // The PHP package reader is the sole projection implementation. This
  // read-only call performs no Laravel bootstrap, database or network writes.
  const code = 'require $argv[1]."/app/Services/ContentPromotion/PromotionContextFactory.php"; require $argv[1]."/app/Services/ContentPromotion/IqPublicEntryPackage.php"; echo json_encode((new App\\Services\\ContentPromotion\\IqPublicEntryPackage)->read($argv[1],$argv[2]), JSON_THROW_ON_ERROR);';
  return JSON.parse(execFileSync('php', ['-r', code, resolve(backendRoot), packageSha256], { encoding: 'utf8', timeout: 30000, maxBuffer: 262144 }));
}

export function renderPage(url) {
  // Next streams Suspense fragments outside main and attaches them during
  // hydration. Accept the actual rendered DOM rather than hidden raw chunks.
  const profile = mkdtempSync(join(tmpdir(), 'iq-online-qa-'));
  const chrome = process.env.CHROME_BIN || (process.platform === 'darwin'
    ? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' : 'google-chrome');
  try {
    return execFileSync(chrome, ['--headless=new', '--disable-gpu', '--disable-dev-shm-usage',
      '--no-first-run', `--user-data-dir=${profile}`, '--dump-dom', '--virtual-time-budget=10000', url],
    { encoding: 'utf8', timeout: 45000, maxBuffer: 2000000, stdio: ['ignore', 'pipe', 'pipe'] });
  } catch {
    throw new Error('IQ_ONLINE_RENDER_FAILED');
  } finally {
    rmSync(profile, { recursive: true, force: true });
  }
}

export async function verifyOnlineCandidates(environment, backendRoot, fetcher = fetch) {
  if (!['staging', 'production'].includes(environment)) throw new Error('IQ_ONLINE_ENVIRONMENT_INVALID');
  const api = environment === 'staging' ? 'https://staging-api.fermatmind.com' : 'https://api.fermatmind.com';
  const web = environment === 'staging' ? 'https://staging.fermatmind.com' : 'https://fermatmind.com';
  const rows = readCandidateRows(backendRoot);
  const read = async (url, type) => {
    const response = await fetcher(url, { redirect: 'error', signal: AbortSignal.timeout(15000), headers: { 'Cache-Control': 'no-cache' } });
    if (response.status !== 200 || !response.headers.get('content-type')?.includes(type)) throw new Error('IQ_ONLINE_RESPONSE_INVALID');
    const body = await response.text();
    if (body.length > 2000000) throw new Error('IQ_ONLINE_PAYLOAD_LIMIT');
    return body;
  };
  for (const row of rows) {
    const payload = JSON.parse(await read(`${api}/api/v0.3/scales/lookup?slug=${slug}&locale=${row.identity.locale}`, 'application/json'));
    if (payload.ok !== true || payload.primary_slug !== slug || payload.scale_code !== 'IQ_RAVEN' || payload.is_public !== true) throw new Error('IQ_ONLINE_IDENTITY_MISMATCH');
    for (const leaf of ['landing_copy', 'why_choose', 'faq']) {
      if (!isDeepStrictEqual(payload.content_i18n_json?.[row.key]?.[leaf], row.patch[leaf])) throw new Error('IQ_ONLINE_BODY_MISMATCH');
    }
    const page = `${web}/${row.key}/tests/${slug}`;
    await read(page, 'text/html');
    assertSsrCandidate(renderPage(page), row);
  }
  return { api_readback_count: 2, ssr_readback_count: 2, environment };
}
