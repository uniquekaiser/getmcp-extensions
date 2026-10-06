# Meta Ads and Instagram

Select the user's actual app and business. Preserve existing callbacks and login settings. Determine app role/access level from the console and verify real consent; an app-mode switch label alone is ambiguous.

## Shared Meta app preparation

1. Inspect the existing app's supported use cases, Facebook Login configuration, app roles, permission access, and required actions. Reuse a suitable app when authorized. Do not rotate an existing secret without a reason; other integrations may depend on it.
2. Read current official provider instructions. For the Ads bridge, use [Meta Ads MCP setup](https://developers.facebook.com/documentation/ads-commerce/ads-ai-connectors/ads-mcp-server/ads-mcp-server-get-started). For Instagram, use [Instagram API with Facebook Login](https://developers.facebook.com/documentation/instagram-platform/instagram-api-with-facebook-login/get-started.md).
3. Copy the two callbacks shown by the GetMCP Authentication forms. A typical installation uses:

```text
{{site_url}}/mcp/meta-ads/oauth/callback
{{site_url}}/mcp/instagram/oauth/callback
```

4. Add them to Valid OAuth Redirect URIs, retaining every pre-existing entry. Prepare the final save for any required Browser access approval. Re-read the saved list. Keep strict redirect matching and HTTPS protection.
5. Inspect the app's login variant. If Facebook Login for Business requires a configuration ID or supported login flow, verify that the installed broker forwards it. Do not invent a configuration ID or assume that a generic scope-only flow supports every business-login variant.
6. Have the user reveal the existing app secret in Basic settings and enter **App ID → Client ID**, **App secret → Client Secret** in each GetMCP server. Keep the operator-stored API credential unset for per-user access. Use the supported Graph version consistently in authorize/token URLs and REST tools.

The observed configuration used Facebook authorization at `https://www.facebook.com/v26.0/dialog/oauth` and token exchange at `https://graph.facebook.com/v26.0/oauth/access_token`. Discover the currently supported version before creating a new setup; these are a dated example, not a permanent version requirement.

Meta scopes can be comma-separated even when GetMCP's generic field help says space-delimited. Verify the provider's format and broker forwarding behavior before changing delimiters.

## Meta Ads bridge

The official remote Ads MCP and a GetMCP REST/JSON-RPC bridge are different connections. Meta's own remote OAuth can be available without an operator-owned app; that does not automatically configure the REST bridge's generic OAuth broker.

1. Confirm the app/user is eligible for Ads MCP and has the permissions needed for the chosen tools. The observed minimum was `ads_mcp_management` plus `ads_read` or `ads_management`; other tools may need additional permissions.
2. For the requested read/write catalog, verify rather than blindly grant this observed scope set:

```text
ads_mcp_management,ads_read,ads_management,catalog_management,business_management,pages_show_list,instagram_basic
```

3. Complete required consent and any selected-business/ad-account choices. Personal authorization can expose only assets available to that identity and allowed by the app's access tier. Standard Access and app roles may suffice for own/test assets; broader external use can require review.
4. Authenticate from the intended MCP client. Run the bridge's tool-list operation, follow its cursor, and inspect names, schemas, and annotations returned by the live upstream service.
5. Choose a harmless account-list or reporting operation from the catalog and call it through the bridge. Check both the outer and upstream JSON-RPC `error` and MCP `result.isError`, not just HTTP 200. If the adapter returns SSE as text, parse the `data:` payload before assessing success. Some structured-content fields contain JSON strings and need a further JSON decode. Keep credentials redacted at every layer.
6. Inspect each discovered account's eligibility and queryability flags, such as `is_ads_mcp_enabled` and `is_queryable`, and the provider's accompanying reasons. A user may have ordinary ad-account access while Ads MCP is unavailable during rollout, or while an old account is closed. Test a selected enabled, queryable account and record restricted accounts without changing their settings. Do not infer that every listed account can be queried.

The observed bridge exposed `list_meta_ads_tools` and `call_meta_ads_tool`. It could forward upstream writes; it did not advertise every upstream tool separately. For a no-input tool, omit an optional wrapper `arguments` field when the adapter would turn an empty object into a JSON array. Validate behavior against the installed version.

Never execute campaign creation, budget changes, publication, or other ad mutations merely to check a connection. Do not use an admin system-user identity where Meta requires an employee system user.

## Instagram: account discovery and tools

This workflow follows the reference's **Instagram API with Facebook Login**. It requires a professional Instagram account linked to a Facebook Page. Instagram Login uses different permissions and tokens; do not mix the two schemes.

1. Confirm the professional account/Page link and the user's Page tasks and app role. Check required permission access and review for external accounts.
2. Verify scopes for the enabled reporting, publishing, insights, and comment tools. The observed set was:

```text
instagram_basic,instagram_content_publish,instagram_manage_insights,instagram_manage_comments,pages_show_list,pages_read_engagement
```

Messaging adds `instagram_manage_messages` and `pages_manage_metadata`. If messaging is unavailable, explain why those permissions and tools are deferred; do not silently claim full messaging support.

3. Connect with Facebook OAuth, selecting the intended available Pages/assets in consent.
4. Run the configured Page-list tool, follow pagination, then look up each linked Instagram business account. The observed names were `get_account_pages` and `get_instagram_account`.
5. Use a returned Instagram account ID for profile and a small recent-media read, such as `get_profile_info` and `get_media_posts`. Distinguish Page ID, Instagram account ID, media ID, container ID, comment ID, and Instagram-scoped recipient ID.
6. Compare the current reference's complete tool source with the adaptation. Include publishing and comment writes when requested. Explain orchestration for image, Reel, and carousel workflows, and distinguish tagged media from broader mentions.

For authorized publishing, create media containers, check readiness, and publish a ready container with its creation ID. For a carousel, create children and then the parent according to current publishing constraints. A container creation response alone does not prove a published post. Re-read the resulting media for an authorized publication. See [content publishing](https://developers.facebook.com/docs/instagram-platform/instagram-api-with-facebook-login/content-publishing).

## Messaging token gap

The ordinary Facebook OAuth flow supplies a User token. The referenced conversation/message operations require an appropriate Page access token. Confirm the actual token type before activating them.

The observed GetMCP adaptation saved `get_conversations`, `get_conversation_messages`, and `send_dm` as drafts because it lacked automatic per-user Page-token selection. Newer versions may fix this; inspect implementation and test a harmless conversation read before declaring support.

A provider-specific implementation should privately discover selectable Pages, securely store per-user Page tokens, select the right token only for the relevant tools, handle expiry/revocation, and redact all tokens from tool results, logs, exports, and UI snapshots.

A separate, protected messaging server with an operator-stored Page token is a possible authorized workaround. It is a shared Page identity, not per-user access to all accounts. Do not add it without the user's requested scope and applicable access approval.

Use Page IDs for conversation discovery and returned conversation IDs for message reads. Send only an expressly requested message to the verified Instagram-scoped recipient under current messaging rules. See [Conversations API](https://developers.facebook.com/docs/messenger-platform/instagram/features/conversation) and [Send API](https://developers.facebook.com/docs/messenger-platform/instagram/features/send-message).

## Renewal and diagnostics

Do not assume a generic OAuth `refresh_token` grant renews Facebook User or Page tokens. Verify provider-specific token extension and reconnect behavior. Document a missing renewal flow instead of promising automated permanent authentication.

| Failure | Next check |
| --- | --- |
| Redirect blocked | Exact callback registration, strict matching, HTTPS, and app login configuration. |
| Invalid/denied scope | Supported permission, app access tier, review, app role, and selected use case. |
| Ads tools unavailable | Ads MCP eligibility and permission, token identity, account rollout/queryability flags, and live upstream error. |
| No Instagram business account | Page linkage, professional account type, Page tasks, selected assets, and pagination. |
| DMs fail while reporting works | Page token type, messaging permissions, and the Page/IG identifier distinction. |
| Connection expires | Supported extension/reconnect behavior; do not retry writes automatically. |

Meta sidebar items may appear as button overlays that do not navigate when clicked. After an ineffective click, inspect the visible state rather than repeatedly clicking. A single focused navigation to the relevant official settings page can be used when allowed by Browser guidance; verify the actual app and page before editing.
