# SMTP DNS Runbook (SPF / DKIM / DMARC)

## Goal
Ensure transactional emails (delivery/refund/support) from `fap-api` are accepted by Gmail and Outlook inboxes.

## 1. Required DNS records

### FermatMind DirectMail baseline

- Sender domain: `mail.fermatmind.com`
- Sender address: `noreply@mail.fermatmind.com`
- SMTP host: `smtpdm.aliyun.com`
- SMTP port: `465`
- SMTP scheme: `smtps`
- EHLO/local domain: `mail.fermatmind.com`
- Outbox sender: `php artisan email:outbox-send`
- Runtime send gate: `EMAIL_OUTBOX_SEND=true`
- DNS gate: `OPS_GATE_SPF_DKIM_DMARC_OK=true`

Production secrets must stay in production env/secret storage only.
Do not commit `MAIL_PASSWORD` or browser-exported credentials, cookies,
session tokens, or provider API keys.

### SPF (TXT on root)
- Host: `mail`
- Type: `TXT`
- Value: `v=spf1 include:spf1.dm.aliyun.com -all`
- Rule: only one SPF TXT record for the same domain.

### DKIM (TXT on selector)
- Host: `aliyun-cn-hangzhou._domainkey.mail`
- Type: `TXT`
- Value: provider public key (`v=DKIM1; k=rsa; p=...`)
- Rule: selector must match SMTP provider configuration.

### DMARC (TXT on `_dmarc`)
- Host: `_dmarc.mail`
- Type: `TXT`
- Value baseline:
  - `v=DMARC1;p=none;rua=mailto:dmarc_report@service.aliyun.com`
- Production hardening suggestion:
  - move from `p=none` -> `p=quarantine` -> `p=reject` after monitoring.

### MX
- Host: `mail`
- Type: `MX`
- Value: `mx01.dm.aliyun.com`
- Priority: `10`

## 2. Backend configuration checklist

- `MAIL_MAILER=smtp`
- `MAIL_SCHEME=smtps`
- `MAIL_HOST=smtpdm.aliyun.com`
- `MAIL_PORT=465`
- `MAIL_USERNAME=noreply@mail.fermatmind.com`
- `MAIL_PASSWORD` configured in production secret/env storage
- `MAIL_FROM_ADDRESS=noreply@mail.fermatmind.com`
- `MAIL_FROM_NAME=FermatMind`
- `MAIL_EHLO_DOMAIN=mail.fermatmind.com`
- `EMAIL_OUTBOX_SEND=true`
- `OPS_GATE_SPF_DKIM_DMARC_OK=true`
- `FAP_SUPPORT_EMAIL` set (or fallback `support@fermatmind.com`)
- `FAP_GLOBAL_TERMS_URL`, `FAP_GLOBAL_PRIVACY_URL`, `FAP_GLOBAL_REFUND_URL`
- `FAP_CN_TERMS_URL`, `FAP_CN_PRIVACY_URL`, `FAP_CN_REFUND_URL`

## 3. Pre-flight validation commands

```bash
# DNS check
nslookup -type=txt your-domain.com
nslookup -type=txt <selector>._domainkey.your-domain.com
nslookup -type=txt _dmarc.your-domain.com
nslookup -type=mx mail.your-domain.com

# Laravel config check
cd backend && php artisan config:clear
cd backend && php artisan tinker --execute="dump(config('mail.default')); dump(config('mail.mailers.smtp.host')); dump(config('mail.from'));"
```

## 4. Delivery verification

1. Queue one EN and one ZH email in `email_outbox` (payment/refund/support template).
2. Run sender:

```bash
cd backend && php artisan email:outbox-send --limit=10
```

3. Confirm in provider logs: accepted + message-id generated.
4. Send to at least:
- one Gmail account
- one Outlook account
5. Verify both rendering and links:
- subject language matches locale
- links point to EN (`/terms`, `/privacy`, `/refund`) or ZH (`/zh/...`) pages

## 5. Troubleshooting

- SPF fail: duplicate SPF records or missing provider include.
- DKIM fail: selector mismatch or truncated TXT record.
- DMARC fail: SPF/DKIM alignment mismatch (domain in From differs).
- Spam placement: warm domain/IP, reduce link density, ensure plain legal/support footer.

## 6. Go-live gate evidence

Store screenshots/log snippets for:
- DNS records (SPF/DKIM/DMARC)
- provider accepted events
- Gmail inbox + Outlook inbox samples
- Ops HealthChecks mailer summary page

## 7. Controlled smoke result

2026-05-29 controlled production smoke result:

- Scope: one internal Outlook recipient only.
- fap-api command: `php artisan email:outbox-send --limit=1`
- Outbox result: `Mailer smtp: sent 1, blocked 0, failed 0.`
- Outbox status: `sent`
- Mailer: `smtp`
- DirectMail result: `250 Send Mail OK`
- Subject: `FermatMind DirectMail smoke test`
- From: `noreply@mail.fermatmind.com`
- Recipient confirmation: delivered to Outlook inbox.

This smoke did not mutate code, publish content, deploy, submit URLs, enqueue
Search Channel actions, or send to real users. Future production smoke tests
must remain opt-in, scoped to internal recipients, and recorded without raw credentials or private user data.
