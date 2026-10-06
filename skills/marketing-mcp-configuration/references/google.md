# Google: project, OAuth client, and the three servers

Use the current [Google web OAuth guide](https://developers.google.com/identity/protocols/oauth2/web-server), the provider consoles, and each GetMCP callback shown in its UI. Keep account-specific URLs and IDs out of reusable output.

## Shared setup

1. In Google Cloud Console, select the intended project or create one when requested. Record its project ID privately.
2. Enable **Google Ads API**, **Google Analytics Admin API**, **Google Analytics Data API**, and **Google Search Console API** as applicable. Prepare any terms for the required action-time approval before enabling them through Browser. Verify all requested APIs in **Enabled APIs & services**.
3. Configure Google Auth Platform branding, user support email, developer contact, and audience. Use External for consumer Google identities; Internal is available only for qualifying Workspace organizations. Preserve an existing app's branding and contacts unless the user requests changes.
4. If Testing, add the actual Google identities that will connect as test users. Registering a support email does not automatically add a test user. Do not silently publish the app to escape testing limits.
5. In Data Access, register the required scopes in the table below. Request only the scopes needed for the chosen tools, including writes explicitly requested. Prepare wider access for applicable Browser approval before the final save.
6. Create a **Web application** OAuth client. Add the exact callbacks copied from the three GetMCP servers. A typical root installation uses:

```text
{{site_url}}/mcp/google-ads/oauth/callback
{{site_url}}/mcp/google-analytics/oauth/callback
{{site_url}}/mcp/google-search-console/oauth/callback
```

7. If Google offers an AI-agent client designation, inspect its help and select it for an MCP agent client. JavaScript origins are unnecessary for a purely server-side broker unless the actual implementation requires them.
8. Have the user securely store the generated credential JSON or Client ID/Secret. New secrets may be visible only at creation. Let the user enter and submit credentials when the Browser handoff policy requires it. Avoid a snapshot of the creation dialog, including copy-button labels.
9. Enter the same application's credentials into the three GetMCP servers, keeping their scopes separate. Keep server-stored API credentials unset. Save each and independently check credential-presence booleans. Watch for browser autofill inserting WordPress credentials.

| Server | Authorize URL | Token URL | Default scope |
| --- | --- | --- | --- |
| Google Ads | `https://accounts.google.com/o/oauth2/v2/auth` | `https://oauth2.googleapis.com/token` | `https://www.googleapis.com/auth/adwords` |
| Google Analytics | Same | Same | `https://www.googleapis.com/auth/analytics.readonly` |
| Google Search Console | Same | Same | `https://www.googleapis.com/auth/webmasters` for configured writes; use `.readonly` only for a read-only catalog |

The provider callback belongs to GetMCP. The MCP client's redirect is handled on the other side of the broker; do not substitute the client's localhost callback into the provider application.

Google settings should request offline access. The observed broker supported `extra_authorize_params` with `access_type=offline` and `prompt=consent`. Verify the installed implementation and saved settings. Google refresh tokens issued to an External app in Testing commonly expire after seven days for these scopes. See [Google's production-readiness guide](https://developers.google.com/identity/protocols/oauth2/production-readiness/overview). Do not promise indefinite access without the relevant publishing/verification and refresh checks.

Check those extra parameters again after saving Authentication: the observed UI dropped them when rebuilding its configuration. See the native-manager workaround and credential-storage details in [GetMCP integration](getmcp.md).

The bundled [one-time maintenance helper](../assets/maintenance/restore-google-offline-oauth.php) provides a tested administrator workaround after affected saves. From the intended WordPress directory, run `wp eval-file {{skill_directory}}/assets/maintenance/restore-google-offline-oauth.php google-ads google-analytics google-search-console --user={{wordpress_admin_login}}`, using actual imported slugs. It validates Google External Provider OAuth, merges offline/consent parameters, preserves stored credentials and other settings, and verifies the result. It neither activates servers nor completes consent. It is optional custom code supplied with the skill; no vendor-file replacement is required. Prefer the fixed native UI when available.

## Google Ads

The current [developer-token migration notice](https://developers.google.com/google-ads/api/docs/api-policy/developer-token) says developer tokens were sunset on 9 September 2026. Access now belongs to the Cloud project that owns the OAuth client; token headers are optional and ignored. This supersedes the page's older generated summary and legacy manager API Center instructions. Recheck the notice when using this skill.

1. Enable the API in the project that owns the client.
2. Open Google Ads API access management from the Cloud API service page. Verify the current access level. Test access permits test accounts only; production access requires an eligible upgrade such as Explorer. Inspect [current access levels](https://developers.google.com/google-ads/api/docs/api-policy/access-levels) and prepare the application before any required final approval.
3. For migrated configurations, remove obsolete required developer-token connection variables and template headers after confirming the new requirement. Update server instructions and client connection examples too. Preserve unrelated settings.
4. Connect through OAuth as the Google user with Ads account access. Run `list_accessible_customers` or its configured equivalent. This returns directly accessible customers, not necessarily every manager's children.
5. For a returned manager, query `customer_client` to discover child accounts, then use the appropriate manager `login_customer_id` without hyphens for a selected child. Follow pagination; a manager itself is not a campaign account.

Example harmless GAQL:

```sql
SELECT customer_client.id, customer_client.descriptive_name,
       customer_client.manager, customer_client.status
FROM customer_client
```

Then, for one selected account:

```sql
SELECT campaign.id, campaign.name, campaign.status
FROM campaign
LIMIT 5
```

The observed Google Ads reference offered customer discovery, GAQL reads, and field/resource metadata. Recheck its current source before making a complete-tool claim; do not add ad mutations merely because the `adwords` scope is broad.

## Google Analytics

1. Enable both Analytics APIs and configure the read-only OAuth scope.
2. Confirm the connecting user already has the intended Analytics account/property access. Changing that access is a separate action from signing in.
3. Run `get_account_summaries` or its mapped account-list operation. Follow pagination to enumerate accessible GA4 properties.
4. Use a returned property for a small `run_report`, for example `activeUsers` over the last seven days. Match the configured schema: the observed adaptation expected the numeric property ID without `properties/`.

The referenced MCP's operations were reads. The REST adaptation may split combined custom-dimension/metric listing into two tools and may add reporting metadata. Document those mappings rather than asserting one-to-one parity. See [Analytics API prerequisites](https://developers.google.com/analytics/devguides/reporting/data/v1/quickstart).

## Google Search Console

1. Enable Search Console API; use the full `webmasters` scope when site/sitemap writes are part of the requested catalog.
2. Confirm the Google user has access to the intended Search Console properties. OAuth does not establish ownership. See [Search Console authorization](https://developers.google.com/webmaster-tools/v1/how-tos/authorizing).
3. Run `list_properties` or the mapped sites-list tool. Preserve returned identifiers exactly: `sc-domain:{{domain}}` or an HTTPS URL including its trailing slash. Supply raw values when the adapter handles path encoding.
4. For one returned property, run a small date-bounded analytics query or inspect a known URL. Search data can lag; use complete dates.
5. Include upstream site addition/removal and sitemap submission/deletion when available and requested. These configure account property/sitemap records, not site ownership or file deletion. Execute them only for a separate, concrete authorized change and re-read afterward.

Composite comparisons, overview reports, batch inspections, and indexing summaries can be implemented by orchestration over query/inspection tools. Identify this as composition. Local credential-file utilities and reauthentication functions should become client reconnect instructions rather than arbitrary server file access.

## Common failures

| Failure | Next check |
| --- | --- |
| Download JSON produces no file | Keep the creation dialog open; check browser download support. The user can try their own browser. Do not close a one-time secret dialog prematurely. |
| OAuth application not configured | Re-read saved credentials; a Client ID entered in Cloud does not save it in GetMCP. |
| `redirect_uri_mismatch` | Exact callback from the GetMCP UI, including scheme, path, slug, and suffix. |
| Access blocked in Testing | Actual login identity appears in the test-user list. |
| Empty account list or denied property | Identity, account permissions, manager relationships, and pagination. |
| `CLOUD_PROJECT_NOT_APPROVED_FOR_PRODUCTION` | Ads access level of the project owning the OAuth client. |
| Initial sign-in works but later fails | Testing token lifetime, revocation, stored refresh-token presence, and refresh behavior. |

Do not change project billing as an automatic workaround for access errors. Diagnose against current official notices and involve the user if a billing change is necessary.
