# Official remote MCP catalogue

## Requirements

Expose the reviewed hosted MCP entries in a separate Remote MCP Servers menu using native Templates design tokens. Selecting an entry opens editable settings, defaults to Active with the creating WordPress user selected, and offers Save & Authorize for OAuth or Save & Test otherwise. It reuses existing personal OAuth and gateway restrictions and creates no native API tools. No provider accounts, memberships, credentials or existing routes change merely by browsing or selecting an entry.

## Included in the candidate build

The bundled snapshot contains 274 distinct hosted connections: 208 personal OAuth, 61 without upstream authentication, and 5 shared operator-token entries. Search, category filtering and bounded pages are available in Remote MCP Servers. Its React components reuse GetMCP's Card, Input, Select, Button, PageHeader and PageContainer. Existing native, marketing and Telegram templates retain their own setup paths under Templates. Page-header actions wrap according to the remaining content width when both admin sidebars are visible.

These are reference classifications, not 274 successful live integration certifications. The reference check date is 2026-10-09; every card discloses that current endpoint and authentication status remain unverified. Tool counts are unknown until discovery. An entry listed without authentication can still change its requirements or impose usage limits.

The safe importer prefers curated IDs/endpoints, removes duplicates and rejects unsupported authentication, local/demo addresses, literal IPs, embedded credentials and query/fragment-bearing endpoints. Four endpoint variants were excluded: Fiscal.ai legacy SSE, Hugging Face with query parameters, Square legacy SSE and Third Bridge legacy SSE. The curated Hugging Face and Square HTTP entries remain available.

The catalogue uses its own native `/remote-servers` route and sidebar item. `/connections` continues to show gateways and saved remote connections. WordPress menu selection and reload URLs follow native navigation and browser history. Old catalogue bookmarks ending in `#/connections` are normalized when opened. Both supported add-on admin adapters are rebuilt through `tools/build-ui.py`; original GetMCP vendor files remain untouched. Each selected adapter is served with its own content-hash cache version.

## Setup

1. Enable the existing Remote MCP and project gateway modules on a supported GetMCP installation.
2. Open **GetMCP → Remote MCP Servers**, search and choose **Connect** on an entry.
3. Review the endpoint, authentication and allowed users. Active status and your current WordPress user are preselected; no other users, secret, gateway membership or original `/mcp` publication are added. Change these settings as needed.
4. Choose **Save & Authorize** to save and immediately open personal OAuth. Discovery and client registration are automatic where the provider supports them. Complete provider consent before using OAuth tools. For token-based or unauthenticated services, **Save & Test** discovers capabilities after saving; enter a token first where required. A failure retains the same saved connection and reports the problem, allowing correction and retry. **Save without connecting** is available to save a draft or defer authorization.
5. Explicitly select the connection in an active project gateway with its own allowed users. Gateway membership delegates use of this connection; direct user restrictions are independent. Each additional permitted user signs in through **My MCP Connections**. Providers without automatic registration still need an operator application under Advanced OAuth settings. Shared-token entries use the existing encrypted operator credential fields.
6. Preview the gateway to discover capabilities and verify the connection before distributing its endpoint. Provider failures must be resolved per service; the catalogue does not certify consent, scopes, refresh or write behavior.

The current architecture persists a small `remote-mcp` connection record for gateway routing and encrypted per-user credentials. It creates no native tool definitions and runs no provider software on WordPress. PHP hosting needs neither Node.js nor a companion service. Inbound WordPress sign-in, upstream OAuth, custom headers, safe HTTP validation, cache isolation and transport negotiation are reused unchanged.

## Maintenance and provenance

`tools/import-remote-catalogue.py` reads literal data from the two supplied reference TypeScript catalogues without evaluating TypeScript or executing the crawler. The generated JSON records both source SHA-256 values, classifications and exclusion reasons. Rebuild locally:

```powershell
python tools/import-remote-catalogue.py --reference 'C:\path\to\MCP-proxy'
python tests/test-remote-catalogue.py
```

The reference `crawl-mcpservers.browser.js`, `check-catalogue.mjs` and `build-official-catalogue.mjs` remain developer intake tools. Their crawling, network checks and application-only exclusions were reviewed, not copied into the WordPress runtime. Review official provider ownership, endpoint, transport and authentication before importing newly checked output:

```powershell
python tools/import-remote-catalogue.py --reference 'C:\path\to\MCP-proxy' --checked-json 'C:\path\to\reviewed-candidates.json'
```

The checked JSON accepts the reviewed `check` values `none`, `oauth` or `bearer`; `oauth-app`, dead and unverified entries are excluded. With both options, references win duplicates and checked candidates extend them. Using `--checked-json` alone **replaces** the snapshot. There is no automatic crawler, provider signup, scheduled refresh or public URL-import endpoint.

The catalogue follows the HTTP transport boundary; legacy SSE-only services require separate review. Relevant primary references: [MCP Registry remote-server guidance](https://github.com/modelcontextprotocol/registry/blob/main/docs/modelcontextprotocol-io/remote-servers.mdx), [TypeScript SDK transport guidance](https://ts.sdk.modelcontextprotocol.io/v2/clients/connect), and the [Linear MCP setup guide](https://linear.app/docs/mcp). Reading these does not verify every listed provider.

## Interfaces

Authenticated managers with `getmcp_manage_servers` can read `GET /wp-json/getmcp/v1/remote-catalogue?q=notion&category=Productivity&page=1` and `GET /wp-json/getmcp/v1/remote-catalogue/notion`. Cookie requests require a REST nonce. Pagination returns at most 12 entries plus total, categories, reference date and `has_more`. Disabling Remote MCP refuses these routes.

The native template index is not extended with remote entries. Catalogue detail returns public metadata and a suggested configuration whose user ID comes from the authenticated WordPress session. It performs no writes. Save, authorize and preview use the existing protected connection APIs. Licensing and native permission failures are preserved. User selections are reviewed before save and are never overwritten on existing records merely by opening the catalogue.

## Migration and rollback

No schema migration, credential migration or automatic connection creation occurs. Existing secrets, allowlists, routes, vendor files and original gateway settings are not rewritten. Roll back by restoring the prior add-on package with the same salts/database. Newly saved remote connections use the existing supported schema; remove only unwanted drafts deliberately. Disabling the add-on retains its established endpoint guard and does not remove records or expose protected endpoints.

## Verification

See `evidence/local-checks.json`, `evidence/remote-catalogue-integration.json` and `docs/verification-remote-catalogue.md`. Machine-readable Studio expectations are in `docs/testing/test-definitions.json`; gap status there means no bound Studio execution evidence, even where separate local tests passed. Live consent, harmless reads and write behavior across the 274 providers remain unverified. No live deployment or GitHub release is part of this candidate implementation.
