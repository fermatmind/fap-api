# Go-Live Gate Checklist (Pass / Stop-Ship)

## 1. Commerce / Stripe
- Live secret key in use (`STRIPE_SECRET` starts with `sk_live_`)
- Live webhook secret configured (`STRIPE_WEBHOOK_SECRET`)
- Real payment closed-loop drill completed
- Refund rollback drill completed (`OPS_GATE_PAYMENT_REFUND_DRILL_OK=true`)

## 2. SRE / DevOps
- `APP_DEBUG=false`
- Queue worker is resident (`QUEUE_CONNECTION != sync` in production)
- Database backup + restore drill recorded (`OPS_GATE_DB_RESTORE_DRILL_OK=true`)
- Log rotation + temp file cleanup verified (`OPS_GATE_LOG_ROTATION_OK=true`)

## 3. Compliance / Communication
- SMTP productionized (`MAIL_HOST` and auth configured)
- SPF / DKIM / DMARC validated (`OPS_GATE_SPF_DKIM_DMARC_OK=true`)
- Legal pages reviewed and published (`OPS_GATE_LEGAL_PAGES_OK=true`)
- Ops compliance skeleton present (audit logs + lifecycle requests)

## 4. Growth / Observability
- Backend and frontend Sentry enabled (`SENTRY_LARAVEL_DSN` / `VITE_SENTRY_DSN`)
- Conversion tracking validated (`OPS_GATE_CONVERSION_TRACKING_OK=true`)
- GSC sitemap + robots/noindex checks completed (`OPS_GATE_GSC_SITEMAP_OK=true`)

## Decision Rule
- If any checklist item fails: `STOP-SHIP`
- If all checklist items pass: `PASS`

## Execution
- API: `GET /api/v0.2/admin/go-live-gate`
- API (force run): `POST /api/v0.2/admin/go-live-gate/run`
- Script: `bash backend/scripts/go_live_gate.sh`
