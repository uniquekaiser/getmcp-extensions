# GetMCP Extensions 1.6.1 verification

The development site reported GetMCP 1.8.1-beta2 with Extensions 1.6.0. The add-on did not recognize the core files and correctly kept affected endpoints blocked. The supplied beta archive has SHA-256 `1507aa04eecfb25112ee647c2c99733f7742e595f6a89550ebbcac42eb4b9c4c`; its 227 compatibility files, including all 34 admin build files, matched the observed development installation.

The candidate adds a separate, exact beta profile. It retains the reviewed 1.8 migration safeguards and the native beta admin bundle, and replaces the beta server controller and tool executor with separately pinned adapters. Those adapters include the beta's connector license gate, request and replay credential redaction, and embedded-file handling as well as the existing Extensions behavior. Remote MCP requests also use the reviewed native DNS-pinning transport. A different or modified build must remain unready and its affected endpoints must stay guarded. The compatibility notice's garbled arrow is corrected.

The baseline GetMCP 1.8.0 package has SHA-256 `6e97570dce54a8cd41d7e349938377dc88ea4a2bb341cdf45fc0be6abf3a9d6e`.

## Local verification, 2026-11-13

- The exact beta passed the local loaded-runtime checks, including request and replay redaction, binary delivery, tier refusal, anonymous denial and the native admin bundle. A deliberately altered beta remained unready, refused migration and blocked protected connections. The remote transport test verified DNS pinning and refused private, insecure and reserved-header requests.
- Synthetic per-user provider, fresh-install grant-store, consent, Gateway boundary and public-upstream checks passed. A synthetic 1.8.0-to-beta upgrade preserved the server row, settings and both encrypted grant rows; both original grants remained decryptable and a different user's lookup returned no grant. Public-Gateway checks used an explicitly synthetic Pro license.
- A locally built package passed the beta checks and the remote-transport test. The packaged add-on also loaded against the reviewed 1.8.0, 1.7.0 and 1.6.0 cores; the latter two passed their existing runtime and access checks. The final local archive was verified to differ from that tested package only in the corrected notice arrow, which was checked separately. WordPress fixture: 6.8.3, PHP 8.3.28 and MariaDB 11.4.
- `python tools/verify.py` passed from an isolated source copy on PHP 8.2.30, 8.3.29 and 8.4.16, including the existing synthetic OAuth and user-access suites, PHP syntax and JavaScript checks. The added upgrade fixture also passed PHP syntax checks on all three versions. Test-definition JSON passed its bundled schema and source-reference checks.
- The shared fixture's original database dump matched after its temporary tables were removed; its normal mounts and stopped state were restored. The only package generated here is a local verification artifact using the existing version number, not a published release.

The development site reported WordPress 7.1.4 and PHP 8.3.36; the candidate was not installed there. Local checks do not exercise real provider consent, customer credentials, live licensing or browser acceptance. Studio browser scenarios remain gaps until the current snapshot is imported, reviewed and executed. Hosted compatibility and release verification remain open.

## Release candidate preflight, 2026-11-13

- Built the 1.6.1 install archive and tested its real WordPress upgrade from the published 1.6.0 ZIP (SHA-256 `5a4527868d8807843344d50b7664c9fbab5b366f537b16448fea75a727dfe902`). The isolated fixture used WordPress 7.1.3, PHP 8.3 and MariaDB 11.4.
- The previous packaged updater passed 23 fresh, cached, details, icon and refusal assertions using synthetic release metadata for 1.6.1. A synthetic server, settings and two encrypted grant stores survived the packaged add-on upgrade and GetMCP 1.8.0-to-1.8.1-beta2 transition; six preservation and isolation assertions passed.
- On the beta and installed 1.6.1 package, 23 compatibility and execution assertions and seven DNS-pinning and refusal assertions passed. Deactivation followed by activation succeeded and the reviewed runtime was ready.
- The shared fixture was restored to its prior stopped configuration. Its database dump matched byte for byte after removal of the temporary fixture tables.

The updater metadata was synthetic during preflight. Real GitHub publication, the hosted site upgrade, live-provider calls and browser scenarios had not yet been verified when this report was written.
