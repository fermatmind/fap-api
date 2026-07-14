# Controlled public read-model warm and verification

The public read-model control command is deliberately fail-closed and always follows the business priority order `L1 MBTI -> L2 Big Five -> L3 Career Industries`.

Safe read-only operations:

```bash
cd backend
php artisan public-content:warm-read-models --dry-run --json
php artisan public-content:warm-read-models --verify-only --json
```

With no mode flag, the command defaults to dry-run. Dry-run neither reads nor writes cache entries. Verify-only reads active/LKG pointers and versioned payloads, verifies a 512 KiB per-payload budget, and emits versions, byte counts, and cache source without response bodies. The scheduler runs only `--verify-only --json` every ten minutes with overlap protection.

Non-production warm requires the explicit mode:

```bash
cd backend
php artisan public-content:warm-read-models --warm --json
```

Production warm is disabled unless all three independent gates are present before the command starts:

```bash
PUBLIC_CONTENT_WARM_PRODUCTION_ENABLED=true \
php artisan public-content:warm-read-models \
  --warm \
  --production-write \
  --confirm=PUBLIC-CONTENT-WARM \
  --json
```

Do not enable or execute the production command as part of CI, deployment, CMS publication, or this PR train. Production cache clearing, purging, content mutation, publication, and deployment are separate controlled actions and are not performed by this command.

The bounded warm sequence is:

1. L1 MBTI: all 32 A/T variants, English and Chinese, detail and SEO read models.
2. L2 Big Five: the backend-authoritative Big Five collection for English and Chinese.
3. L3 Career Industries: the existing Career authority warm command followed by active/LKG version verification.

The L3 phase invokes the Career command with `--directory-only`; it rebuilds only the EN/ZH directory read models and does not refresh dataset, job-index, job-detail, or launch-governance cache families. Nested command output is captured so the parent `--json` mode always emits exactly one JSON document.

Any failed priority stops later warm phases. After a successful warm, the command re-reads every selected version and fails if a pointer is missing, a payload cannot be read back, or a payload exceeds the byte budget. Reports never contain public content bodies, private routes, attempt/report/order identifiers, or secrets.
