# GetMCP Extensions 1.2.4 verification

Developed by Synergetic Dev — https://synergetic.dev/

## Scope

The five marketing templates now appear in the static GetMCP 1.7 catalogue,
including internal navigation and search. Their installer creates an empty draft,
passes its UUID to the existing protected template API and opens Authentication.
No provider credentials, allowed users or gateway membership are migrated.

## Passed locally

- WordPress 7.1.3 / PHP 8.3.28 / MariaDB 11.4 fixture: all five templates install
  as drafts with empty personal allowlists and no credentials. Disposable records
  were removed after verification; the connected Telegram browser fixture remains.
- Browser catalogue search renders all five marketing cards; Meta installation
  opens the resulting draft's Authentication page with blank application keys.
- Existing OAuth and access suites: 62 and 33 assertions on PHP 8.3.28.
- JavaScript syntax and install package integrity, root, version and secret-pattern
  checks passed. The source scan and compatibility matrix are recorded by verify.py.
- The existing tag-only release workflow and both release builders were inspected.
  No branch-triggered or duplicate manual workflow run is planned.

## Boundaries and pending distribution proof

Provider writes remain fixtures. Real provider account consent, other hosting
platforms, multisite and the original vendor React build are unavailable or outside
this change. This readable adapter leaves vendor files untouched.

The exact-tag Actions result, downloaded release hashes, published notes, fresh
and cached public update details, and normal 1.2.3-to-1.2.4 fixture upgrade must be
recorded after publication. None of those pending steps is claimed as passing here.
