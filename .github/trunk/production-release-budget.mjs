// Deployer and Career file readback share one forward deadline. Reserve a
// separate 30m LKG rebuild, 15m bounded closeout, and both 30s kill graces.
// Other publishers remain outside the forward deadline: their allowances
// include business execution/recovery and bounded reader settlement.
// In particular 53 sequential 360s cache waits cannot fit a 120m job.
// Reject such a selection before activation rather than sacrifice recovery.
export function requiredSeconds(deployMinutes, operations) {
  if (![30,35,60].includes(deployMinutes)) throw new Error('DEPLOY_BUDGET_INVALID');
  if (!operations || typeof operations !== 'object' || Array.isArray(operations)) throw new Error('OPERATIONS_INVALID');
  const consumers = {
    eq_new_source_articles_publish: 2600,
    eq_existing_public_pages_publish: 6000,
    eq_new_english_articles_publish: 2600,
    iq_public_scale_publish: 3000,
    iq_public_articles_publish: 8000,
    iq_eq_topic_publish: 3000,
    public_content_53_publish: 26000,
  };
  for (const name of [...Object.keys(consumers), 'career_current_authority_release']) {
    if (operations[name] !== undefined && typeof operations[name] !== 'boolean') throw new Error('OPERATION_INVALID');
  }
  if (operations.career_current_authority_release && deployMinutes !== 60) throw new Error('CAREER_FORWARD_BUDGET_INVALID');
  return deployMinutes*60 + 1800 + 900 + 60 + Object.entries(consumers)
    .reduce((sum,[name,seconds])=>sum+(operations[name] === true ? seconds : 0),0);
}

if (process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href) {
  const operations = JSON.parse(Buffer.from(process.argv[3], 'base64').toString());
  process.stdout.write(String(requiredSeconds(Number(process.argv[2]),operations))+'\n');
}
