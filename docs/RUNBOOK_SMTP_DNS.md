# SMTP DNS Runbook (SPF / DKIM / DMARC)

## Goal
Ensure transactional emails (delivery/refund/support) from `fap-api` are accepted by Gmail and Outlook inboxes.

## 1. Required DNS records

### SPF (TXT on root)
- Host: `@`
- Type: `TXT`
- Value example: `v=spf1 include:_spf.your-mail-provider.com ~all`
- Rule: only one SPF TXT record for the same domain.

### DKIM (TXT on selector)
- Host: `<selector>._domainkey`
- Type: `TXT`
- Value: provider public key (`v=DKIM1; k=rsa; p=...`)
- Rule: selector must match SMTP provider configuration.

### DMARC (TXT on `_dmarc`)
- Host: `_dmarc`
- Type: `TXT`
- Value baseline:
  - `v=DMARC1; p=none; rua=mailto:dmarc@your-domain.com; adkim=s; aspf=s`
- Production hardening suggestion:
  - move from `p=none` -> `p=quarantine` -> `p=reject` after monitoring.

## 2. Backend configuration checklist

- `MAIL_MAILER=smtp`
- `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` configured
- `MAIL_FROM_ADDRESS` uses verified sender domain
- `FAP_SUPPORT_EMAIL` set (or fallback `support@fermatmind.com`)
- `FAP_GLOBAL_TERMS_URL`, `FAP_GLOBAL_PRIVACY_URL`, `FAP_GLOBAL_REFUND_URL`
- `FAP_CN_TERMS_URL`, `FAP_CN_PRIVACY_URL`, `FAP_CN_REFUND_URL`

## 3. Pre-flight validation commands

```bash
# DNS check
nslookup -type=txt your-domain.com
nslookup -type=txt <selector>._domainkey.your-domain.com
nslookup -type=txt _dmarc.your-domain.com

# Laravel config check
cd backend && php artisan config:clear
cd backend && php artisan tinker --execute="dump(config('mail.default')); dump(config('mail.from'));"
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
