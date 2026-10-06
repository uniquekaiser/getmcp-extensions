# User directory and native OAuth allowlist API

All REST routes require a WordPress principal with `getmcp_manage_servers`. Use standard WordPress REST authentication: cookie + REST nonce for the admin UI, or the site's supported authenticated API mechanism. Never expose these endpoints anonymously or send Alegra API credentials to them. The PHP service repeats the capability check; it does not trust posted actor IDs.

## Search and selected-user hydration

`GET /wp-json/getmcp/v1/oauth-users?search=admin&page=1&per_page=20`

Search matches display name and username. Type at least two characters. Blank, one-character and wildcard-only searches return an empty list without querying the directory. Responses are bounded to 20 by default and 50 maximum. Pagination is deterministic by display name and ID.

```json
{"users":[{"id":1,"name":"Admin","login":"admin","eligible":true}],"page":1,"total_pages":1,"has_more":false}
```

Only IDs, display names, usernames and eligibility are returned. An ineligible account can appear but cannot be added. Emails and credentials are omitted. Search permission does not grant access to any server.

`GET /wp-json/getmcp/v1/oauth-users?include=1,2`

Resolves selected users without downloading the directory. Up to 100 positive IDs per batch; duplicates collapse. Missing IDs are omitted. The picker retains unresolved IDs as `User #ID` so a directory error never silently removes stored selections.

## Read and replace the saved list

`GET /wp-json/getmcp/v1/servers/{uuid-or-numeric-id}/oauth-users`

```json
{"server_id":9,"allowed_user_ids":[1]}
```

`PUT /wp-json/getmcp/v1/servers/{uuid-or-numeric-id}/oauth-users`

```json
{"allowed_user_ids":[1,2],"expected_user_ids":[1]}
```

This replaces the list, deduplicates positive existing read-capable users and preserves other configuration keys, authentication mode and encrypted credentials. An empty list is valid and denies everyone. It works only for existing custom servers explicitly using native GetMCP OAuth; it cannot switch an external provider or activate OAuth implicitly.

`expected_user_ids` is optional but recommended. Pass the prior GET result, in its saved order. Stale updates return HTTP 409. A conditional database update checks the original auth_config and OAuth mode to reject a concurrent change during the write. Even without expected IDs, compare-and-swap protects the snapshot read within the request. Invalid IDs return 400; unauthorised callers 403; missing servers 404; database failure 500.

To add/remove instead of replacing blindly, GET the list, merge/remove IDs, and PUT with the original list as expected_user_ids. On 409, fetch and ask the operator to reconcile; do not retry blindly.

## AI management tools

The built-in management MCP server exposes:

- `search_oauth_users`: search/include/page/per_page as above.
- `get_oauth_allowed_users`: numeric server_id.
- `set_oauth_allowed_users`: server_id, allowed_user_ids, optional expected_user_ids.

The token must identify a current WordPress user with server-management capability. Reads require mcp:read or mcp:admin; updates require mcp:write or mcp:admin. Read-only tokens cannot change permissions. Set is annotated as a permission-changing/destructive operation, and its description requires explicit user authorisation. These tools are on the management server, never exposed through an individual Alegra server token. Client tool catalogues may need a refresh after deployment.

## PHP hook / ability integrations

Custom abilities and trusted integrations should call the shared service instead of editing auth_config directly:

```php
$before = \GetMCP\Auth\OAuthUserAccess::get( $server_id );
if ( is_wp_error( $before ) ) { return $before; }
return \GetMCP\Auth\OAuthUserAccess::set(
    $server_id,
    $approved_user_ids,
    $before['allowed_user_ids']
);
```

The default actor is the current authenticated WordPress user. A trusted server-side adapter may pass a fourth actor ID after it has verified that principal (as the management MCP dispatcher does); never take this value from an untrusted request. Use the current account's management capability in the ability's permission callback as well. This handoff provides actual management MCP tools and a shared PHP service; it does not automatically register an additional WordPress Abilities API catalogue.

Observe changes with:

```php
add_action( 'getmcp_oauth_allowed_users_updated',
    function ( $server_id, $new_ids, $old_ids, $actor_id ) {
        // Record your audit event; never log credentials or OAuth tokens.
    }, 10, 4
);
```

The action fires after a successful change through the dedicated API/tools or normal ServerManager updates (including the Authentication form). No-op dedicated saves do not fire it. The action is for notification/auditing, not permission bypass; runtime authorisation continues to use the saved allowlist. The default form still stores its native auth_config through the existing server PATCH flow. Initial server creation uses the existing getmcp_server_created hook.
