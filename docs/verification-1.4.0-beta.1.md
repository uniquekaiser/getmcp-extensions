# GetMCP Extensions 1.4.0-beta.1 verification

Local prerelease preparation passed on 2026-10-16. The release-workflow change was explicitly approved on 2026-10-16 and applied after local proposal checks. The exact-tag hosted publication gate follows local preflight; no remote-site installation is included in this release task.

The Google Health implementation and account boundaries are documented in [Google Health](google-health.md). The full behavioral coverage and unavailable real-provider acceptance are documented in [implementation verification](verification-google-health.md).

## Local results

- `python tools/test-google-health.py`: 177 shared-fixture checks passed (91 Google Health, 50 connection/template, 28 catalogue, 8 upstream OAuth). The existing `getmcp-telegram-qa` stack was restarted using its existing containers and volumes. The first attempt ran before MariaDB was ready and returned a database-connection environment error; the post-readiness rerun passed all four suites. No parallel stack was created.
- `python tools/verify.py` with the owned GetMCP 1.7.0 root: 140 PHP files linted on PHP 8.2.30, 8.3.29 and 8.4.16; 62 auth/security plus 33 access assertions passed on each runtime; 45 JavaScript syntax checks passed; no source inventory failures.
- `python tools/build.py`: deterministic twelve-tool prerelease ZIP built, root/CRC/version/distribution marker and tested-source parity verified. No write scopes, tests, evidence or browser artifacts enter the install ZIP.
- Canonical prerelease changelog generated and exact GitHub notes validated with `syn-release`.
- Proposed workflow checked locally against beta and stable metadata and publication flags: four checks passed. The proposal accepts alpha/beta/rc tags, publishes prereleases with `--prerelease --latest=false`, retains stable `--latest` behavior, and leaves the one tag-triggered release path unchanged. The approved proposal is now the release workflow; its triggers and permissions are unchanged.
- `git diff --check`: passed.

Package: `getmcp-extensions-1.4.0-beta.1.zip`, 1,163,007 bytes. SHA-256:

```text
f93020b98bcfb6f729bb8e0efea9f98843d63beec4e9f26e31991f270670f59e
```

## Remaining acceptance

The established release workflow must run once on the approved exact prerelease tag, build the credential-free developer handoff, and publish audited assets with prerelease status. The normal updater deliberately ignores prereleases; test installation is manual. The earlier browser proof covers the unchanged health UI, while the new version has been checked through local backend/package tests. Normal install/upgrade of this prerelease ZIP and testing on the intended remote site are pending.

Real Google OAuth consent, issued-token refresh, account eligibility and populated nutrition/health data remain unverified. GetMCP 1.6.0 health runtime acceptance, the minimum WordPress runtime matrix and other hosting/database platforms remain unavailable. No claim of production readiness is made for this prerelease.
