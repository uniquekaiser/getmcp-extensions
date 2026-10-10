# GetMCP Extensions 1.2.3 candidate verification

Developed by Synergetic Dev — https://synergetic.dev/

## Change under test

GetMCP 1.7.0 renders Client Authentication choices in a custom listbox capped at 108px, which hides the new WordPress sign-in option below the initial viewport. The add-on now expands only that listbox to up to 240px, bounded by the available viewport space. The options remain native GetMCP values plus WordPress sign-in (GetMCP).

## Passed locally

- `node --check admin/native-oauth-authentication.js`.
- `python tools/verify.py`: PHP lint and OAuth/access regressions passed on PHP 8.2, 8.3 and 8.4; all checked JavaScript files passed; public-source credential scan passed.
- `python tools/test-connections.py` against reviewed GetMCP 1.7.0: all 11 WordPress 7.1.2 / PHP 8.3 suites passed, and all 11 WordPress 6.2.2 / PHP 8.2 minimum-stack suites passed.
- Browser save/reload fixture on WordPress 7.1.3 / PHP 8.3.35 / GetMCP 1.7.0: existing WordPress sign-in remained selected, the allowed-user record remained present, and the opened menu showed all six choices at once (216px rendered height; no internal scrolling required).
- Live cache inspection on WordPress admin: the HTML document returned `no-cache, must-revalidate, max-age=0, no-store, private`, while add-on scripts returned `public, max-age=604800`. All injected JavaScript now uses a 16-character SHA-256 content version, including scripts that previously used only the plugin version, so changed files receive a new URL on the next page load.
- The pre-cache-change candidate ZIP contained 232 entries and passed archive integrity checks. The final tagged ZIP checksum is generated and published by the release workflow.

## Not verified

- Live installation of this candidate is not verified; inspection confirms the cache headers and current UI but does not prove the candidate was installed there.
- The fresh/cached updater response and normal 1.2.2-to-1.2.3 WordPress upgrade flow were bypassed under the user's explicit authorization on 2026-10-10; those checks are not claimed as passing.
- GitHub release publication and hosted workflow have not yet run; the authorized release workflow is pending.
- This UI-only change does not repeat external provider OAuth consent or real upstream writes; provider writes are covered by fixtures in the local integration suites.
