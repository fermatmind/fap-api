# Env & Config Strategy (Draft)

Purpose  
Define how `local` / `staging` / `production` environments will be separated
for the fap-api service.

Stage: 2 · Skeleton-level

---

## 1. Env Modes (planned)

- `APP_ENV=local`
- `APP_ENV=staging` (optional)
- `APP_ENV=production`

---

## 2. Config Boundaries (to be detailed)

- Separate DB / Redis / domain for each env.
- No hard-coded secrets in code — use `.env` only.
- Align region/locale defaults with FAP v0.2 specs.

---

## 3. TODO

- Write example `.env.example` layout.
- Define how to switch env on the server.