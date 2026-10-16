# Local testing

Tests must use owned, disposable environments. Do not run fixture license hooks, Page/message writes or lifecycle tests on customer sites. Provider HTTP is synthetic; a fixture write is not live provider acceptance.

## Checks

```powershell
$env:GETMCP_VENDOR_ROOT = (Resolve-Path '.\vendor-getmcp').Path
$env:GETMCP_UI_VENDOR = $env:GETMCP_VENDOR_ROOT
python tools/verify.py
python tools/build-ui.py
python tools/build.py
python tools/graph.py
```

`verify.py` uses installed PHP 8.2/8.3/8.4 runtimes on Windows, or PHP from PATH on other systems. It syntax-checks authored and dependency code, runs the existing OAuth/access suites when a reviewed vendor root is supplied, checks JavaScript, and rejects known secret/private-context patterns. Missing vendor auth fixtures are unavailable, never passing. GetMCP is a separately acquired dependency and is not distributed as a fixture.

Use only the shared Compose project `getmcp-telegram-qa` at `http://localhost:8932`, defined by `../getmcp-telegram/tools/compose.yaml`. Inspect and preserve its existing accounts, sessions, credentials and definitions. For sequential compatibility matrices, snapshot and temporarily reconfigure this same stack, restore its normal mounts/data, and remove temporary overrides. Do not launch the historical per-version release projects or the legacy `tools/test-connections.py` runner, which creates separate projects.

For Google Health and self-cleaning connection/catalogue regressions, run `python tools/test-google-health.py`. Google responses are synthetic; authentication, native installation/execution, privacy logs and data cleanup run against the real shared WordPress database. See [Google Health](docs/google-health.md).
After initialization/activation, run relevant `wp eval-file /fixtures/marketing/wordpress.php`, `execution.php`, `discovery.php`, `lifecycle.php` and gateway protocol/access scripts. They can create/delete fixture-owned definitions and temporarily toggle modules/activation. Enable pretty permalinks and flush before HTTP tests.

For 1.2.2, test both reviewed core snapshots. GetMCP 1.6.0 loads the legacy React app from `build/`; GetMCP 1.7.0 loads its isolated app and lazy chunks from `compat/versions/1.7.0/build/`. Build each against its exact vendor snapshot, with 1.6 built first and 1.7 second, then package once. Test the resulting ZIP sequentially in the single shared fixture with a snapshot and temporary configuration for each vendor/image pair. It runs the OAuth, access, marketing, gateway, import, protocol, and lifecycle regressions plus `connections-wordpress.php`. Browser checks separately cover direct portal routes, remote-server forms, draft save/reload, masked custom headers, gateway listing, and native Quick Connect. Fixture credentials and endpoints are synthetic.

## Coverage

Historical 1.1.0 local proof: 215 real WordPress 7.1.2/PHP 8.3/MariaDB assertions with synthetic provider HTTP; 62 OAuth and 33 access checks on each of PHP 8.2/8.3/8.4; Authentication/import/Page browser save/reload flows; package hashes and reconstructable patch. See docs/verification-1.1.0.md for limits.

Current compatibility and test evidence is in docs/verification-1.2.2.md. Public 1.1.2 distribution proof remains in docs/verification-1.1.2.md; older results remain in their historical reports. Requirements, dependency boundaries, exact release assets, fresh/cached updater metadata and a normal fixture upgrade must be checked for publication. The published 1.2.0 distribution is the previous update client. Use its genuine package, `prepare-upgrade.php` to baseline protected-state hashes, and `verify-upgrade.php` after a normal upgrade. `updater-contract.php` uses synthetic GitHub metadata; `capture-update-proof.php` explicitly checks public release and image URLs.

Unavailable coverage must be recorded separately: real provider consent/Meta extension and Page messaging, other OS/hosting/database/editions, multisite (unsupported), original React source build, and untested WordPress versions. PHP 8.0/8.1 are outside the declared minimum. No hosted workflows are configured or dispatched.
