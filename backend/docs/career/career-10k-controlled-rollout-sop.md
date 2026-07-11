# Career 10k controlled rollout SOP

This PR installs a read-only promotion readiness gate. It does not import, publish, deploy, warm production, or change CMS/runtime authority.

The only valid sequence is `100 → 500 → 1,000 → 2,500 → 5,000 → 10,000`. For every batch, supply immutable evidence for API SLO, frontend success rate, backend authority count, EN/ZH parity, canonical/robots/structured-data checks, sitemap and llms completeness, cache warm completion, 404/5xx/504 budgets, publication/indexability approval, and a tested previous-version rollback target.

Run `php artisan career:validate-10k-controlled-rollout --batch=<count> --evidence=<artifact.json> --json`. A pass means only `ready_for_separate_exact_sha_approval=true`; `apply_allowed` remains false. Production promotion and deploy require a separate exact-SHA authorization. Any failed gate restores/retains the previous runtime projection and stops batch advancement.

Do not advance on staging status, imported row count, or file presence. Data enters public directory, detail APIs, sitemap, or llms only after the backend publication/indexability gate reports the exact approved batch count.
