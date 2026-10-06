# GetMCP Extensions 1.1.0 verification

Local development only. No customer installation, live provider account, existing live gateway routing or vendor files were modified. Provider writes were synthetic fixtures. No hosted CI, publication or deployment was dispatched.

## Passed

- PHP 8.2.30, 8.3.29, 8.4.16: all add-on PHP syntax checks; existing 62 OAuth and 33 access-service checks on each runtime. These use actual implementation classes with isolated WordPress/database fixtures and are not three real WordPress installs.
- Eight shipped JavaScript files passed local syntax checks. Reviewed adapter generation succeeded, with exact-seam assertions. Original React build/type checks cannot be run because the distributed vendor has no original source.
- Real WordPress 7.1.2 / PHP 8.3 / MariaDB 11.4.13 Docker integration: 64 marketing persistence/identity/import checks; 18 real execution/messaging checks; 12 account-discovery/management/failure checks; 6 marketing lifecycle/HTTP guard checks; existing 56 gateway integration, 26 protocol, 10 HTTP OAuth/access, 3 native listing and 20 module/runtime checks. Total: **215** real WordPress assertions, using synthetic provider HTTP.
- The existing protocol suite covers JSON/SSE, legacy/stateless initialization/version negotiation, session/cache isolation, pagination, structured objects, names/URI routing, templates, malformed responses, redirects, timeouts and mutation no-retry. The new execution suite verifies Page-token host/path restrictions, user/gateway cache identities, a successful synthetic message and an ambiguous write issued once, test-debug token masking, scope revocation/readiness invalidation, safe draft activation and stale management edits.
- Browser: Authentication save/reload flows for the three Google fixtures used blank secret fields. Independent database verification confirmed encrypted secret hashes and offline/consent parameters remained unchanged. Paste Configuration mapped endpoint/name/custom header into editable fields; browser save and a subsequent scope edit preserved the encrypted header without returning its value. The personal Page picker saved a different linked Page, invalidated readiness, verified a harmless conversations read and retained selection after reload. All browser data was disposable fixture data.
- Vendor/shared checkout preservation, install ZIP CRC/exact bytes, baseline and changed-file hashes, feature patch reconstruction, explicit credential/customer-free package inventory: see machine-readable package evidence. These checks are completed by the packaging script, not assumed from runtime tests.

## Failed

No unresolved failure in the completed local suites. Development failures led to fixes for a test-path Page credential bypass, gateway quota-method visibility, old opaque encrypted-credential parsing, current ownership/permission validation, empty structured objects and tool-manager integration. Early fixture failures also required the CLI to share the owned HTTP service network and a permalink flush. Final passing results supersede these failed intermediate runs; they do not imply broader coverage.

## Skipped

- Live sends, publishing, provider account changes, deployment and hosted CI: excluded by the user's authorization boundary.
- PHP 8.0/8.1: outside the standalone add-on's declared PHP 8.2 minimum, not marked passing.

## Unavailable / separate acceptance

- New real Google/Meta consent, actual Meta token-extension request compatibility, real Page messaging reads, official Meta Remote MCP OAuth/client-registration and live dynamic tool exposure. The previously working five-server gateway and dated 98-tool bridge are historical context, not new acceptance proof. Official Meta pages could not be retrieved reliably during this run.
- Vendor original React build/type checks, supported minimum WordPress matrix, multisite, other hosting/OS/database combinations, other licensed editions, real TLS networks and provider limits. Multisite is explicitly rejected.
- ZIP upload/installation through a second clean WordPress browser: runtime used read-only source bind mounts and the package verifier compares every ZIP byte to those files. This is source/package proof, not a staging install.
- New real upstream browser consent/denial/refresh after provider permission revocation: service, database and fault-injection checks cover replay, wrong user/endpoint, expiry, disconnect/configuration races and refresh isolation locally. Provider console/app review remains separate.

## Reproduction

Run locally; do not dispatch hosted CI:

```powershell
python scripts/build-getmcp-addon.py
python scripts/verify-getmcp-addon.py
docker compose -f tests/marketing/compose.yml up -d wordpress
docker compose -f tests/marketing/compose.yml run --rm cli wp eval-file /fixtures/marketing/wordpress.php
docker compose -f tests/marketing/compose.yml run --rm cli wp eval-file /fixtures/marketing/execution.php
docker compose -f tests/marketing/compose.yml run --rm cli wp eval-file /fixtures/marketing/discovery.php
docker compose -f tests/marketing/compose.yml run --rm cli wp eval-file /fixtures/marketing/lifecycle.php
python scripts/package-marketing-addon.py
```

The compose project is explicitly named `getmcp-extensions-marketing-qa`, bound to loopback port 8916 and uses disposable fixture credentials. It requires a legitimately supplied reviewed GetMCP archive extracted to `build/marketing/vendor-getmcp`; that vendor archive is not distributed in the handoff. Initialize WordPress, activate GetMCP then the add-on, enable the fixture license option, set pretty permalinks/flush, and ensure only this owned fixture's MU directory is writable by WordPress. Never point fixture scripts or synthetic license hooks at a customer site. Existing gateway/protocol/HTTP/native-list/runtime scripts are included; run them in the same owned fixture. Browser verification uses the current UUIDs generated by the fixture, disposable users and a before/after hash baseline, not provider credentials.

Artifacts preserve separate original OAuth prerequisite and gateway reference patches. The new marketing/setup patch targets the exact standalone 1.0.0 baseline, not a vendor checkout. Building adapters also needs the privately supplied immutable base ZIPs described by the generator; the installable add-on already contains generated adapters and needs no generator at runtime.
