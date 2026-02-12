# SECURITY KEY ROTATION (SEC-001)

## Scope
The repository must never contain real production secrets.

## Keys/Credentials that must be rotated
- APP_KEY
- DB passwords
- Redis passwords
- Stripe/Billing webhook secrets
- Any integration webhook secrets
- Any admin/service access tokens

## Ownership
- Rotation is executed by Ops/SRE.
- Repository side only keeps examples/placeholders.
- Real values are stored in secret managers / deployment environment variables.

## Required rule
- Do not commit any real `.env` / `.env.*` file.
- `backend/.env.example` is the only template and contains non-sensitive defaults only.
