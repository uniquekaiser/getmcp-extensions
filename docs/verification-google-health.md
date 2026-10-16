# Google Health local verification

Status: implementation and local synthetic-provider acceptance passed. Real Google acceptance remains **Partial**. No release, hosted workflow, production deployment or upstream health write was performed.

## Environment and commands

The existing `getmcp-telegram-qa` fixture at `http://localhost:8932` ran GetMCP 1.7.0, WordPress 7.1.3, PHP 8.3.28 and MariaDB 11.4. Its canonical source mounts and existing data volumes were reused. No parallel fixture or temporary Compose override was created.

| Check | Command | Result |
| --- | --- | --- |
| Google Health integration | `python tools/test-google-health.py` / `tests/google-health-wordpress.php` | 91 passed |
| Connection/template regression | Same runner / `tests/connections-wordpress.php` | 50 passed |
| Hosted catalogue regression | Same runner / `tests/remote-catalogue.php` | 28 passed |
| Upstream OAuth regression | Same runner / `tests/remote-catalogue-oauth.php` | 8 passed |
| PHP lint / existing auth and user-access tests | `python tools/verify.py` with the owned GetMCP 1.7.0 vendor root | 140 PHP files checked on PHP 8.2.30, 8.3.29 and 8.4.16; 62 security + 33 access assertions passed on each runtime |
| JavaScript syntax / source inventory | Same verifier | 45 JavaScript files passed; no source inventory failures |
| Feature definitions | JSON Schema validation against the WordPress developer definition schema | Passed; Studio scenarios remain explicit gaps |
| Code map | `python tools/graph.py` | Passed; code-only extraction, no semantic/model work |
| Local package | `python tools/build.py` and ZIP source-parity checks | Passed; twelve read-only tools, no write scopes, no tests/evidence/browser files in the ZIP |
| Whitespace | `git diff --check` | Passed |

Total shared WordPress checks: **177**. The standalone security/access assertions are additional and use their own PHP/WP/DB fixtures; they are not browser consent or production proof.

## Behavioral evidence

The health suite installs the template through native REST, refuses nonempty targets, checks all twelve read-only annotations and tests native query/body mappings against synthetic upstream contracts. Daily rollups use the official `range.start/end.date` structure. It exercises direct reads, nutrition, civil-date daily/seven-day summaries, empty and omitted protobuf lists, malformed lists, repeated and bounded pagination, missing scopes, token refresh, two-user data isolation, invalid inputs, endpoint tampering and safe upstream errors.

Privacy is checked with verbose response logging and a global cache TTL enabled: real protocol/database records contain metadata only, with neither health payload nor replay body. The admin Test path returns filtered MCP output without raw responses or credentials. Project gateway routing uses the personal grant; removing membership prevents any Google request. Removing user access or disconnecting the account also refuses the read without fallback. Unrelated server and encrypted connection state are compared before/after.

Playwright browser checks on the same fixture covered template search, twelve-tool draft installation, Authentication redirect, synthetic client ID/secret save and reload, preservation of read scopes and offline-consent parameters, plus the template card at 390px width. The saved-credential readback exposed the initial-client-ID reset issue; the fixed path was retested through native REST and browser save/reload. Browser consent at Google was not attempted. Screenshots are retained under `evidence/google-health/` locally.

Disposable servers, users, grants and logs were removed; only the original fixture users remain. The shared containers remain available with their original source mounts. Existing accounts, Telegram sessions and credentials were preserved.

## Preview artifact and remaining gaps

Local preview: `dist/getmcp-extensions-google-health-preview.zip`, 1,162,755 bytes. SHA-256:

```text
161f825e04e77bb7c0a1db4dd4fe779347ab6adbc8b8872ada0bea586da39d5f
```

The preview retains development version 1.3.0; it is not a newly published release. Package bytes for the health module, authentication code, native tool asset, both executor adapters and protocol handler were compared with tested source. A normal ZIP installation/upgrade was not exercised in this task; the shared fixture executed the source mount.

Unavailable: real Google web application consent, token issuance/refresh, account eligibility and populated data (including nutrition); live MCP client transport acceptance against Google; GetMCP 1.6.0 runtime acceptance of the new integration; minimum WordPress/PHP runtime matrix for the health flow; other hosting/database platforms. PHP syntax and existing standalone auth checks do not establish those runtime environments. Studio extraction/bindings/receipts have not been created. Multisite remains unsupported.

Follow [Google Health setup](google-health.md) for the account connection and final read acceptance.
