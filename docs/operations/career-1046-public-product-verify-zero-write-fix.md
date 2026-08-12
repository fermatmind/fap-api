# Career 1046 public product verify zero-write fix

The global slow-query listener must not write an application warning for an authenticated Career 1046 verify-only read. It asks the existing `CareerVerifyOnlyRequestAuthorizer` to validate the method, exact Career directory/detail path, marker, APP_KEY HMAC, and short-lived timestamp before suppressing the log.

Slow-query logging remains enabled for ordinary traffic, unsigned or forged requests, expired signatures, and signed requests outside the exact Career verification paths. This change does not alter public Career responses, cache/pointer authority, generation behavior, or workflow execution.

The Task 7A workflow remains manual-only and unexecuted. Production/staging access, deployment, database/CMS/cache writes, and discoverability/Search actions are deferred to separately authorized operations.
