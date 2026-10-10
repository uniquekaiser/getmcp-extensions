# Changelog

## 1.2.2 - 2026-10-10

- [COMPAT] Support GetMCP 1.7.0 with a separately verified compatibility profile and version-specific adapters while retaining GetMCP 1.6.0 support.
- [FIX] Load isolated 1.6 and 1.7 admin bundles so each supported core version renders the correct native app and add-on routes.
- [FIX] Preserve GetMCP 1.7 server settings validation, FTP handling, OAuth branding, and the native admin/FTP interface alongside add-on screens; use only public UrlGuard methods across both releases.
- [FIX] Restore native WordPress OAuth controls in GetMCP 1.7 while keeping other authentication cards available and accepting the native provider configuration.
- [FIX] Keep GetMCP's full client-authentication choices visible alongside WordPress sign-in, and replace irrelevant external OAuth setup fields with clear first-party guidance.
- [SECURITY] Include the complete 1.7 core and admin asset inventory in runtime integrity checks so unreviewed vendor changes keep affected endpoints blocked.

## 1.2.1 - 2026-10-06

- [FIX] Keep refreshable Google accounts connected when a short-lived access token expires; show automatic renewal separately from required reauthorization.
- [FIX] Return provider and Remote MCP consent to the originating admin, profile or standalone connection screen using protected OAuth state.
- [FIX] Keep available Google Ads discovery results when a disabled customer rejects hierarchy access, and explicitly report incomplete coverage.
- [SECURITY] Prevent personal connection responses from being cached and bypass previously cached status responses in the browser.

## 1.2.0 - 2026-10-06

- [NEW] Preview and import multiple Remote MCP servers with editable names, user access and independent retry results.
- [NEW] Add Remote MCP from My MCP Connections and copy accessible project gateway URLs.
- [NEW] Connect Codex Desktop with direct HTTP and copyable config.toml.
- [NEW] Convert client configuration between Claude/Cursor JSON, VS Code JSON and Codex TOML with secret redaction.
- [NEW] Install five marketing templates into empty draft native servers with credentials and messaging activation kept separate.
- [FIX] Show project gateways on the original Gateway screen and return saved gateways to the correct list.

## 1.1.2 - 2026-10-06

- [FIX] Include icons, valid publisher links and categorized HTML changelogs in the WordPress release-details response.
- [SECURITY] Discard invalid cached update packages without interrupting the Plugins screen.
- [DEV] Verify anonymous release delivery and dashboard upgrades with preserved configuration in disposable compatibility environments.

## 1.1.1 - 2026-10-06

- [NEW] Receive WordPress dashboard updates from public GitHub releases without a client token.
- [IMPROVE] Show Synergetic Dev publisher information, release details and update icons.
- [SECURITY] Accept only the matching versioned release package and keep development checkouts outside automatic updates.
- [DEV] Add reproducible packages, local verification commands, API documentation and a Graphify architecture map.

## 1.1.0 - 2026-10-05

- [FIX] Preserve encrypted secrets and advanced OAuth settings during Authentication saves, with explicit clear controls and stale-save rejection.
- [NEW] Import remote connections from configuration previews and edit authentication and encrypted custom headers through individual fields.
- [NEW] Connect personal REST-provider accounts and select encrypted, per-user Instagram Page credentials.
- [SECURITY] Require current permissions, Page ownership and a harmless read before enabling reviewed messaging drafts; isolate identities and disable mutation retries.
- [IMPROVE] Discover provider accounts, show connection readiness and normalize nested Meta response envelopes.

## 1.0.0 - 2026-10-04

- [NEW] Add native WordPress OAuth with searchable user allowlists and optional feature switches.
- [NEW] Connect remote Streamable HTTP servers with personal OAuth, tool, resource and prompt routing.
- [NEW] Create project gateways with explicit server membership and user restrictions while preserving the original gateway.
- [SECURITY] Enforce endpoint-bound tokens, identity-scoped sessions and a persistent endpoint guard when the extension is unavailable.
