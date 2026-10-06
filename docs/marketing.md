# GetMCP Extensions 1.1.0 — local developer handoff

This is an installable standalone add-on update. No customer installation or provider account was changed. The current vendor files, existing broker grants, direct-server allowlists and gateway routing remain untouched. Install the plugin ZIP, not the developer archive. PHP 8.2+, single-site WordPress and a reviewed GetMCP build are required.

## Implemented

- Authentication updates merge omitted advanced fields, preserve blank masked credentials and their encrypted bytes, replace allowlist arrays, support explicit clearing/replacement, and reject stale edits. The editor includes authorization-parameter fields and a separate personal-account user picker. Credential reads return presence only. Provider or application-identity changes clear obsolete credentials.
- Facebook Login exchanges short-lived tokens using `fb_exchange_token`; expiration uses the provider response. Expired Meta grants require reconnection, never a generic OAuth refresh. Google refresh remains separate.
- WordPress-personal REST grants reuse encrypted upstream storage but are explicitly tagged `rest-provider`. Existing MCP broker rows retain their identity and credentials; their Page vaults are keyed by broker row, never silently assigned to a WordPress user. Consent binds user, server endpoint, configuration, PKCE, expiration, disconnect version and one-use state.
- Encrypted Page vaults paginate private discovery, select a linked Page/Instagram pair, and require current permissions, current Page ownership/MESSAGING task and a harmless conversations read. Changing selection, credentials, connection or failed verification invalidates readiness. Verification is refreshed with harmless reads after five minutes of active use. Page tokens never appear in connection responses or test-debug headers.
- Existing `get_conversations`, `get_conversation_messages` and `send_dm` drafts can be activated by a permitted manager only after verifying their own connection and the reviewed endpoint shapes. No replacement definitions are created. Calls use the requesting identity's selected Page. Conversation reads are restricted to IDs discovered on that Page. Mutations get zero automatic retries.
- My MCP Connections handles supported Google/Facebook REST providers as well as Remote MCP. Discovery covers Google Ads managers/children, Analytics properties, Search Console properties and linked Instagram Pages. Discovery and reporting verification remain separate. Missing/unknown scopes and expiry are explicit per connection.
- The Meta bridge normalizes bounded nested JSON/SSE/JSON-RPC envelopes, preserves content blocks/structured objects, decodes JSON-string `ad_entities`, and propagates protocol/tool errors. Explicit `is_ads_mcp_enabled`, `is_queryable` and provider reasons appear in `result._meta["getmcp/provider-account-readiness"]`; absent flags remain unknown. Listing an account never marks reporting verified.
- Add Remote MCP has URL and **Paste configuration** entry points. JSON client configurations, basic YAML, Codex TOML, supported Claude/Codex add commands and bare HTTPS URLs map into editable fields. OAuth scopes/client aliases and custom headers are mapped; unknown field names are reported. Import previews are encrypted, bounded, actor-bound, expiring and single-use. Existing names require a rename, never implicit replacement. Command-only entries require a reviewed native API port; no process is launched.
- Custom headers work with no auth, shared auth and personal OAuth. Values are encrypted and write-only. Blank preserves a named header; removing its row removes the header. Reserved transport/auth headers cannot override a user's OAuth token. Remote OAuth discovers metadata and supported client registration; callback setup is not requested for automatically registered providers. Fixed-client providers still require their own registration.
- Marketing/setup features have an independent switch. Authentication-save protection stays active; disabling marketing stops marked enhanced messaging endpoints while retaining data. The owned MU guard is refreshed during upgrades, with no vendor edits. Admin assets use content hashes to prevent old forms/instructions loading after an update.

## REST and MCP interfaces

All configuration writes use existing server/tool management permissions and REST nonces or authenticated management MCP scopes. Personal routes require WordPress login and current direct personal access or selected-gateway access. Empty allowlists deny everyone, including administrators.

| Interface | Purpose |
|---|---|
| `GET /getmcp/v1/servers/{uuid}/credentials` | Configurations, credential-presence flags and opaque authentication revision; no decrypted credentials |
| `PATCH /getmcp/v1/servers/{uuid}` | Existing native update; `authentication_revision`, `auth_config_mode`, `clear_credentials` supplement ordinary fields |
| `GET, PUT /getmcp/v1/servers/{uuid}/provider-configuration` | Personal user selection, provider preset, token sources and advanced authentication; requires `configuration_revision` on writes |
| `GET, PUT /getmcp/v1/provider-tools/{id}` | Body template, annotations, noncredential headers, retry count/backoff; requires opaque `revision` |
| `GET /getmcp/v1/provider-presets` | Portable provider defaults/requirements without application secrets or invented Meta API versions |
| `POST /getmcp/v1/connections/import-preview` | `{ "document": "client configuration text" }`; returns mapped fields/presence and private preview token |
| `POST /getmcp/v1/connections/import-confirm` | `{ "preview_token": "...", "index": 0, "configuration": { "name": "Example", "allowed_user_ids": [] } }` |
| `GET /getmcp/v1/my-connections` | Current user's permitted remote and REST connections/readiness |
| `POST, DELETE /getmcp/v1/my-connections/{id}` | Start personal consent / disconnect that user's connection |
| `POST /getmcp/v1/my-connections/{id}/discover` | Paginated harmless account discovery; reporting remains unverified |
| `GET, POST, PUT /getmcp/v1/my-connections/{id}/assets` | Public Page names/IDs, discover, select `{page_id, instagram_id}`, verify `{operation:"verify"}` |
| `GET, POST, PUT /getmcp/v1/provider-assets/{id}` | Same Page operations for an existing, exact-server MCP broker bearer identity; not an administrator shortcut |
| `POST /getmcp/v1/servers/{uuid}/enable-messaging` | Enable the three existing reviewed drafts after that manager's personal verification; sends no message |
| `GET, PUT /getmcp-extensions/v1/marketing` | Independent module `enabled` and numeric CAS `revision` |

Examples contain placeholders only. Obtain current revisions with GET before PUT/PATCH. These are partial merges by default:

```json
{"configuration_revision":"<from GET>","auth_config":{"extra_authorize_params":{"access_type":"offline","prompt":"consent"}},"personal_provider":{"allowed_user_ids":[123],"preset":"google-analytics"}}
```

```json
{"configuration_revision":"<from GET>","token_sources":{"get_conversations":"page","get_conversation_messages":"page","send_dm":"page"}}
```

Setting Page token sources marks the endpoint as requiring this add-on; it does not activate tools or bypass verification. Explicit clearing uses `clear_credentials:["auth_credentials"]`; `auth_config_mode:"replace"` removes omitted configuration. `null` removes one merged configuration leaf. Do not put secrets in `auth_config`.

Management MCP uses `manage_provider_configuration` (`read`, `update`, `read_tool`, `update_tool`, `presets`) with typed configuration schemas. Existing `manage_mcp_connections` now documents `connection_headers:[{name,value}]` alongside its existing write-only credentials. Both remain excluded from project gateways. Existing OAuth, remote-call, deletion and disconnect hooks remain in use; no unprotected PHP execution endpoint was added.

## Optional individual Meta tool exposure

Use a separate **Remote MCP** connection when the provider supports the reviewed Streamable HTTP and OAuth paths. Its existing live catalogue discovery exposes individual tools with current schemas/annotations and paginates without assuming 98 tools. The local gateway/protocol suite exercises this path with synthetic upstreams. Keep the working REST bridge selected until an operator explicitly authenticates, discovers and verifies a replacement. Preview the proposed gateway membership and publish one catalogue deliberately; there is no automatic credential migration or replacement.

Current official Meta connector documentation could not be retrieved during this local run (access/rate-limit errors), and no new real Meta consent was performed. Consequently, Meta-specific registration/permissions, token-extension POST compatibility and the optional official-endpoint catalogue remain **unavailable for live acceptance**, not passing. The existing dated 98-tool result is not new proof. The automatic Remote MCP OAuth path remains conditional on provider metadata/client-registration support; a provider requiring a fixed client must supply its own registration. No new transport is necessary or included.

The current Google developer-token requirement was checked against [Google's official developer-token documentation](https://developers.google.com/google-ads/api/docs/api-policy/developer-token): the header is optional/ignored after its September 2026 sunset. No preset adds it. Graph versions come from existing reviewed tool/application configuration; this release does not invent a current provider version.

## Migration and rollback

1. Back up files/database and retain WordPress salts/encryption keys. Verify the exact vendor profile. On a clean reviewed installation, activate GetMCP before the add-on. For an existing 1.0.0 add-on, update only `getmcp-extensions/`; do not reinstall GetMCP or apply the reference vendor patches for this update.
2. The existing schema version remains 1. New personal REST grants use the existing encrypted upstream table with explicit `kind` and endpoint/configuration binding. Page vaults and one-use consent/import records use encrypted, nonautoloaded options. Existing credentials and broker rows are not migrated, interpreted as browser grants or rewritten.
3. Personal REST access is opt-in through the new allowlist or current gateway delegation. Connect new browser identities explicitly. Broker identities can keep client-driven OAuth and use the broker Page-assets interface. Do not map one user's grant to another or use shared credentials on personal failure.
4. Leave live messaging drafts unchanged until provider prerequisites and harmless-read verification are satisfied. The marker `settings.getmcp_extensions_required` protects enhanced endpoints when marketing or the add-on is unavailable. The upgrade refreshes only the add-on-owned MU guard; filesystem failure blocks upgrade readiness rather than overwriting an unrelated guard.
5. To stop new functionality, disable the marketing module. To roll back to 1.0.0, first pause enhanced Page-token tools/servers and retain the 1.1.0 owned guard: 1.0.0 lacks their runtime checks. Deactivating/removing the add-on leaves the persistent guard blocking affected endpoints; data/credentials stay stored. Do not remove that guard or convert marked endpoints back to ordinary native servers until their token-source definitions are reviewed.
6. Delete only explicitly unwanted fixture/personal connections through the supported API. Server/user deletion and disconnect clean their Page vaults. Routine cleanup removes expired consent/import records and old Page discovery vaults. No uninstall purge runs automatically.

## Verification and package layout

See `verification-1.1.0.md` and machine-readable evidence for actual results and limitations. The install ZIP contains only plugin files. The developer archive includes the exact install ZIP, readable modules/admin factories, deterministic adapter generator/template, source/baseline hashes, changed-file manifest, a feature patch against the exact 1.0.0 ZIP, separate historical prerequisite/feature reference patches, fixture tests, API/migration instructions and sanitized evidence. No vendor distribution, customer configuration, installed-site database, provider account data, live credentials or private marketing evidence is included.

The adapter generator uses asserted seams on reviewed compiled chunks because original React sources are absent. Upstream developers should port readable factories into their original source tree; they should not hand-edit minified chunks. All existing `source/getmcp` files are independently checked against their preserved baseline. Installation/upload acceptance must be completed in a staging site before a future deployment; this phase ends with local artifacts.

To reconstruct the feature independently, extract the exact 1.0.0 install ZIP into a temporary directory and run `git -c core.autocrlf=false apply /absolute/path/03-standalone-marketing-setup.patch` from that directory. The patch targets its `getmcp-extensions/` folder. Disabling automatic line-ending conversion preserves the exact package hashes on Windows. Compare all resulting files against `manifests/addon-files.json`; do not apply this patch to vendor files or a live installation.

## Small improvements included and follow-ups

Included: stale-save rejection, explicit clear controls, encrypted header editing, import previews with collision protection, automatic OAuth discovery, truthful per-user readiness, refreshed ownership/permissions, conservative mutation retries, and cache isolation across users/gateways/selected Pages.

Follow-ups needing separate acceptance: real Meta extension and messaging reads, optional official Meta Remote MCP discovery, original React source integration, WordPress minimum-version/platform matrix, and scoped export/restore of configuration with write-only secret references. Batch import intentionally confirms one chosen server per preview; users can preview again for another entry. Hosted-known package lookup/installation, unrestricted YAML/TOML, arbitrary command execution and silent replacement remain outside this release.
