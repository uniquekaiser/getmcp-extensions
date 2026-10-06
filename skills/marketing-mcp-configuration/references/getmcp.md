# GetMCP configuration, tool coverage, and acceptance

Inspect the installed version and supported API before changing fields. Use native server/tool managers or management tools through the exact authorized connector when they are available. Use Browser for provider console and GetMCP settings work requested by the user. Keep credential entry private.

## Baseline and save

Record server UUID/ID privately, name, slug, endpoint, type/transport, status, auth provider, scope, callback, tools/status, connection variables, SSL verification, retries, and existing instructions. For credentials, record presence and format checks only; exclude their values and encrypted payloads.

Change the narrow necessary fields. Retain unrelated callbacks, users, provider scopes, retry policies, token configuration, and existing business integrations. Independently re-read saved state after the UI confirms success. A form's populated value or a toast is not sufficient evidence if the backend contradicts it.

In the observed Authentication form, saving credentials rebuilt `auth_config` and dropped `extra_authorize_params`. Re-read advanced fields after every Authentication save. If the installed version has this bug, restore the previously authorized fields through its native manager while retaining the newly saved credentials; record the workaround and request a UI fix. For Google, losing `access_type=offline` and `prompt=consent` can prevent refresh-token issuance.

The observed native model stored the OAuth application's secret in encrypted `auth_credentials`, separate from `auth_config.client_id`. An absent `auth_config.client_secret` therefore did not mean the secret was missing. Use the actual storage contract and report presence/encryption flags, never the credential payload.

When updating a template header, remove obsolete connection requirements from all tools and server settings. Update instructions and client examples too. If backend state is correct but Quick Connect still advertises a removed header, record the discrepancy; do not weaken authentication or add dummy credentials to satisfy stale UI.

## Tool inventory and parity

Build a row per upstream operation:

| Upstream operation | Configured tool(s) | Mode | Token/permission | Status and evidence |
| --- | --- | --- | --- | --- |
| `{{operation}}` | `{{tool_name}}` | Read / write / composition / local-only | `{{requirement}}` | Verified / configured / draft / unavailable |

Inspect the current source tool registration or an authenticated `tools/list`. Describe split operations, compositions, and remote bridges explicitly. An active wrapper does not prove the upstream catalog, write eligibility, or account access.

For REST tool schemas:

- Use raw IDs/URLs where the adapter performs encoding. Verify braces and URL template substitution. Double encoding can break Graph fields, Search Console property URLs, and sitemap paths.
- Match bodies to the endpoint's JSON/form conventions. Do not confuse a POST reporting query with a write.
- Provide useful field validation and defaults; preserve pagination cursors and resource identifiers.
- Set `readOnlyHint`, `destructiveHint`, `idempotentHint`, and `openWorldHint` according to behavior. For an arbitrary remote bridge, do not claim every invocation is a harmless read.
- Disable retries for writes unless the API and actual request have a reliable idempotency guarantee. Failed or ambiguous publication is not a reason to repeat it automatically.
- Keep SSL verification on. Do not replace authentication with a public endpoint to simplify testing.

## OAuth acceptance layers

| Evidence | What it proves |
| --- | --- |
| Server/tool saved; Active badge | Configuration exists |
| Initialization and `tools/list` through an internal/loopback context | MCP discovery works in that context |
| Public resource/auth-server metadata and anonymous request rejection | Discovery and an authentication boundary are exposed |
| MCP client completes provider consent/code exchange | The real OAuth path works for that client and identity |
| Harmless provider account/property read using that connection | Authenticated upstream data access works |
| Pagination/manager/Page exploration with appropriate reads | The tested account-discovery scope |
| Token expiry/refresh/reconnect verification | The tested renewal behavior |

Do not merge these into one success claim. Test the intended end-user OAuth path, not a loopback bypass or a manually injected admin token. Do not mint user grants merely to make a test pass.

For all accessible accounts, enumerate the authenticated identity's assets, follow pagination, and traverse manager/Page relationships as supported. Different Google/Facebook identities may need separate OAuth connections. The open Ads/Analytics/Instagram browser tab is not proof of API eligibility.

Use a minimal read for each provider. Avoid creating posts, changing campaigns, submitting sitemaps, deleting records, or sending messages unless separately authorized for an exact operation. Do not add tests for every reversible text/configuration change; run meaningful checks at the affected boundary.

## Secret handling

Secrets belong in native secure fields and encrypted storage. Never place them in tool arguments, chat, reusable skill files, exported configuration, or screenshots. Even a hashed/encrypted credential is unnecessary in a shareable catalog.

When inspecting a credential page, do not print raw button `aria-label` values: provider copy buttons may include their secret in the accessible name. Limit output to expected public labels or sanitized presence checks. Prefer screenshots of APIs, callbacks, tool configuration, and status pages where credentials are absent.

Keep any user-requested credential JSON outside the shareable package. Do not fabricate a successful download. If a file is reconstructed from visible details with the user's authorization, identify it as a compatible reconstruction, validate its structure without printing values, and keep it private. Never retrieve hidden browser app state or call network APIs to circumvent the Browser interface.

## Development handoff

Distinguish configuration records, existing upstream code, locally proposed patches, and deployed custom runtime code.

If only configuration changed, say so plainly. Record the native storage and manager APIs actually used. Typical GetMCP components inspected in the observed installation were:

- `includes/core/class-server-manager.php`: server settings and encrypted operator credentials.
- `includes/core/class-tool-manager.php`: tool definitions.
- `includes/auth/class-oauth-provider.php`: External Provider OAuth broker, token storage, and generic refresh.
- `includes/auth/class-mcp-auth.php`: connecting user's token context.
- `includes/execution/class-auth-injector.php`: choosing upstream authentication; a stored outbound credential can override user OAuth.

Extensions can supply compatible implementations in different paths. Locate the actual loaded component before giving the development team a filename or proposing a patch.

Potential gaps to investigate, not assume:

1. External Provider connection UI for REST servers, with supported OAuth initiation and read-only connection status.
2. Provider-specific Meta token extension and reconnect behavior.
3. Secure per-user Page selection and token exchange, with tool-specific Page authentication.
4. Automatic discovery/exposure of a remote MCP catalog instead of generic bridge wrappers.
5. Quick Connect cache/serialization consistency after connection-variable removal.
6. Authentication saves preserving advanced `auth_config` fields and treating stored encrypted credentials as configured.

For each confirmed gap, specify the trigger, current behavior, desired behavior, token/identity boundary, affected loaded files, and acceptance checks. State whether code exists, is locally validated, is deployed, or remains a proposal. Do not modify vendor/plugin files without authorization for that implementation.
