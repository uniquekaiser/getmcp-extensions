# Public delivery and upgrade verification: 1.2.0

Published 2026-10-06 using the local-first syn-release procedure. Developed by [Synergetic Dev](https://synergetic.dev/).

The stable [GitHub release](https://github.com/uniquekaiser/getmcp-extensions/releases/tag/v1.2.0) uses annotated tag `v1.2.0` at commit `bae5172114c6eb11735dc5f9f015ef8008cdd5f7`. All 189 packaged source files agree with that commit after canonical text line endings; the remaining DISTRIBUTION marker is package-generated. The installed ZIP retains its original verified bytes.

| Asset | SHA-256 |
| --- | --- |
| Install ZIP | `6efc1a123e9f91087e16361b96906eb6fe9d0d2c42f89fd8eea9ef71294dc90f` |
| Developer handoff ZIP | `94bc1b223dfcb24ec91eba98a8ba2e642586c41290c79061fd3d3924b52ce5b7` |

The release also contains SHA256SUMS and files.json. Anonymous download bytes match the audited local assets and GitHub's published digests. Both ZIPs pass CRC checks. The refreshed handoff includes the release fixture changes: its baseline-specific patch reconstructs all 41 changed/deleted files, and its complete hash inventory and known-credential/private-context scan pass.

## Passed

- `python tools/verify.py`: 122 PHP files syntax-checked on PHP 8.2.30, 8.3.29 and 8.4.16; 62 OAuth and 33 access assertions on each; 11 JavaScript files; public inventory scan passes. Existing integration/browser evidence is reused for the unchanged install ZIP as documented in [feature verification](verification-1.2.0.md).
- The public updater source/ZIP audit passes all 23 checks. Both owned WordPress fixtures pass the 23 synthetic updater contracts using real WordPress/Plugin Update Checker paths.
- Genuine published 1.1.2 clients discover 1.2.0 without authentication. Fresh update and plugin-details responses include exact asset URLs, WordPress/PHP requirements, publisher, icons and categorized cumulative HTML changelogs. Cached update reads succeed with HTTP explicitly blocked; no new request occurs. All probed icon URLs return HTTP 200 with image/png MIME types.
- The normal WordPress dashboard upgrade succeeds on WordPress 7.1.2/PHP 8.3; the normal WP CLI Plugin_Upgrader succeeds on WordPress 6.2.2/PHP 8.2. Both use MariaDB 11.4.13 and the exact public release asset.
- Ten independent checks per environment verify preserved server records (including settings, allowlists and routing), encrypted upstream connections, Page records, module options, active plugins and owned MU guard. The installed version is 1.2.0, runtime/updater are ready and every installed file matches the published manifest. A second protected-state check also preserves the baseline captured before temporary fixture downgrade.
- Browser release details show 1.2.0 and its categorized changelog. The dashboard reports successful update, and reload shows active version 1.2.0. Both real previous-client captures pass `syn-release runtime: READY target=1.2.0`.
- The published release body passes the canonical GitHub notes gate after anonymous readback. The remote annotated tag resolves to the verified release commit. Both fixture container logs contain zero PHP fatal/parse errors since publication.
- GitHub has zero configured workflows and zero Actions runs. No hosted validation was triggered.

## Skipped and unavailable

Customer deployment, live provider changes, hosted Actions and WordPress.org publication were skipped. The transient request to add a workflow was withdrawn; no workflow or repository protection setting was changed.

Actual Codex Desktop sign-in/tool calls, real Google/Meta consent, refresh and messaging, original React source/type build, other platforms/database/version/edition combinations remain unavailable. Multisite and PHP below 8.2 are unsupported. WordPress 6.2.2 covers the declared release line; exact 6.2.0 was not tested. These limits remain separate from successful public delivery and fixture upgrades.

The release handoff records pre-publication feature evidence; this document and the machine-readable delivery summary record subsequent public verification. No credentials, database exports, customer configuration or protected-state hashes are committed.
