# Google connection status and consent return fixes

Developed by Synergetic Dev — https://synergetic.dev/

## Changes

- Keep an authorized Google connection connected when its access token expires and a refresh credential exists. Listing is read-only; the existing protected execution path renews the token on the next request. This does not verify a revoked refresh credential in advance.
- Report expired Meta or non-refreshable connections as requiring reconnection.
- Bind the originating connection screen to encrypted, single-use OAuth state. Admin, profile and standalone portal are fixed destinations. Return URLs supplied in callback queries are ignored, and management destinations require the existing capability.
- Mark personal REST responses, including errors, private and non-cacheable; tell LiteSpeed not to cache them. Use a unique status-query parameter to bypass previously cached responses without changing site-wide cache settings.
- Preserve other Google Ads hierarchy results when Google specifically returns CUSTOMER_NOT_ENABLED for a customer. Include unavailable customers and complete=false. Other authentication errors, malformed results and transport failures still fail normally.

## Local verification

Passed on WordPress 7.1.2 / PHP 8.3 and WordPress 6.2.2 / PHP 8.2 in owned Docker fixtures:

- 23 new connection-status checks per environment: refreshable expiry, renewal on next use, revoked refresh, credential preservation, disabled access, callback destinations, redirect injection rejection, identity-sensitive cache headers and secret-free status output.
- Existing marketing WordPress, execution, discovery and lifecycle suites, plus gateway WordPress, protocol, HTTP authentication, native listing, runtime and connection regression suites.
- The final discovery suite has 14 checks per environment, including disabled-customer partial coverage and outage rejection. It was rerun after that correction; unchanged regression evidence was retained.

Passed locally on PHP 8.2.30, 8.3.29 and 8.4.16: PHP syntax, 62 native OAuth security checks and 33 user-access checks per runtime. JavaScript syntax and the public-source credential/configuration scan passed. The initial new fixture header assertion was corrected to exercise WordPress's HTTP post-dispatch filter, which internal rest_do_request does not invoke by itself; the corrected suite passed.

Not performed: hosted CI, GitHub publication, provider mutations, or a new live Google consent/refresh cycle. Browser verification of the installed correction must be recorded separately from source and fixture checks.

## Installation and rollback

Version 1.2.1 is a locally built maintenance package; this work does not publish a GitHub release. Review the package hash, back up the current add-on and update GetMCP Extensions through WordPress. Keep vendor GetMCP 1.6.0, the database, WordPress salts and existing MU endpoint guard intact. Verify runtime compatibility, activation and hashes of server/tool configuration, encrypted connections, allowlists and gateway membership before and after the update.

No schema or credential migration is required. Existing pending consent states without a return context use the current user's accessible admin/profile screen. Registered provider callback URIs stay unchanged. The standalone portal remains available and flows started there return there.

Rollback: restore the original 1.2.0 add-on package through WordPress, keeping the database and salts. Its audited install ZIP SHA-256 remains 6efc1a123e9f91087e16361b96906eb6fe9d0d2c42f89fd8eea9ef71294dc90f. The older UI/cache behavior returns; existing saved credentials are retained.
