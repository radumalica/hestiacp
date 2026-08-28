# Sprint State

## Current repository state

Development is proceeding through isolated, committed sprints.

Each sprint must follow:

`dev-docs/SPRINT_PROTOCOL.md`

## Completed API v2 sprints

### Sprint 1 — API v2 HTTP Contract
Commit: `5cfdd2498`
Status: COMPLETE

Defined:
- POST `/api/v2/execute`
- authentication contract
- API-owned operation model
- authorization/authentication ordering
- public error model

### Sprint 2 — API v2 HTTP Entry Point & Authentication
Commit: `ab9052b82`
Status: COMPLETE

Implemented:
- HTTP entry point
- Basic authentication
- access-key authentication integration
- operation allowlist
- response mapping
- request handling

### Sprint 3 — Core Operation Exposure
Commit: `7443173c`
Status: COMPLETE

Exposed:
- domain.get
- domain.list
- domain.create
- domain.delete
- database.create
- database.delete
- backup.schedule

Implemented:
- public parameter contracts
- database.delete identifier normalization
- authorization preservation

### Sprint 4 — HTTP Boundary Hardening
Commit: `9ccfad786`
Status: COMPLETE

Implemented:
- JSON validation distinction
- request body size limit
- sanitized exception handling
- stable error envelopes

### Sprint 5 — HTTP Rate Limiting
Commit: `0477a6144`
Status: COMPLETE

Implemented:
- pre-authentication rate limiting
- authenticated rate limiting
- filesystem-backed production storage
- RATE_LIMITED response

### Sprint 6 — API v2 Audit Logging
Commit: `d2806b168`
Status: COMPLETE

Implemented:
- audit event model
- append-only audit logging
- request correlation IDs
- fail-open audit persistence

### Sprint 7 — Audit Log Production Provisioning
Commit: `06047762c`
Status: COMPLETE

Implemented:
- production audit directory provisioning
- fresh-install support
- upgrade support
- logrotate configuration

## Current API v2 surface

Endpoint:

`POST /api/v2/execute`

Currently exposed operations:

- `domain.get`
- `domain.list`
- `domain.create`
- `domain.delete`
- `database.create`
- `database.delete`
- `backup.schedule`

Authentication:

`Authorization: Basic <credential_id>:<secret>`

Actor:

`{ "user": "<authenticated user>" }`

Security ordering:

`rate limit → authenticate → allowlist → validate → normalize → authorize → lock → execute → audit`

Important invariants:

- caller cannot select the actor;
- caller cannot select an executable/script;
- API operations are explicitly allowlisted;
- CommandAdapter remains the execution boundary;
- authorization must happen before lock/execution;
- secrets must never appear in responses, logs, targets, or errors;
- unknown mutation state must not be converted into success or failure without evidence.

## Known deferred API v2 work

- database.list
- database.get
- backup.list
- idempotency
- asynchronous jobs
- Cloud Account / tenancy / roles
- credential expiration
- credential rotation
- advanced audit failure detection
- stale rate-limit counter cleanup
- multi-host rate-limit synchronization
- configurable rate-limit policies
- real-system end-to-end API validation

## Separate upcoming workstream

### Web Server / Hosting Security

This is a separate workstream from API v2.

Planned areas include:

- Nginx template architecture redesign/extension
- ModSecurity integration
- OWASP CRS integration
- additional WAF/security rule providers
- WordPress-focused security profiles
- configurable rate limiting
- caching controls
- PHP execution restrictions
- protection against PHP execution in forbidden/upload directories
- configurable redirect/quarantine behavior
- greater per-domain/server flexibility than current Hestia templates
- future extensibility for custom provider rules

Do not implement this work as part of API v2 unless a future sprint explicitly scopes it.

## Documentation location

All design and implementation documents belong under:

`dev-docs/`

API v2 documents belong under:

`dev-docs/api-v2/`

Do not create new design/implementation Markdown files in the repository root.

## Sprint state maintenance

After every completed sprint:

- update this file with the new sprint;
- record the commit hash;
- record the commit message;
- record the final status;
- update the current API/workstream state where necessary;
- keep historical detail in the sprint-specific implementation document rather than expanding this file unnecessarily.

`SPRINT_STATE.md` is a concise state/index document, not a replacement for detailed sprint documentation.

## Current baseline

Latest completed sprint:

Sprint 7

Latest commit:

`06047762c`

The repository may contain unrelated pre-existing `.tokensave/` changes. These must never be staged or committed unless explicitly requested.
