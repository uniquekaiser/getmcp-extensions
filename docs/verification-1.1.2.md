# GetMCP Extensions 1.1.2 verification

Prepared 2026-10-06. This release corrects the public updater's final WordPress metadata and cached-package handling. Customer installations and provider accounts were not changed.

## Passed before publication

- `python tools/verify.py`: 114 PHP files pass syntax checks on PHP 8.2.30, 8.3.29 and 8.4.16; 62 OAuth and 33 access assertions pass on each runtime. Nine JavaScript files pass syntax checks. The public inventory scan finds no known credential/private-context patterns.
- Install the actual 1.1.2 ZIP in owned WordPress 7.1.2/PHP 8.3 and WordPress 6.2.2/PHP 8.2/MariaDB 11.4 fixtures. All 23 updater assertions pass in each: real WordPress/Puc paths with synthetic GitHub HTTP, final details icons, categorized HTML, one publisher link, fresh/cached updates, exact assets, no client authentication, tampered-cache rejection without fatal errors and unrelated-plugin isolation.
- The 215 marketing/gateway/protocol/OAuth/access/module assertions per WordPress matrix and browser Authentication save/reload/secret preservation from 1.1.1 remain valid for unchanged feature code. See the historical verification report for exact scope.
- Graphify authored-code AST map: 227 nodes, 299 edges, 18 communities; generated/dependency code excluded; no external model/API calls.
- Reproducible package build: single installed root, runtime dependency/legal files and compatibility manifest, development files excluded, exact hash inventory and CRC.

## Publication and normal upgrade

Final public anonymous download, metadata/image response, cached metadata and normal dashboard/CLI upgrades from the genuine published 1.1.1 package are recorded in [the delivery report](delivery-1.1.2.md). Baselines retain only hashes of protected server, credential and Page records, module options, active plugins and the persistent guard.

The older 1.1.1 updater can deliver the valid new package, but its details formatting cannot gain the missing icons until 1.1.2 is installed. The final-format gate runs on the corrected client; previous-client delivery and the normal upgrade are separate evidence. Real 1.0.0/1.1.0 packages require one manual bootstrap installation.

## Failed, skipped and unavailable

The defects found in the initial public 1.1.1 details/cache verification are fixed and regressions pass in 1.1.2. Publication proof remains a separate step, never inferred from synthetic HTTP.

Production/customer deployment, live provider writes, hosted Actions and WordPress.org submission are skipped. No hosted workflows are configured. Real provider consent/token extension/Page messaging, original React build/type checks, intermediate hosting/OS/database/version combinations and other licensed editions are unavailable for this release. Multisite is unsupported. PHP 8.0/8.1 are below the declared minimum. WordPress 6.2.2 covers the 6.2 line; exact 6.2.0 was not exercised.
