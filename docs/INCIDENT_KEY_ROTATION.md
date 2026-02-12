# INCIDENT KEY ROTATION (SEC-001)

## Mandatory rotation checklist
- APP_KEY
- Stripe webhook secret
- Billing webhook secret
- All token/signing secrets
- DB password
- Redis password

## Repository rules
- Keep `backend/.env.example` in repository.
- Never commit `backend/.env` or any real `.env.*` secret file.
- Real secrets must be stored in secret manager / runtime environment only.
