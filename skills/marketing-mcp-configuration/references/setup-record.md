# Portable setup record

Copy this template into a separate setup artifact. Keep private installation/account details in that artifact; do not merge them into the reusable skill.

## Context

- Date checked: `{{date}}`
- Exact connector/site: `{{connector}}`, `{{site_url}}`
- GetMCP/runtime extensions: `{{version_and_features}}`
- Intended MCP client and transport: `{{mcp_client}}`, `{{transport}}`
- Identity model: `{{per_user_or_shared}}`
- Requested tool/write scope: `{{requested_scope}}`

## Provider and server state

| Provider | Endpoint | Credential configuration | Consent/data read | Remaining action |
| --- | --- | --- | --- | --- |
| Google Ads | `{{endpoint}}` | `{{presence_flags}}` | `{{evidence}}` | `{{action}}` |
| Google Analytics | `{{endpoint}}` | `{{presence_flags}}` | `{{evidence}}` | `{{action}}` |
| Google Search Console | `{{endpoint}}` | `{{presence_flags}}` | `{{evidence}}` | `{{action}}` |
| Meta Ads | `{{endpoint}}` | `{{presence_flags}}` | `{{evidence}}` | `{{action}}` |
| Instagram | `{{endpoint}}` | `{{presence_flags}}` | `{{evidence}}` | `{{action}}` |

## Step record

| Step | Pre-existing state | Narrow change | Approval/handoff if required | Saved verification |
| --- | --- | --- | --- | --- |
| `{{step}}` | `{{baseline}}` | `{{change}}` | `{{approval_or_none}}` | `{{evidence}}` |

List the exact callbacks copied from the installation's UI. Record enabled provider APIs, app testing/publishing state, test-user presence, approved access level, configured versus actually granted scopes, and selected asset types. Store no secrets, auth codes, bearer tokens, or encrypted credentials.

## Tool coverage and writes

Link the exact upstream revision or live catalog date. Map every operation to a configured tool, a composition, a local-only replacement instruction, or a named gap. Record write status without executing it as a test.

## Verification

Distinguish passed, failed, not performed, and unavailable. Include the real client/identity and harmless operation, result type/count, pagination coverage, and limitations. A saved credential, discovery response, or initialized server alone is not an authenticated account query.

## Custom code and handoff

State **configuration only; no custom runtime code added** when accurate. Otherwise list every authored file, deployment destination, narrow purpose, local checks, deployed verification, and remaining acceptance gap. List proposals separately.

## User actions

Provide only the exact remaining steps: secure credential entry, specific provider approvals, identity selection, client OAuth consent, or required review. Explain a required pause by naming its actual source. Keep unresolved prerequisites explicit rather than calling the setup complete.
