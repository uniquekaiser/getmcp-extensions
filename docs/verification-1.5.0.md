# GetMCP Extensions 1.5.0 verification

The release candidate was checked against the reviewed GetMCP 1.6.0, 1.7.0 and 1.8.0 profiles using the shared, disposable WordPress fixture. Provider responses used by automated tests were synthetic. Existing fixture data was backed up for restoration.

- `python tools/verify.py` passed PHP syntax and OAuth/access suites on PHP 8.2.30, 8.3.29 and 8.4.16, JavaScript syntax checks, updater contract checks and the public-source inventory.
- The 1.6 and 1.7 admin bundles were rebuilt from their reviewed vendor snapshots and matched the committed bundles. The 1.8 standalone admin scripts passed JavaScript syntax checks.
- On WordPress 7.1.3/PHP 8.3, the packaged 1.8 candidate passed personal OAuth access and isolation (14 checks), personal OAuth over the actual Gateway HTTP and OAuth flow (5), native and mailbox timeout migration (8), paused provider Gateway migration (11), Gateway history review (14), readiness (13), and Health policy migration (2). The packaged updater passed 23 synthetic WordPress updater checks.
- On WordPress 6.2.2/PHP 8.2, the reviewed 1.6 and 1.7 profiles each passed the connection suite (50 checks) and Gateway suite (56 checks). The 1.8 profile passed personal OAuth (14), paused Gateway migration (11), Gateway review (14), readiness (13), and Health policy (2). The final bounded-timeout change affects the 1.8 migration path; its packaged HTTP and mailbox regression passed on WordPress 7.1.3.
- Earlier 1.8 package tests were reused where their relevant code did not change. The final install ZIP was rebuilt and its timeout migration and actual OAuth Gateway HTTP paths were retested.

The checks include per-user credential separation, revocation, resource binding, unknown configuration refusal, endpoint restrictions, unchanged encrypted mailbox settings, paused Gateway preservation and safe handling of missing migration history. Local provider stubs do not establish third-party account permissions or live-provider behavior.

The release workflow, published GitHub assets, WordPress updater and site installation require separate verification after publication. The Test Suite for WP Devs Studio interfaces were unavailable in this session; the definition file records the remaining browser and external-provider gaps explicitly.
