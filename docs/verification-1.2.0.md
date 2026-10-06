# GetMCP Extensions 1.2.0 local verification

Prepared 2026-10-06. Installable local handoff; not published or deployed to a customer site. Developed by [Synergetic Dev](https://synergetic.dev/).

## Passed

| Check | Environment | Result |
| --- | --- | --- |
| PHP syntax, OAuth and user access | PHP 8.2.30, 8.3.29, 8.4.16 | 122 PHP files; 62 OAuth and 33 access assertions on each |
| JavaScript syntax and public inventory | Node 24.15.0, local source | 11 JavaScript files; no known real credential/private-context patterns |
| New connection/template contracts | WordPress 7.1.2 / PHP 8.3 and WordPress 6.2.2 / PHP 8.2, MariaDB 11.4.13 | 50 assertions per environment |
| Existing marketing/gateway/protocol/access/runtime regressions | Both WordPress environments | 215 assertions per environment |
| Public token-free updater contracts | Real PUC/WordPress with synthetic GitHub HTTP, both environments | 23 assertions per environment |
| Final ZIP installation | Both environments | 10 checks each: version/runtime/updater, exact installed bytes and preserved servers, upstream credentials, Page records, modules, active plugins and owned guard |
| Browser-created native template | WordPress 7.1.2 / PHP 8.3 | 4 independent database checks: draft, 3 tools, no credentials, deny-all personal access |
| Architecture map | Local Graphify AST only | 271 nodes, 364 edges, 19 communities; zero model/API calls |

The 215 existing regressions were rerun during this change and reused after final changes confined to the new client metadata/template UI/empty-target guard. The 50 new checks and 10 package checks ran on the final runtime ZIP in both environments. Updater code was unchanged; its synthetic fixture was updated for the new version. Coverage is 298 cumulative WordPress assertions per environment, plus the independent browser/template proof; counts are not a claim that every test ran repeatedly on every intermediate ZIP.

New checks cover selected/unsupported entries, duplicate names, partial results, retries/replay, encrypted preview rollback, secret preservation/redaction, original gateway publication staying off, manager/subscriber/anonymous access, current gateway membership, administrator exclusion, inbound Codex metadata, JSON/TOML multi-server conversion, command-text redaction, five templates, native UI description preservation, existing credential protection, messaging inactivity, licensing and module disabling.

Browser flows completed through the real local WordPress UI: import two servers in one paste and rename one; confirm both persisted; create a gateway while Remote MCP is selected, return to the correct gateway list and reload; see project gateways beside original gateway settings; Add Remote MCP from My MCP Connections opens the existing editor; convert two JSON entries to TOML with a redacted header; native server Codex Quick Connect renders the inbound URL and copies config.toml; Authentication save with blank secret followed by reload preserves hashes of advanced configuration, secrets, settings and status; all five marketing templates appear; install Google Ads through the native template modal and independently verify draft/credential/access state.

## Resolved findings

The reported two gateways were already present in the named site's database and REST listing. That site was inspected read-only; no records were repaired or replaced. The add-on addresses the stale Remote MCP/search filter and adds project navigation to the singular original Gateway screen.

Browser testing found that GetMCP's template catalogue is static, with the index API used only for tool counts. An owned, hash-reviewed UI seam now appends the credential-free catalogue. Testing also caught the native UI's presentation description stored in settings; the installer now preserves it while rejecting actual configuration and existing capabilities. An old hardcoded version in the synthetic updater fixture was corrected. These findings pass their regressions; no unresolved functional test failure remains.

## Commands and evidence

```powershell
$env:GETMCP_VENDOR_ROOT = 'C:\path\to\owned\original-getmcp-1.6.0'
$env:GETMCP_UI_VENDOR = $env:GETMCP_VENDOR_ROOT
$env:GETMCP_QA_VENDOR = $env:GETMCP_VENDOR_ROOT
python tools/build-ui.py
python tools/verify.py
python tools/build.py
python tools/test-connections.py
python tools/graph.py
```

Initialize the owned fixtures as described in TESTING.md; never run their license/provider hooks against a customer site. Install `/packages/getmcp-extensions-1.2.0.zip` using each fixture's local WP CLI, then run `/fixtures/connections-package.php before/after` around replacement, `/fixtures/connections-wordpress.php` and `/fixtures/release/updater-contract.php`. Browser checks and their independent hash/template readers are separate from isolated fixtures. The handoff contains changed files, a baseline-specific feature patch, hashes, evidence summaries and the install ZIP; prior OAuth/access/remote/provider work remains in its independently reviewable public baseline.

## Skipped and unavailable

- Skipped: customer deployment, live provider changes, GitHub publication and hosted CI. No Actions workflow was added or dispatched.
- Unavailable: actual Codex Desktop account sign-in/end-to-end tool calls for this new UI, real Meta/Google consent and token renewal/messaging, other hosting/OS/database/version combinations and licensed editions, original React source build/type checks. Generated client configuration, permissions and fixture execution are verified separately.
- Unsupported: multisite and PHP below 8.2. WordPress 6.2.2 covers the declared 6.2 line; exact 6.2.0 is untested. The converter covers three portable output formats, not the reference project's complete dialect list; it does not execute or install command servers.

No database schema change or credential migration is introduced. Restore the genuine 1.1.2 add-on ZIP for rollback, retaining the database, salts, enhanced definitions and current owned MU guard. See connections-1.2.0.md for template prerequisites and integration/retirement behavior.

The developer ZIP's complete hash inventory was verified. Its feature patch applies cleanly to the public baseline and reconstructs all 41 changed/deleted files with matching canonical text hashes. This validation uses an isolated copy and does not modify the shared baseline or Git history. Release preparation adds owned-fixture tests for the genuine 1.1.2 to 1.2.0 upgrade and cached public metadata; it does not change the audited install ZIP. Publication and normal-upgrade results are recorded separately after public delivery.
