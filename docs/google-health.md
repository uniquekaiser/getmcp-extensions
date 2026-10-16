# Google Health

## Requirements

Owner requirement: implement Google Health through native GetMCP tools and the Extensions add-on, starting with read access and daily/weekly summaries. Include nutrition data where available. Use the one shared local fixture and preserve existing accounts, sessions, servers and credentials.

The template installs twelve native HTTP tools in a **draft server**, with no credentials and an empty personal-user allowlist. It does not launch Node, npx or a separate MCP service. The add-on executes the reviewed native parameter mappings and handles personal OAuth, validation, privacy and summary orchestration. Google Health is available independently of the Marketing switch; it requires the compatible Extensions runtime.

## Setup

1. Open GetMCP → Templates and install **Google Health · Synergetic** into a new server.
2. Enable Google Health API in your Google Cloud project and configure a web OAuth application. Register the exact callback shown by the server, ending in `/oauth/callback`.
3. Enter the client ID and client secret in Authentication/provider settings. Secrets use the existing encrypted credential store.
4. Select permitted users under personal-provider access and activate the server. Each user connects Google in **My MCP Connections**. Empty personal access permits nobody directly.
5. Create a project gateway with WordPress sign-in, select this server, and explicitly allow its intended users. Connect your MCP client to that gateway. The current core's broker-only Google grants do not retain an authoritative upstream scope set; this first integration requires a WordPress user and their personal Google connection.
6. Call `google_health_connection_status`, then a harmless profile/data read. Connection status reports local state only; it never claims a successful live read.

Use only the scopes needed for your integration. Defaults request profile, settings, activity/fitness, measurements, sleep and nutrition **read-only** scopes. Partial consent preserves permitted summary domains. Missing permissions are reported per domain, with no shared credential fallback. Nutrition includes `nutrition-log`, `hydration-log`, `food` and `food-measurement-unit` where supported. No nutrition-write tool or write scope is installed.

## Tools

| Tool suffix (`google_health_`) | Behavior |
| --- | --- |
| `get_identity`, `get_profile`, `get_settings` | One authorized Google read with identity/location filtering |
| `list_data_points`, `reconcile_data_points` | One page; explicit `page_token` pagination and optional source family |
| `daily_rollup` | Civil `start_date` inclusive and `end_date` exclusive; read-only POST |
| `rollup` | Exact timezone-bearing RFC3339 interval, positive seconds window; read-only POST |
| `nutrition` | One page of nutrition-log data; requires nutrition.readonly |
| `daily_summary` | Ten domains for a civil date, with observed data and explicit gaps |
| `weekly_summary` | Same domains over seven civil dates ending on `date` |
| `data_inventory` | Curated fourteen-type scope/operation coverage; not a live availability claim |
| `connection_status` | Personal connection, scope and storage information without tokens |

Examples: `daily_summary` with `{"date":"2026-10-15","timezone":"America/Bogota"}`; `nutrition` with `{"page_size":25}`. The timezone determines the default date; Google civil dates retain their recorded meaning and are not rebucketed into the requested timezone.

Summaries contain daily aggregate/reconciled observations for steps, distance, calories, active zone minutes, resting heart rate, HRV, sleep, weight, nutrition and hydration. They distinguish `observed`, `no_data`, `partial`, `unavailable` and `tool_disabled`. Each domain is bounded to three pages; incomplete pagination stays incomplete. No-data is never converted into zero. Disabling an underlying native tool also disables that summary domain. Summaries make no medical assessments or inferred measurements.

## Privacy and boundaries

Tokens remain in the encrypted per-user WordPress connection store. Unlike the reference connector's local-first deployment, WordPress hosting now processes health data. Health responses bypass the native response cache even when a global cache TTL is enabled. The normal protocol handler logs metadata only: arguments, response payloads and request/response replay are omitted for health tools. Admin Test returns filtered MCP output, without raw HTTP bodies or credentials. Third-party WordPress hooks, infrastructure logs and client-side storage remain outside this guarantee.

Recursive filtering removes known identity, secret and location keys, including location containers, while preserving physiological data and the pagination cursor. This is a documented key filter, not a guarantee against arbitrary future location encodings. No raw-mode escalation is exposed. Only the fixed reviewed HTTPS endpoints/methods are accepted, redirects are refused, and upstream errors are mapped to safe codes. Direct native calls and summaries use the same scope and access checks.

## Sources and local verification

- [Reference project](https://github.com/davidmosiah/google-health-mcp), reviewed at 0.7.8 / fc59b0a; implementation uses current Google contracts rather than assuming complete reference parity.
- [List contract](https://developers.google.com/health/reference/rest/v4/users.dataTypes.dataPoints/list)
- [Daily rollup contract](https://developers.google.com/health/reference/rest/v4/users.dataTypes.dataPoints/dailyRollUp): `range.start/end` contain `CivilDateTime.date` objects.
- [Exact rollup contract](https://developers.google.com/health/reference/rest/v4/users.dataTypes.dataPoints/rollUp)
- [Scopes](https://developers.google.com/health/scopes)

Run `python tools/test-google-health.py` against the existing `getmcp-telegram-qa` WordPress container, then `python tools/verify.py` with a reviewed `GETMCP_VENDOR_ROOT`. The health suite uses synthetic Google HTTP with real database, native template installation, provider grants, executor and protocol logging. It deletes only its disposable servers, users and logs and compares unrelated server/connection state before and after. It does not prove real Google consent, eligibility or populated nutrition data. Evidence is saved under `evidence/google-health/`; browser/real-provider acceptance and compatibility gaps must be reported separately.
