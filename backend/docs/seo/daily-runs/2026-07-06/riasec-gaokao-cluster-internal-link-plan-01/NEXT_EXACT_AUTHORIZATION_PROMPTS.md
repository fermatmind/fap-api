# Next Exact Authorization Prompts

## Continue Read-only Train

Authorize Codex to execute:

`RIASEC-GAOKAO-CLUSTER-CONTENT-PACKAGE-HANDOFF-01`

Scope:

- generated docs only
- package the selected brief and internal-link plan into an operator handoff
- do not write CMS, publish content, mutate links, sitemap, llms, schema, metadata, canonical, noindex, Search, runtime, or deploy state

## Future Link Mutation Authorization

Only after CMS/content owner approval, authorize a separate PR:

`RIASEC-GAOKAO-CLUSTER-INTERNAL-LINK-IMPLEMENTATION-01`

Required constraints:

- explicit source and target URLs;
- path-limited CMS/import/runtime scope;
- no private URL targets;
- no uncreated routes;
- claim boundary review before merge.
