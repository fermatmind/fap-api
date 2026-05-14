# Controlled Codex-Assisted CMS Publish SOP

This SOP allows Codex to assist with publishing reviewed CMS article drafts only through a controlled backend command. It does not allow Codex to freely publish content through the CMS UI or to bypass editorial gates.

## When This Applies

Use this SOP only for Article drafts that were created from a CMS-ready Editorial Package and have already passed the draft import gates:

- exact body hash
- metadata completeness
- cover, alt, prompt, and style tag completeness
- references completeness
- CTA and FAQ completeness
- graph metadata completeness
- claim boundary checks

Do not use this SOP for health-sensitive or ability-sensitive articles. Those still require direct human CMS publish.

## Required Human Confirmation

Codex may run the publish command only after the user provides the exact confirmation phrase emitted by dry-run.

Single article:

```bash
I explicitly approve Codex to publish article id 31 after preflight passes.
```

Multiple articles:

```bash
I explicitly approve Codex to publish article ids 31,32 after preflight passes.
```

Boundary-context claim warnings must be acknowledged per article:

```bash
--ack-claim-warning=31
```

## Dry Run

Run dry-run first:

```bash
php artisan articles:publish-controlled \
  --article=31 \
  --article=32 \
  --make-indexable \
  --dry-run
```

Dry-run must report:

- `ok=1`
- expected confirmation phrase
- import status `imported` or `warning`
- claim status passed or boundary-context warning
- media complete
- references count greater than zero
- graph complete
- CTA count greater than zero
- FAQ count greater than zero
- body hash present

If dry-run reports any error, do not publish.

## Formal Publish

Run formal publish only after exact user confirmation:

```bash
php artisan articles:publish-controlled \
  --article=31 \
  --article=32 \
  --ack-claim-warning=31 \
  --make-indexable \
  --confirm="I explicitly approve Codex to publish article ids 31,32 after preflight passes."
```

The command:

- approves the working revision
- marks the article and SEO meta indexable when `--make-indexable` is set
- publishes through `ArticlePublishService`
- records content release follow-up using source `controlled_codex_publish`
- writes a `codex_controlled_article_publish` audit log

## Post-Publish Verification

After publish, verify:

- public article detail API returns published/indexable payload
- article list includes the article
- homepage/topic/career surfaces update if graph metadata points there
- Article JSON-LD image and canonical are present
- sitemap and llms include the article if policy allows
- content release revalidate audit succeeded

## Boundaries

Codex must not:

- write or rewrite article content
- bypass import gate results
- publish without exact user confirmation
- publish non-boundary claim warnings
- publish health-sensitive or ability-sensitive content
- access attempts, results, report snapshots, orders, payments, shares, raw answers, or user PII
- use CMS UI clicks as the default publishing mechanism

Human editors remain accountable for editorial review and can still publish directly through CMS workflows when appropriate.
