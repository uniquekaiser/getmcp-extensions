# Graph Report - getmcp-extensions  (2026-10-15)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 322 nodes · 453 edges · 30 communities (10 shown, 20 thin omitted)
- Extraction: 81% EXTRACTED · 19% INFERRED · 0% AMBIGUOUS · INFERRED: 84 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `0e796ae3`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Community 0
- Community 1
- Community 2
- Community 3
- Community 4
- Community 5
- Community 6
- Community 7
- Community 8
- Community 10
- Community 12
- Community 14
- Community 17
- Community 20
- Community 21
- Community 22
- Community 23
- Community 24
- Community 26
- Community 27

## God Nodes (most connected - your core abstractions)
1. `AuthenticationSettings` - 26 edges
2. `PageTokens` - 26 edges
3. `Runtime` - 24 edges
4. `ProviderTokens` - 23 edges
5. `MarketingModule` - 22 edges
6. `ProviderConnections` - 19 edges
7. `ConfigurationImport` - 10 edges
8. `Updater` - 10 edges
9. `ProviderDiscovery` - 9 edges
10. `MarketingTemplates` - 8 edges

## Surprising Connections (you probably didn't know these)
- `{closure#2}()` --calls--> `Runtime`  [INFERRED]
  includes/class-remote-catalogue.php → includes/class-runtime.php
- `{closure#2}()` --calls--> `AuthenticationSettings`  [INFERRED]
  includes/class-connection-screens.php → includes/class-authentication-settings.php
- `{closure#28}()` --calls--> `AuthenticationSettings`  [INFERRED]
  includes/class-marketing-module.php → includes/class-authentication-settings.php
- `{closure#35}()` --calls--> `AuthenticationSettings`  [INFERRED]
  includes/class-marketing-module.php → includes/class-authentication-settings.php
- `{closure#1}()` --calls--> `PageTokens`  [INFERRED]
  includes/class-marketing-module.php → includes/class-page-tokens.php

## Import Cycles
- None detected.

## Communities (30 total, 20 thin omitted)

### Community 0 - "Community 0"
Cohesion: 0.10
Nodes (5): AuthenticationSettings, SettingsConflict, ConnectionHeaders, {closure#31}(), {closure#35}()

### Community 1 - "Community 1"
Cohesion: 0.14
Nodes (4): {closure#1}(), {closure#2}(), {closure#3}(), PageTokens

### Community 2 - "Community 2"
Cohesion: 0.06
Nodes (4): ConfigurationImport, {closure#11}(), {closure#13}(), {closure#15}()

### Community 8 - "Community 8"
Cohesion: 0.29
Nodes (6): license, name, require, php, yahnis-elsts/plugin-update-checker, type

### Community 10 - "Community 10"
Cohesion: 0.71
Nodes (6): {closure#1}(), {closure#2}(), getmcp_extensions_guard_has_blocked(), getmcp_extensions_guard_marketing_missing(), getmcp_extensions_guard_ready(), getmcp_extensions_guard_row()

### Community 12 - "Community 12"
Cohesion: 0.62
Nodes (6): clientAuthCard(), expandNativeAuthMenu(), isWordPressMode(), mount(), scheduleNativeMenuExpansion(), syncNativeOAuthSettings()

### Community 14 - "Community 14"
Cohesion: 0.18
Nodes (3): {closure#23}(), {closure#28}(), ProviderConnections

### Community 17 - "Community 17"
Cohesion: 0.05
Nodes (6): {closure#2}(), {closure#4}(), ConnectionScreens, MarketingModule, MarketingTemplates, Runtime

### Community 21 - "Community 21"
Cohesion: 0.31
Nodes (4): build(), literal_entries(), main(), public_url()

### Community 24 - "Community 24"
Cohesion: 0.29
Nodes (3): {closure#17}(), {closure#25}(), ProviderDiscovery

## Knowledge Gaps
- **5 isolated node(s):** `license`, `name`, `php`, `yahnis-elsts/plugin-update-checker`, `type`
  These have ≤1 connection - possible missing edges. (Counts symbols only; 176 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **20 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `MarketingModule` connect `Community 17` to `Community 0`, `Community 2`, `Community 14`, `Community 23`, `Community 26`?**
  _High betweenness centrality (0.159) - this node is a cross-community bridge._
- **Why does `Runtime` connect `Community 17` to `Community 25`, `Community 3`, `Community 22`?**
  _High betweenness centrality (0.119) - this node is a cross-community bridge._
- **Why does `AuthenticationSettings` connect `Community 0` to `Community 14`, `Community 17`, `Community 20`, `Community 24`, `Community 26`?**
  _High betweenness centrality (0.098) - this node is a cross-community bridge._
- **Are the 18 inferred relationships involving `AuthenticationSettings` (e.g. with `.prepare()` and `.read()`) actually correct?**
  _`AuthenticationSettings` has 18 INFERRED edges - AST-inferred connections that need verification._
- **Are the 12 inferred relationships involving `PageTokens` (e.g. with `{closure#1}()` and `{closure#2}()`) actually correct?**
  _`PageTokens` has 12 INFERRED edges - AST-inferred connections that need verification._
- **Are the 5 inferred relationships involving `Runtime` (e.g. with `{closure#2}()` and `.activate()`) actually correct?**
  _`Runtime` has 5 INFERRED edges - AST-inferred connections that need verification._
- **Are the 14 inferred relationships involving `ProviderTokens` (e.g. with `{closure#28}()` and `.discover()`) actually correct?**
  _`ProviderTokens` has 14 INFERRED edges - AST-inferred connections that need verification._