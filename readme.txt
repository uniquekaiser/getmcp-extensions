=== GetMCP Extensions ===
Contributors: uniquekaiser
Tags: mcp, oauth, gateway, automation
Requires at least: 6.2
Tested up to: 7.1.2
Requires PHP: 8.2
Requires Plugins: getmcp
Stable tag: 1.1.1
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

= 1.1.1 =
* [NEW] Receive dashboard updates from public GitHub releases without a client token.
* [IMPROVE] Show Synergetic Dev publisher information, release details and icons.
* [SECURITY] Accept matching versioned packages and exclude development checkouts from updates.
* [DEV] Add reproducible packages, local verification, API docs and a Graphify map.

= 1.1.0 =
* [FIX] Preserve secrets and advanced OAuth settings during Authentication saves.
* [NEW] Preview imported connection settings and edit encrypted custom headers.
* [NEW] Connect personal marketing accounts and select private Page credentials.
* [SECURITY] Verify permissions and harmless reads before reviewed messaging activation.
* [IMPROVE] Show provider readiness and normalize nested response envelopes.

= 1.0.0 =
* [NEW] Add native OAuth, explicit user allowlists, remote connections and project gateways.
* [SECURITY] Isolate credentials and protect endpoints with a persistent guard.

== Upgrade Notice ==

= 1.1.1 =
Install this release ZIP once on installations without the public updater. Preserve salts, existing data and the owned persistent guard.
