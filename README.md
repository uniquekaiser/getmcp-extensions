# GetMCP Extensions

Developed by **[Synergetic Dev](https://synergetic.dev/)**.

A standalone WordPress add-on for personal MCP connections, project gateways and controlled access. PHP 8.2+, single-site WordPress 6.2+ and a compatible GetMCP installation are required. Tested on WordPress 7.1.3. Existing GetMCP subscription limits still apply.

## Features

- Native WordPress OAuth with searchable, explicit user allowlists.
- Remote Streamable HTTP connections with tools, resources, prompts and personal upstream OAuth.
- Project gateways with selected servers, separate user access and endpoint-bound credentials.
- Paste Configuration previews for supported JSON, simple YAML/TOML, MCP client commands and HTTPS URLs.
- Select and import up to 100 Remote MCP entries with individual results, renaming and retry support.
- Codex Desktop Quick Connect with direct HTTP and copyable `config.toml` entries.
- A redacting configuration converter for Claude/Cursor JSON, VS Code JSON and Codex TOML.
- Google Health native read tools, personal Google OAuth, daily/weekly summaries and available nutrition reads.
- Personal Oura OAuth connections on GetMCP 1.8, with health-data privacy protections.
- Optional credential-free public GET upstreams for users already granted access through an active project gateway.
- Five marketing templates that install into empty native servers in draft status.
- A separate hosted MCP catalogue with native styling, Save & Authorize and current-user access defaults, without creating native tools.
- Editable authentication fields and encrypted custom headers. Blank saved-secret fields preserve existing values.
- Personal marketing connections, private Instagram Page selection, provider discovery and readiness checks.
- Optional modules that can be retired individually as matching functionality becomes available.
- Public GitHub updates in the normal WordPress dashboard, without a client token.

Command-only server configurations are flagged for a reviewed native port. Import never runs commands, installs runtimes or creates a companion service. Automatic remote OAuth depends on provider metadata and client-registration support; some providers still require an application registration.

## Installation and updates

1. Back up files and the database; retain encryption salts. Validate in a disposable or staging site first.
2. Download the versioned plugin ZIP from [Releases](https://github.com/uniquekaiser/getmcp-extensions/releases/latest). Activate GetMCP before this add-on.
3. Open **GetMCP → Extensions** to select modules, **Gateways & Remote MCP** for connections and gateways, and **My MCP Connections** for personal accounts.
4. Subsequent public releases appear under **Dashboard → Updates** and **Plugins**. No GitHub token is requested.

The first updater-enabled version requires one manual ZIP installation on older installations. GitHub source archives and Git checkouts are development material and do not enable self-updates. The install ZIP includes a distribution marker and the update-checker runtime.

## Access and safe retirement
On GetMCP 1.8, configure personal Oura access in **Extensions → Personal provider access**. Each eligible user connects their own account in **My MCP Connections**. Health responses are excluded from shared response caches, protocol filters and replay logs. An interrupted single-use refresh requires the affected user to reconnect.

For a server whose upstream is publicly readable, an administrator can select it under **Personal provider access → Public read-only upstream**, enter its HTTPS origin and save. All of its tools must be HTTPS GET requests to that origin with certificate verification enabled. The account gateway publishes it only to users already allowed by an active project gateway containing that server. Clear the origin to disable this path. Gateway and custom credential headers are not sent to the public upstream.


Empty gateway allowlists deny everyone, including administrators. Gateway membership deliberately delegates access to its selected servers; direct connections retain their own restrictions. Gateway credentials are never sent upstream. Existing credentials, routing and user selections are preserved during an add-on update.

Activation installs an owned persistent endpoint guard in `wp-content/mu-plugins/`. Disabling or deleting the add-on leaves affected endpoints blocked. Reactivating a compatible version restores them. Do not remove the guard until protected definitions have been migrated or retired. There is no automatic data purge.

## Documentation

- [Installation, compatibility and rollback](docs/installation.md)
- [Bulk import, portal, templates and Codex setup](docs/connections-1.2.0.md)
- [Hosted MCP catalogue and gateway setup](docs/remote-catalogue.md)
- [Local 1.2.0 verification](docs/verification-1.2.0.md)
- [User access API](docs/api-user-access.md) and [gateway API/hooks](docs/api-gateways.md)
- [Google Health setup and boundaries](docs/google-health.md)
- [Marketing connections and setup API](docs/marketing.md)
- [Token-free updates and releases](docs/releases.md)
- [Architecture and Graphify](docs/architecture.md)
- [Local tests and coverage limits](TESTING.md)
- [Contributing](CONTRIBUTING.md) and [security reporting](SECURITY.md)
- [Portable marketing configuration guide](skills/marketing-mcp-configuration/SKILL.md)

Real Meta consent, token-extension compatibility and messaging require separate provider acceptance. Local write tests use synthetic providers. No production installation is implied by repository publication.

## Development

```powershell
python tools/verify.py
python tools/build.py
python tools/graph.py
```

The build contains runtime files only. Tests, docs, Graphify outputs and tools stay in the repository. The code map indexes authored backend and admin code, excludes dependencies and generated compatibility assets, and uses local AST extraction without an external model.

GPL-2.0-or-later. Required third-party notices are in [LICENSE-NOTICE.md](LICENSE-NOTICE.md).
