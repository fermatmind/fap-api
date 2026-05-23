# SEO-GROWTH-MBTI-ACTION-01B-FIX-01 Single URL Search Channel Enqueue Command

Task: `SEO-GROWTH-MBTI-ACTION-01B-FIX-01`

## Why this fix was needed

`SEO-GROWTH-MBTI-ACTION-01B` proved that the EN MBTI test URL was already an eligible persisted `seo_urls` candidate for `indexnow`, but the official `seo-intel:search-channel-queue` command could only plan or write broad candidate sets. It had no supported way to target exactly one canonical URL through the official queue path.

This fix adds a bounded single-URL selector so a later human-approved production task can enqueue exactly one persisted URL without manual DB writes, without bulk queue creation, and without any live search submission.

## Added command support

The official command now supports:

- `--canonical-url=<absolute canonical URL>`
- `--enqueue`

Example safe dry-run:

```bash
php artisan seo-intel:search-channel-queue \
  --dry-run \
  --no-write \
  --json \
  --channel=indexnow \
  --canonical-url=https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types
```

Example later bounded enqueue path after deploy and human approval:

```bash
php artisan seo-intel:search-channel-queue \
  --enqueue \
  --json \
  --channel=indexnow \
  --canonical-url=https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types
```

## Authority boundary

The single-URL selector is backed only by persisted `seo_intel.seo_urls`.

Required authority:

- persisted URL Truth row in `seo_urls`
- approved `source_authority`
- supported `page_entity_type`
- public, canonical, indexable, non-private, non-draft, non-noindex, claim-safe state

Forbidden authority sources:

- sitemap
- `llms.txt`
- frontend fallback
- crawler logs
- search engine responses
- Digital PR mentions
- local copies

If the exact canonical URL is absent from persisted `seo_urls`, the command fails closed.

## Safety behavior

- No live submission is added or changed.
- No external API call is added or changed.
- No URL Truth writer is added or changed.
- No sitemap or `llms.txt` behavior is changed.
- No `fap-web` behavior is changed.
- No bulk enqueue is allowed through the single-URL path when `--canonical-url` is used for a write without an explicit channel.
- Existing queue idempotency remains required.
- Existing active queue item protection remains required.

## Duplicate and idempotency behavior

When `--canonical-url` and `--channel` resolve to an existing queue item idempotency key:

- the command reports duplicate detection
- no second queue item is created
- no manual DB write path is used
- no live submission is attempted

## Deferred URLs

The following remain deferred and are not changed by this PR:

- `https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types`
- `https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report`
- `https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report`

ZH MBTI remains deferred because it is still missing from the exact production `seo_urls` evidence. Research URLs remain deferred because prior production readiness found URL Truth host mismatch versus public apex canonical.

## Next task

After backend deploy readiness and deploy of this command support:

`SEO-GROWTH-MBTI-ACTION-01B-R2`
