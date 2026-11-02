# GetMCP Extensions 1.6.0 verification

The 1.6.0 install candidate was checked locally using the shared disposable WordPress fixture. Its 260 files matched the package inventory, and the ZIP passed CRC validation.

- `python tools/verify.py` passed PHP syntax and OAuth/access suites on PHP 8.2.30, 8.3.29 and 8.4.16, JavaScript syntax checks, updater checks and public-source inventory checks. The 1.6 and 1.7 admin bundles were rebuilt from reviewed vendor snapshots and matched the committed output.
- On WordPress 7.1.3/PHP 8.3.28 and WordPress 6.2.2/PHP 8.2.8, the install candidate with GetMCP 1.8 passed Oura consent and PKCE (3 checks), per-user access, token rotation and privacy (24), and public upstream Gateway access and refusal (10). Provider responses were synthetic.
- With GetMCP 1.6 and 1.7 on both WordPress/PHP combinations, the shared fixture passed connection (50), Gateway (56), provider configuration (64) and provider execution (18) checks. The final package also passed provider configuration and execution checks on each combination.
- A synthetic future GitHub release passed 23 updater contract checks, including the exact ZIP asset, cached metadata, WordPress update transient and plugin details changelog. The fixture version was newer than this candidate, so the check exercised an actual available update.
- The first older-Gateway and provider tests lacked their synthetic upstream and provider fixtures; after restoring the disposable database and installing those fixtures, the affected tests passed. The first minimum-version package run needed the GetMCP schema migration; after applying the migration in the disposable fixture, all package tests passed.

The checks cover separate user grants, credential-free public requests, explicit project membership, revocation, endpoint and method changes, cache and replay exclusions, state replay, successful refresh rotation and an interrupted single-use refresh. The shared fixture database was restored byte-for-byte apart from its dump timestamp, and its original source mounts and stopped state were restored.

Real Oura authorization and provider data access, browser-based Studio scenarios and hosts outside the tested WordPress/PHP combinations remain separate acceptance checks. The formal GitHub release workflow, published assets and WordPress updater require verification after publication.

PHP_CodeSniffer and WordPress Coding Standards were not installed locally; their security sniffs were unavailable. The changed authentication and delegation boundaries were reviewed and exercised by the focused positive and refusal tests above.
