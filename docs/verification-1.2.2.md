# GetMCP Extensions 1.2.2 compatibility verification

Developed by Synergetic Dev — https://synergetic.dev/

## Scope

This build adds a reviewed `vendor-original-1.7.0` profile and 1.7-specific PHP class adapters while retaining the 1.6 profile. The published add-on bundle now keeps the 1.6 app under `build/` and the 1.7 app and its lazy chunks under `compat/versions/1.7.0/build/`; each runtime selects its matching bundle. No GetMCP vendor files, server records, upstream credentials, user allowlists, or gateway membership were changed.

## Passed locally

- Exact GetMCP 1.6.0 and 1.7.0 vendor asset inventories are hashed in `compatibility.json`; 1.7 class overrides are profile-specific.
- Disposable Docker WordPress integration on WordPress 7.1.2 / PHP 8.3 / MariaDB with GetMCP 1.6.0 and 1.7.0. The run covers native OAuth, access restrictions, remote connections, REST providers, marketing readiness and credentials, resources/tools/prompts, per-user token boundaries, migrations, module switches, import/export redaction, and protected HTTP requests.
- The same Docker integration suite on the minimum stack, WordPress 6.2.2 / PHP 8.2 / MariaDB, for both GetMCP 1.6.0 and 1.7.0. Each of the four core/stack combinations passed all 11 suites; results are recorded separately under `evidence/` and `evidence/minimum/`.
- Browser rendering of direct `My MCP Connections` and `Gateways & Remote MCP` admin URLs in both core versions. On both 1.6 and 1.7, a draft Remote MCP definition with a dummy custom header saved and remained present after reload; the stored header value was masked. The Add Remote MCP form exposed Server URL and Paste configuration, per-user OAuth, automatic discovery guidance, and encrypted custom-header fields.
- Static PHP syntax passed for 134 files on PHP 8.2.30, 8.3.29, and 8.4.16; 62 native OAuth and 33 access-control assertions passed on each runtime. JavaScript syntax passed for all 42 checked files; the repository credential/private-context scan found no issues. UI-bundle and ZIP integrity checks passed.
- The genuine public 1.2.0 package was used as the previous client in all four Docker stacks. Normal installation to 1.2.2 preserved server records, upstream connections, pages, module settings, active plugins, and endpoint guards (10/10 assertions per stack); installed package bytes matched the candidate ZIP.
- The real WordPress update transient and `plugins_api` details were verified from 1.2.0 on GetMCP 1.6 and 1.7 (23/23 assertions each), including cached metadata, compatibility fields, icons, categorized HTML details, rejection of foreign/malformed packages, and no source-archive fallback.
- Candidate install ZIP: 232 entries, CRC check passed, SHA-256 `77e4613ea8ccd3b3703e6e293ff46420fbc096748504394835826a14e1b47487`.
- Synthetic provider fixtures only. The messaging tests use fixture writes and enforce the existing harmless-read/readiness gates before the messaging drafts can activate.
- The release-upgrade fixture now uses the published 1.2.0 package as its previous-client baseline.

## Not performed / unavailable

- No deployment to `novamira-wpdev-synergetic`, no live provider account changes, and no live Google/Meta consent, token renewal, Page messaging, or real upstream write.
- A Playwright run in the disposable GetMCP 1.7 WordPress fixture verified the Authentication tab after reload: the WordPress sign-in panel, server-stored credential controls, and Client Authentication selector are all visible. The selector retains None, Bearer Token, API Key, Basic Auth, and OAuth 2.0. With GetMCP WordPress sign-in active, only the irrelevant external identity-provider fields are replaced by a clear note; the misleading missing-provider warning is gone. Saving and reloading the allowed-user list retained user ID 1 and kept the native controls visible.
- This document records the local pre-publication verification state. The tag-only GitHub release workflow is configured, but its hosted run and published-asset verification are recorded after publication; no hosted check is claimed here.
- No tests on multisite, PHP below 8.2, other hosting vendors, licensed editions, or the GetMCP original React source/build pipeline.
- No check of the upstream auth handshake for the disposable `example.org` endpoint was made; it remained a draft and was not published or used for upstream requests.

## Migration and rollback

The compatibility addition is additive and does not introduce a new database schema or credential migration. Back up the site database/files before installation and retain the WordPress salts and the add-on-owned MU endpoint guard. To roll back, reinstall the previously active GetMCP Extensions ZIP; do not remove the database tables, encrypted options, allowlists, or MU guard as part of a code rollback. This compatibility ZIP remains a local handoff artifact until the reviewed tag-only release workflow publishes it.

## Evidence index

- `evidence/local-checks.json`: local PHP 8.2/8.3/8.4 syntax/auth/access, JavaScript, and credential scan outcomes.
- `evidence/updater-contract.json`: real WordPress update transient and details checks for GetMCP 1.6 and 1.7.
- `evidence/connections-1.2.2-getmcp-1.6.0/` and `evidence/connections-1.2.2-getmcp-1.7.0/`: current-version Docker suite outputs.
- `evidence/minimum/connections-1.2.2-getmcp-1.6.0/` and `evidence/minimum/connections-1.2.2-getmcp-1.7.0/`: minimum-version Docker suite outputs.
- ZIP hashes are in the handoff's `SHA256SUMS`; the source manifest records baseline and resulting file hashes.
