# NEXT_EXACT_AUTHORIZATION_PROMPTS

No follow-up execution is authorized by this run.

If the operator wants to continue after this PR is reviewed and merged, use one explicit prompt per scope:

1. `After MBTI-MAIN-FREE-FAQ-CONTENT-01 is merged into main, reconcile the PR-train ledger and run post-merge local cleanup only. Do not start another implementation PR.`

2. `Review the next MBTI main schema-parity PR-train item from docs/codex/pr-train.yaml. Verify dependencies are merged, then execute only that item from latest main. Do not change FAQ content, TDK, sitemap, llms, search submissions, fap-web, or production CMS data.`

3. `Run a read-only GSC/GA evidence scan for the MBTI main free-test landing page and produce a planning report only. Do not modify code, PR-train state, sitemap, llms, search submissions, or production CMS data.`

