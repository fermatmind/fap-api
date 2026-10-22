// Deployer and Career file readback share one forward deadline. Reserve a
// separate 30m LKG rebuild, 15m bounded closeout, and both 30s kill graces.
// Other publishers remain outside the forward deadline: their allowances
// include business execution/recovery and bounded reader settlement.
// Count the verified selected package, not the historical lane name. Each
// public page reserves 600s for prestate (up to two read-only browsers),
// API/DOM parsing and the existing
// 360s cache-settlement loop (including its final bounded browser attempt).
// Both 800s publication/recovery SSH limits remain reserved separately.
import {readCandidateRows,packageDigest} from './public-content-53-package.mjs';
import {IQ_PUBLICATION_TRANSPORT_TIMEOUT_MS} from './iq-public-scale-publish.mjs';
import {IQ_CANDIDATE_READ_TIMEOUT_MS, IQ_HTTP_READ_TIMEOUT_MS} from './iq-public-scale-online-qa.mjs';

// Two locales: API + HTML per locale, then a real browser and final assertion.
// Keep the shared reader unchanged. Its 45s timer can overrun by one synchronous
// 30s DOM assertion; browser shutdown takes <=6.5s, and final DOM QA another 30s.
// Source contracts bind those shared limits; 120s covers local package/setup work.
export const IQ_PUBLIC_SCALE_BUDGET_SECONDS = Math.ceil(
  (2 * IQ_PUBLICATION_TRANSPORT_TIMEOUT_MS + IQ_CANDIDATE_READ_TIMEOUT_MS
    + 4 * IQ_HTTP_READ_TIMEOUT_MS + 2 * (45000 + 30000 + 6500 + 30000)) / 1000 + 120,
);

export function requiredSeconds(deployMinutes, operations, publicContentRows = 53, newArticleDiscovery = false) {
  if (![30,35,60].includes(deployMinutes)) throw new Error('DEPLOY_BUDGET_INVALID');
  if (!operations || typeof operations !== 'object' || Array.isArray(operations)) throw new Error('OPERATIONS_INVALID');
  const consumers = {
    eq_new_source_articles_publish: 2600,
    eq_existing_public_pages_publish: 6000,
    eq_new_english_articles_publish: 2600,
    iq_public_scale_publish: IQ_PUBLIC_SCALE_BUDGET_SECONDS,
    iq_public_articles_publish: 8000,
    iq_eq_topic_publish: 3000,
  };
  for (const name of [...Object.keys(consumers), 'public_content_53_publish', 'career_current_authority_release']) {
    if (operations[name] !== undefined && typeof operations[name] !== 'boolean') throw new Error('OPERATION_INVALID');
  }
  if (operations.career_current_authority_release && deployMinutes !== 60) throw new Error('CAREER_FORWARD_BUDGET_INVALID');
  if (operations.public_content_53_publish) {
    if (!Number.isSafeInteger(publicContentRows) || publicContentRows < 1) throw new Error('PUBLIC_CONTENT_ROW_COUNT_INVALID');
    consumers.public_content_53_publish = 1600 + publicContentRows * 600 + (newArticleDiscovery ? 360 : 0);
  }
  return deployMinutes*60 + 1800 + 900 + 60 + Object.entries(consumers)
    .reduce((sum,[name,seconds])=>sum+(operations[name] === true ? seconds : 0),0);
}

if (process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href) {
  const operations = JSON.parse(Buffer.from(process.argv[3], 'base64').toString());
  const rows = operations.public_content_53_publish === true ? readCandidateRows('backend',packageDigest('backend',operations.public_content_53_package)).candidates : [];
  const count = rows.length || undefined;
  process.stdout.write(String(requiredSeconds(Number(process.argv[2]),operations,count,rows.some(row=>row.new_article)))+'\n');
}
