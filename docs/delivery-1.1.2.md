# Public delivery and upgrade verification: 1.1.2

The public [release](https://github.com/uniquekaiser/getmcp-extensions/releases/tag/v1.1.2) is tagged at `1bf07bd1c474ed5d10d3e227449292f8c0f426c9`. The versioned asset contains 180 files under one `getmcp-extensions/` root. Anonymous download bytes equal the audited local package and GitHub asset digest:

`e64f80ea61f889f8b4fb10fbda2fef84a2790e2eae3e7b39ea9a5f2cbcdc8d16`

All 179 packaged source files match the release commit; the remaining DISTRIBUTION marker is package-generated. SHA256SUMS and files.json are published release assets. The public release body was re-read anonymously and passed the canonical notes audit.

## Passed

- Both genuine published 1.1.1 fixture clients discover public 1.1.2 without a GitHub token. The browser renders the real cached update row; the dashboard updater downloads and installs the exact GitHub release asset successfully on WordPress 7.1.2/PHP 8.3. The WordPress CLI normal plugin upgrader succeeds on WordPress 6.2.2/PHP 8.2.
- Ten independent checks per environment confirm server records, encrypted per-user connections, Page options, module options, active plugins and the persistent guard are preserved; the new runtime is ready, the updater loads and every installed file matches the published manifest.
- After installation, real `plugins_api` responses include version, complete WordPress/PHP compatibility, categorized cumulative HTML changelog, one Synergetic Dev publisher link and icons. All public icon requests return HTTP 200 with image/png MIME types.
- The syn-release runtime contract passes using explicitly labeled snapshots across the upgrade: real previous-client update response plus real corrected-client details response. This does not imply the previous client already formats those details correctly.
- Local PHP/JavaScript/OAuth/access regressions and 23 updater checks per environment pass; see [the detailed report](verification-1.1.2.md) and [machine-readable summary](verification/delivery-1.1.2.json).
- GitHub has no workflows and zero Actions runs. No hosted CI was dispatched.

## Known older-client limitation

Public 1.1.1 can deliver this update but omits details icons and category formatting until the correction is installed. Its invalid-cache fatal path is covered by the 1.1.2 fix. This historical package was not rewritten. Older 1.0.0/1.1.0 distributions have no public updater and need one manual installation of the current versioned ZIP.

## Skipped / unavailable

Customer deployment, live provider writes, hosted Actions and WordPress.org submission were skipped. Remaining provider/platform/build acceptance gaps are listed in the detailed report. Fixtures contain synthetic data; protected baselines, credentials, customer configuration and private machine evidence are excluded from publication. The shared original source/vendor files are preserved.
