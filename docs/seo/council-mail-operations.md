# Council mail (A08 remains paused)

Council mail is a transport, not runtime or business-write authority. Existing
notification policy, outbox and pause guards remain authoritative. No marketing
subscriber, email lifecycle, GSC sync or Mission execution is involved.

## Configuration

Both environments require `SEO_COUNCIL_NOTIFICATION_CHANNEL=email`,
`SEO_COUNCIL_MAIL_RECIPIENT` (one operator address) and `SEO_COUNCIL_MAIL_OPS_URL`
(HTTPS environment-specific Ops origin). Production requires the canonical
`https://ops.fermatmind.com` origin and reuses existing SMTP `MAIL_*` settings.
The dedicated mailer enforces certificate verification, TLS and a 10-second
network timeout without changing the application's default mailer.

Staging keeps `MAIL_MAILER=log`. Its independent sender uses:

- `SEO_COUNCIL_MAIL_HOST`, `SEO_COUNCIL_MAIL_PORT` (default 465)
- `SEO_COUNCIL_MAIL_SCHEME` (default smtps)
- `SEO_COUNCIL_MAIL_USERNAME`, `SEO_COUNCIL_MAIL_PASSWORD`
- `SEO_COUNCIL_MAIL_FROM_ADDRESS`, `SEO_COUNCIL_MAIL_EHLO_DOMAIN`

Provision the independent staging sender through the existing mail service and
inject its credentials through the authorized environment configuration channel.
Never copy production SMTP credentials to staging. Never commit real recipients,
SMTP passwords, provider responses or private evidence. Missing configuration is
`MAIL_CONFIGURATION_HOLD`, not permission to fall back to log or production mail.

## Acceptance

`php artisan seo:council-mail preflight` is read-only and never authenticates or
sends. `MAIL_CONFIGURATION_READY` proves configuration only, not SMTP acceptance.

`php artisan seo:council-mail test` is staging-only. It sends one fixed test
message to the configured operator without resuming Council or draining outbox.
Production rejects test mode. A non-expiring atomic Redis marker binds staging, release SHA
and recipient digest; duplicate attempts do not send again. Do not delete test
markers to retry an uncertain result. Cache loss/eviction means historical
at-most-once proof is unavailable: inspect existing evidence before any retry.

`SMTP_ACCEPTED_INBOX_UNCONFIRMED` requires one operator inbox confirmation.
`SMTP_ALREADY_ACCEPTED_INBOX_UNCONFIRMED` reports prior provider acceptance only.
An unknown previous attempt remains `DELIVERY_ACK_UNKNOWN` on subsequent calls.
Provider acceptance and operator-confirmed inbox delivery are distinct outcomes.
Keep failure details sanitized. `DELIVERY_ACK_UNKNOWN` never automatically retries;
an explicit SMTP rejection or pre-send configuration failure uses existing bounded
outbox retry. A transport result never changes the Mission verdict.

No production test incidents are generated. While Council is paused, ordinary
notification delivery remains paused too. Production natural delivery can only
be observed after a separately authorized Mission rollout.
