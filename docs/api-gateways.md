# APIs and extension seams

Configuration interfaces require the existing `getmcp_manage_servers` capability. WordPress cookie REST requests require the normal `X-WP-Nonce` for `wp_rest`. Preview also requires the actual operator to be in the endpoint allowlist; management permission does not grant runtime access.

| Method | Path under `/wp-json/getmcp/v1` | Result |
|---|---|---|
| GET / POST | `/connections` | List all server summaries / create gateway or remote connection |
| GET / PUT / DELETE | `/connections/{id}` | Read / update / delete a new-kind record |
| GET | `/connections/{id}/preview` | Discover tools, resources, resource templates, prompts |
| GET | `/my-connections` | Current user's available upstream OAuth accounts |
| POST / DELETE | `/my-connections/{id}` | Start upstream OAuth / disconnect current user's account |
| GET | `/upstreams/{uuid}/client-metadata` | Public client metadata for an active upstream OAuth connection |

Creation example (IDs are placeholders; select users and servers from your installation):

```json
{"kind":"gateway","name":"Project Alpha","slug":"project-alpha","status":"active","allowed_user_ids":[12,19],"server_ids":[34,35]}
```

Remote connection with operator credentials (send credentials only over a protected management request):

```json
{"kind":"remote-mcp","name":"Project API","slug":"project-api","allowed_user_ids":[12],"remote":{"endpoint":"https://mcp.example.com/mcp","auth_mode":"shared","publish_original":false},"credentials":{"headers":{"Authorization":"Bearer REPLACE_WITH_OPERATOR_SECRET"}}}
```

Per-user upstream OAuth example:

```json
{"kind":"remote-mcp","name":"User Accounts","allowed_user_ids":[12,19],"remote":{"endpoint":"https://mcp.example.com/mcp","auth_mode":"oauth","client_id":"PRE_REGISTERED_CLIENT_ID","scope":"read write","publish_original":false},"credentials":{"client_secret":"REPLACE_WITH_CLIENT_SECRET_IF_REQUIRED"}}
```

Omit `client_id` for an upstream supporting public client metadata or dynamic registration. Discovered URLs must pass the same public HTTPS checks; issuer/resource identities and S256 are validated. Pre-register the exact callback `https://your-site.example/?getmcp_upstream_callback=1` with a provider requiring pre-registration. POST `/my-connections/{id}` returns `authorization_url`; the logged-in browser follows it and returns through the frontend callback. Replays, another WordPress principal, configuration changes, and disconnects reject the pending callback. These REST interfaces do not accept a caller-supplied target user for grants.

For updates use PUT with the changed fields. Record kind is immutable. Stable slugs are preserved on name changes. `credentials` replaces the stored upstream credential object when supplied; omission preserves it. Public responses return `has_shared_credentials` and a temporary `connection_status`, never credential values. A gateway allowlist or member update takes effect without reconnecting existing clients; previously issued tokens do not bypass current checks.

The existing user directory and allowlist REST service remain the source of searchable users and selected-user hydration. The new component reuses that service and the readable picker.

## Management MCP and PHP

The built-in management endpoint exposes `manage_mcp_connections` with operations `list`, `get`, `save`, `delete`, and `preview`. Its JSON schema documents the same fields as the REST API. Existing management authentication, scopes, capabilities, and licensing apply. This tool is never included in project gateway members.

```php
$gateway = \GetMCP\Gateway\FeatureManager::run('save', [
    'kind' => 'gateway', 'name' => 'Project Alpha',
    'allowed_user_ids' => [12], 'server_ids' => [34],
]); // Current WordPress actor must have getmcp_manage_servers.
$preview = \GetMCP\Gateway\FeatureManager::run('preview', ['id' => $gateway['id']]);
```

| Hook | Arguments | Purpose |
|---|---|---|
| `getmcp_gateway_configuration_saved` | saved Server, previous Server or false, actor ID | React to committed configuration changes |
| `getmcp_upstream_connected` | server ID, user ID | Successful initial connection or grant rotation |
| `getmcp_upstream_disconnected` | server ID, user ID | Local encrypted grant removal/tombstone |
| `getmcp_remote_tool_called` | member ID, gateway/direct ID, user ID, upstream name, milliseconds, isError | Successful transport result, including upstream tool errors |
| `getmcp_remote_call_failed` | member ID, gateway/direct ID, user ID, MCP method | Sanitized upstream failure |
| `getmcp_remote_tool_schema_rejected` | server ID, upstream name | Unsupported or invalid header annotation withheld |

Existing `getmcp_server_capabilities` and `getmcp_gateway_servers` filters remain available. New-kind manifests are narrowed after capability filters. Original gateway member filters cannot inject a nested/management gateway or implicitly publish a remote connection. Hooks carry identifiers, not secrets. PHP extension code is trusted WordPress code; use FeatureManager for permission/invariant checks rather than direct SQL.

Connection exports use `getmcp/connections-v1` and clear installation-specific allowed-user/member IDs. They contain public configuration only. Recreate with `/connections`, explicitly reselect users and members, then provision credentials independently. Existing native import does not silently convert these exports into native servers.
