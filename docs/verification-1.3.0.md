# GetMCP Extensions 1.3.0 verification

Developed by Synergetic Dev — https://synergetic.dev/

The change range from v1.2.4 adds the separate hosted MCP catalogue, current-user defaults, Save & Authorize / Save & Test, responsive connection headings, distinct native routes and content-hash adapter versions. No database or credential migration occurs. See [feature verification](verification-remote-catalogue.md) and [setup and rollback](remote-catalogue.md).

## Passed locally

- PHP 8.2.30, 8.3.29 and 8.4.16: syntax checks plus 62 OAuth and 33 access assertions each (`python tools/verify.py`).
- JavaScript syntax checks, six editor/route scenarios and six safe importer unit tests.
- Real WordPress 7.1.3 / PHP 8.3.28 / MariaDB 11.4: 28 catalogue/access/cache assertions and eight synthetic OAuth assertions; no real provider requests.
- Actual browser: catalogue, creator defaults, narrow layouts, saved gateway/remote lists, separate selected menu entries, Back/Forward and reload.
- Reproducible runtime package, content inventory and credential/customer-pattern audits. Both generated adapters have distinct routes; original vendor plugin files untouched.
- Code-only Graphify map and canonical test-definition schema validation.
- Genuine published 1.2.4 package on the shared fixture: 23 WordPress/PUC updater contract checks for 1.3.0, including fresh/cached metadata, HTML plugin details, icons, exact asset selection and refused archive/foreign-host fallbacks. Synthetic GitHub responses; public delivery remains a separate check.

## Distribution verification

The release uses the existing single tag-triggered GitHub Action and its canonical install/handoff builders. Exact-tag Actions, anonymous published assets, note readback, public updater fresh/cached details, icons and a genuine 1.2.4 to 1.3.0 upgrade are verified separately after publication and recorded in local release evidence. Pending publication is not passing evidence.

## Skipped and unavailable

No customer deployment or provider account changes. This is a GitHub distribution without a WordPress.org listing. Live consent and harmless reads for all 274 providers, multisite, WordPress 6.2 database/browser acceptance, GetMCP 1.6 browser acceptance and immutable Studio receipts remain unavailable. Individual provider verification is required. No unperformed matrix is represented as passed.

## Local fixture policy

Reuse only `getmcp-telegram-qa` at localhost:8932 using the Telegram tools Compose file. Package tests temporarily replace the Extensions mount, then restore it; connected account/session volumes are preserved. Do not create parallel GetMCP stacks. See AGENTS.md.
