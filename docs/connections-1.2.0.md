# Connection setup additions in 1.2.0

Developed by [Synergetic Dev](https://synergetic.dev/). This is an update to the standalone add-on; GetMCP files and existing accounts are preserved. This build is a local handoff, not a published release or customer deployment.

## Bulk Remote MCP import

Open GetMCP → Gateways & Remote MCP → Add Remote MCP → Paste configuration. JSON `mcpServers`, VS Code `servers`, Codex `mcp_servers`, basic YAML, the supported TOML subset, a URL or a client add command can be previewed. Select any supported entries, edit names/slugs, choose status and permitted WordPress users, then Import selected servers. The default is draft with an empty deny-all user list; original `/mcp` publication stays off. Up to 100 entries and 256 KiB are supported. Command-only entries remain text and cannot be imported as runnable servers.

The preview is encrypted, actor-bound and expires after 15 minutes. Each successfully imported entry is consumed atomically. Failed entries retain their encrypted settings for correction/retry. Duplicate names do not overwrite existing servers. Each entry has a separate result; a batch may partially succeed. A single entry can still be opened in the individual authentication/header editor. Refreshing loses the browser preview token; paste again to obtain a fresh preview, and omit already-created servers.

Management REST example, authenticated with the existing WordPress REST nonce/capability mechanism:

```json
POST /wp-json/getmcp/v1/connections/import-preview
{"document":"{\"mcpServers\":{\"Reporting\":{\"url\":\"https://example.org/mcp\"},\"Project\":{\"url\":\"https://example.org/project\"}}}"}
```

Then submit only selected indexes and edits using the returned preview token:

```json
POST /wp-json/getmcp/v1/connections/import-batch
{"preview_token":"RETURNED_PREVIEW_TOKEN","entries":[{"index":0,"configuration":{"name":"Reporting","status":"draft","allowed_user_ids":[]}}, {"index":1,"configuration":{"name":"Project","status":"draft","allowed_user_ids":[]}}]}
```

The response has `created` and `results[]` containing `index`, `status` and either a public server or a safe failure message. HTTP success represents a processed batch; inspect every entry's status. Existing quotas, public HTTPS validation, encrypted credentials and Remote MCP runtime continue to apply. Gateway tokens never become upstream credentials.

## Gateway visibility and personal portal

Project gateways are now also listed on the original Gateway screen with a management link. Saving or returning from a gateway editor resets the list to Gateways and clears a stale search. This fixes the case where a newly created gateway was hidden by the Remote MCP filter. Existing records are not repaired or recreated: their listing API already included them.

My MCP Connections shows URLs only for currently active endpoints explicitly accessible to that user. Administrators are also restricted. Managers additionally see Add Remote MCP, linking to the existing protected editor. Ordinary users can connect their own upstream account but cannot create server definitions. `/my-connections` now returns `endpoints` and `can_manage_connections` alongside its existing `connections` array.

## Codex Desktop

Native server Quick Connect and project/remote cards include Codex Desktop. Enter the inbound GetMCP URL under Settings → MCP servers → Add server → HTTP, save, then authenticate when requested. Alternatively merge the generated entry into `~/.codex/config.toml` (Windows `%USERPROFILE%\.codex\config.toml`). Keep existing entries. Native HTTP requires no `mcp-remote` command bridge. See [official MCP setup](https://developers.openai.com/codex/mcp/) and [configuration reference](https://developers.openai.com/codex/config-reference/).

```toml
[mcp_servers.project]
url = "https://example.org/mcp/project"
```

OAuth uses the normal client sign-in; the CLI can run `codex mcp login project`. Bearer mode references `GETMCP_TOKEN`; basic and header API keys reference `GETMCP_AUTH_HEADER` through `env_http_headers`. The client must be launched with these local environment variables. Query API keys or unsupported authentication require manual configuration and are explicitly identified as incomplete. No secrets are read into generated setup text. `GET /getmcp/v1/connection-config/{id-or-uuid}` returns only inbound URL/name/slug and authentication type/header/location. This metadata never grants endpoint access.

## Configuration converter

Expand Convert configuration between clients in the paste form before previewing/importing. It accepts the same portable input subset and writes Claude/Cursor JSON, VS Code JSON or direct-HTTP Codex TOML. It does not store definitions, make requests or run commands. Header/environment values become `REPLACE_LOCALLY`; command arguments are omitted because they can contain secrets. Unsupported settings produce warnings instead of silently changing their meaning. OAuth application settings are omitted; configure each client locally. Codex environment header mappings retain variable names only. Conversion is deliberately a portable subset of the reference project's formats, not every client's dialect.

```json
POST /wp-json/getmcp/v1/connections/transcode
{"document":"{\"mcpServers\":{\"project\":{\"url\":\"https://example.org/mcp\"}}}","format":"codex"}
```

Returns `configuration`, `format`, `warnings` and `secrets_redacted`. Neither preview nor converter exports stored credentials.

## Marketing templates

The native Templates screen gains Google Ads, Google Analytics, Google Search Console, Meta Ads and Instagram, using the existing credential-free definitions. Slugs are `synergetic-{provider}`. Index/detail/install use the vendor's existing routes and permission/licensing gates; a narrow post-callback filter fills only missing owned templates. A vendor implementation with a matching slug takes precedence.

Select a new empty native server. Targets with tools, credentials, authentication or custom settings are rejected without writes. Installation is transactional, preserves the target's name/slug/URL, leaves it draft, creates no credentials or personal grants and seeds a deny-all personal allowlist. Configure application credentials, permitted users and harmless-read verification before publishing. The three Instagram messaging definitions remain inactive. Meta Ads retains its two-tool REST bridge; individual upstream tools remain available through the existing Remote MCP discovery implementation.

## Integration and retirement

Readable modules are `includes/class-configuration-import.php`, `class-configuration-transcoder.php`, `class-connection-screens.php`, `class-marketing-templates.php` and `admin/{native-connections,connection-tools,getmcp-app-extension}.js`. Native REST routes, permissions and credential storage are reused. No schema version changes or credential migrations are needed. New templates and import/converter interfaces follow the existing marketing module switch; gateway/remote runtime switches remain authoritative.

The distributed GetMCP plugin has no original React source. `python tools/build-ui.py` applies exact reviewed anchors from add-on tag `v1.1.2`: mount ProjectGateways before the original `/gateway` component and CodexConnect inside native Quick Connect. For the native Templates screen, it also appends the credential-free catalogue supplied by MarketingTemplates::client_catalogue() to the vendor's fixed UI list. Set GETMCP_UI_VENDOR to the separately acquired original GetMCP 1.6.0 directory; the template chunk must match SHA-256 b29d6bd9ac8fd1914ea96c933423b3c130e6cd9fa5b30e165a957cd7f5eea344. The generator creates hashed owned chunks and redirects only the add-on's copies. Unknown anchors fail the build. Upstream integration should import those readable components in its React source. Do not copy the compatibility files into vendor directories.

Upgrade only `getmcp-extensions/` after a backup, retaining WordPress salts. Rollback to the genuine 1.1.2 ZIP preserves gateways, remote definitions, user lists, credentials and templates created in this version; the new UI/templates catalogue disappear. Any template-created servers remain draft until explicitly published. Retain the current owned MU guard. Do not purge options or uninstall GetMCP. Unconsumed import previews expire through existing cleanup.
