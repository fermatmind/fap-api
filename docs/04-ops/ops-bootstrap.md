# Ops Bootstrap Runbook

## Purpose

This runbook defines the current repo-backed bootstrap path for:

- the first Ops login account
- the first owner-ready Ops admin
- the first org-selected, actually usable Ops session

This document only describes the current repository truth. It does not invent new bootstrap commands, onboarding UI, seed flows, or tenant-side ownership semantics that do not exist in code today.

## Scope

In scope:

- first Ops admin bootstrap
- first login flow
- first org creation path
- first org selection path
- minimum acceptance checks

Out of scope:

- deploy / asset / entry runbooks
- auth or provider rewrites
- tenant-side ownership/member semantics redesign
- organization import automation

## Preflight

Before running bootstrap:

1. Migrations must already be applied.
2. The Ops panel must be enabled with `FAP_ADMIN_PANEL_ENABLED=true`.
3. The correct Ops login URL is `/ops/login`.
4. Host/IP restrictions for Ops access must already allow your environment.

Minimum repo checks:

```bash
cd backend
php artisan list | rg 'admin:bootstrap-owner'
php artisan route:list | rg 'ops|select-org|organizations-import|two-factor-challenge|admin-users|organizations'
```

## Correct Login Model

The first Ops login account must be an `App\Models\AdminUser`.

Do not use:

- `App\Models\User`
- `App\Models\TenantUser`
- `php artisan db:seed` as an Ops bootstrap substitute

Why:

- Ops panel auth uses the `admin` guard and the `admin_users` provider.
- `TenantUser` only applies to the tenant panel, not the Ops panel.
- The default `DatabaseSeeder` creates a `User`, which is not an Ops login account.

## Bootstrap States

This repo currently has three different bootstrap states. Do not collapse them into one:

1. `login-capable admin`
   - A valid `AdminUser` exists.
   - The account is active.
   - The account is not locked.
   - The password is set.
   - This state is enough to log into `/ops/login`.

2. `owner-ready admin`
   - The `AdminUser` also has initialized RBAC state.
   - The account has the roles and permissions needed to manage Ops bootstrap screens.
   - This is the intended result of `admin:bootstrap-owner`.

3. `org-selected usable Ops session`
   - The admin has logged in successfully.
   - An organization exists.
   - An organization has been selected in the Ops session.
   - This is the minimum state for org-scoped Ops workflows.

Logging in successfully does not mean Ops is fully usable.

## First Admin: Official Bootstrap Path

The official first-admin path is:

```bash
cd backend
php artisan admin:bootstrap-owner --email=owner@example.com --password='ChangeMe123!' --name='Owner'
```

What this command does today:

- creates or updates an `AdminUser`
- sets:
  - `name`
  - `email`
  - `password`
  - `is_active = 1`
- initializes permissions
- initializes default roles
- grants:
  - `OpsAdmin`
  - `Owner`

What this command does not do:

- create a tenant-side `users` record
- create an organization
- create an `organization_members` row
- establish tenant-side `owner_user_id` semantics

## First Admin: Minimum Login-Capable Conditions

The minimum login-capable Ops admin is an `AdminUser` with:

- `name`
- `email`
- `password`
- `is_active = 1`
- `locked_until = null` or not in the future

Notes:

- Roles and permissions are not the raw login gate.
- Roles and permissions do matter for what can be managed after login.
- `failed_login_count` can remain at its default value.
- `totp_enabled_at` may remain `null` for first login.

## First Login Flow

Use:

```text
/ops/login
```

Current flow:

1. Submit valid `AdminUser` credentials at `/ops/login`.
2. If TOTP is enabled for that admin, the session is redirected to:

```text
/ops/two-factor-challenge
```

3. Otherwise, or after successful TOTP verification, the session is redirected to:

```text
/ops/select-org
```

This is expected. First login lands in org selection, not directly in a fully usable org-scoped workspace.

## No-Org State vs Usable State

Current no-org state:

- login works
- dashboard works
- select-org works
- the following pages/resources remain accessible without org selection:
  - dashboard
  - select-org
  - admin-users
  - roles
  - permissions
  - organizations
  - deploys
  - organizations-import
  - go-live-gate
  - health-checks
  - queue-monitor

Current org-scoped state:

- many content, commerce, support, and runtime pages require an org to be selected
- if no org is selected, middleware redirects those requests back to `/ops/select-org`

Practical rule:

- `login success` means the account is valid
- `usable Ops` starts only after an org exists and has been selected

## First Org: Current Supported Path

There is no repo-backed first-org artisan bootstrap command today.

The current supported first-org path is manual creation inside Ops UI:

1. Log in at `/ops/login`.
2. Complete TOTP if required.
3. Go to `/ops/select-org`.
4. Create an organization from the existing UI entry point.

Current supported creation entry points:

- `/ops/select-org`
- `/ops/organizations/create`

Current supported selection step:

- choose the org from `/ops/select-org`

## Important Boundary: Org Creation Is Not Tenant Ownership Bootstrap

Be explicit about the current boundary:

- creating an org in Ops UI does not mean the `AdminUser` becomes a tenant-side owner/member
- current Ops-side org creation does not establish `organization_members` for the admin
- current Ops-side org creation does not establish tenant-side `owner_user_id` semantics for an actual tenant user

Do not document those semantics as if they are already wired up.

## Unsupported Or Non-Official Bootstrap Paths

Do not treat any of the following as the official first bootstrap path:

- creating `App\Models\User` and expecting Ops login to work
- creating `App\Models\TenantUser` and expecting Ops login to work
- `php artisan db:seed`
- the default `DatabaseSeeder`
- tenant API org creation as the first Ops bootstrap path
- `/ops/organizations-import` as an automated import solution

The current import page is only a placeholder. It is not an automated org bootstrap pipeline.

## Minimum Acceptance Checks

After first-admin bootstrap:

```bash
cd backend
php artisan list | rg 'admin:bootstrap-owner'
php artisan route:list | rg 'ops|select-org|organizations-import|two-factor-challenge|admin-users|organizations'
php -l app/Console/Commands/AdminBootstrapOwner.php
php -l app/Models/AdminUser.php
php -l app/Http/Responses/Auth/OpsLoginResponse.php
php -l app/Http/Middleware/RequireOpsOrgSelected.php
```

Host-level smoke:

```bash
curl -I https://<ops-host>/ops/login
curl -I https://<ops-host>/ops
curl -I https://<staging-ops-host>/ops/login
```

Repo-context checks:

```bash
cd backend
php artisan tinker --execute="dump(config('auth.guards.admin')); dump(config('auth.providers.admin_users'));"
php artisan tinker --execute="dump(config('admin.panel_enabled')); dump(config('admin.url'));"
```

## Operational Notes

- Prefer `admin:bootstrap-owner` over ad-hoc tinker for first-admin bootstrap because the command is repo-backed and initializes RBAC.
- If production recovery ever requires manual intervention beyond this runbook, document that separately as an exception flow. Do not replace this runbook with “just use tinker”.
- If a future PR adds a real first-org command or import automation, update this runbook then. Do not pre-document it now.
