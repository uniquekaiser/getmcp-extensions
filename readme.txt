=== GetMCP Extensions ===
Contributors: uniquekaiser
Tags: mcp, oauth, gateway, automation
Requires at least: 6.2
Tested up to: 7.1.2
Requires PHP: 8.2
Requires Plugins: getmcp
Stable tag: 1.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Personal MCP connections, project gateways and explicit user access. Developed by Synergetic Dev.

== Description ==

Developed by [Synergetic Dev](https://synergetic.dev/).

Add native OAuth user restrictions, remote Streamable HTTP connections, project gateways, configuration import previews and encrypted custom headers. Each user can connect their own upstream accounts. Optional modules preserve stored configuration when disabled.

Public GitHub release updates appear in the WordPress dashboard without a client token. Install the versioned release ZIP once to bootstrap updates; development checkouts do not self-update.

Single-site installations only. GetMCP must be installed separately. Verify compatible build diagnostics before enabling connections. Existing subscription limits continue to apply.

== Installation ==

1. Back up files/database and retain encryption salts. Validate in staging.
2. Activate GetMCP, upload the versioned release ZIP, then activate GetMCP Extensions.
3. Configure GetMCP Extensions, Gateways & Remote MCP and My MCP Connections.
4. Keep the persistent endpoint guard until protected definitions have been retired or migrated.

== Frequently Asked Questions ==

= Do updates need a GitHub token? =
No. Release packages and update information are public.

= Can imports run local commands? =
No. Command-only configurations require a reviewed native port.

= Does deleting the add-on remove access restrictions? =
No. Its persistent endpoint guard blocks affected endpoints until a compatible runtime returns or records are deliberately migrated.

== Changelog ==

= 1.1.2 =

* [FIX] Include icons, valid publisher links and categorized HTML changelogs in the WordPress release-details response.
* [SECURITY] Discard invalid cached update packages without interrupting the Plugins screen.
* [DEV] Verify anonymous release delivery and dashboard upgrades with preserved configuration in disposable compatibility environments.

= 1.1.1 =

* [NEW] Receive WordPress dashboard updates from public GitHub releases without a client token.
* [IMPROVE] Show Synergetic Dev publisher information, release details and update icons.
* [SECURITY] Accept only the matching versioned release package and keep development checkouts outside automatic updates.
* [DEV] Add reproducible packages, local verification commands, API documentation and a Graphify architecture map.

= 1.1.0 =

* [FIX] Preserve encrypted secrets and advanced OAuth settings during Authentication saves, with explicit clear controls and stale-save rejection.
* [NEW] Import remote connections from configuration previews and edit authentication and encrypted custom headers through individual fields.
* [NEW] Connect personal REST-provider accounts and select encrypted, per-user Instagram Page credentials.
* [SECURITY] Require current permissions, Page ownership and a harmless read before enabling reviewed messaging drafts; isolate identities and disable mutation retries.
* [IMPROVE] Discover provider accounts, show connection readiness and normalize nested Meta response envelopes.

= 1.0.0 =

* [NEW] Add native WordPress OAuth with searchable user allowlists and optional feature switches.
* [NEW] Connect remote Streamable HTTP servers with personal OAuth, tool, resource and prompt routing.
* [NEW] Create project gateways with explicit server membership and user restrictions while preserving the original gateway.
* [SECURITY] Enforce endpoint-bound tokens, identity-scoped sessions and a persistent endpoint guard when the extension is unavailable.

== Upgrade Notice ==

= 1.1.1 =
Install this release ZIP once on installations without the public updater. Preserve salts, existing data and the owned persistent guard.
