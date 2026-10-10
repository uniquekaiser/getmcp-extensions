# Graph Report - getmcp-extensions  (2026-10-10)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 287 nodes · 398 edges · 20 communities (7 shown, 13 thin omitted)
- Extraction: 79% EXTRACTED · 21% INFERRED · 0% AMBIGUOUS · INFERRED: 82 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `484ad9cb`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Community 0
- Community 1
- Community 2
- Community 3
- Community 4
- Community 5
- Community 7
- Community 8
- Community 10
- Community 17
- Community 18
- Community 19

## God Nodes (most connected - your core abstractions)
1. `AuthenticationSettings` - 26 edges
2. `PageTokens` - 26 edges
3. `ProviderTokens` - 23 edges
4. `MarketingModule` - 22 edges
5. `Runtime` - 21 edges
6. `ProviderConnections` - 19 edges
7. `ConfigurationImport` - 10 edges
8. `Updater` - 10 edges
9. `ProviderDiscovery` - 9 edges
10. `MarketingTemplates` - 8 edges

## Surprising Connections (you probably didn't know these)
- `{closure#4}()` --calls--> `MarketingModule`  [INFERRED]
  includes/class-connection-screens.php → includes/class-marketing-module.php
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

## Communities (20 total, 13 thin omitted)

### Community 0 - "Community 0"
Cohesion: 0.07
Nodes (6): AuthenticationSettings, SettingsConflict, ConnectionHeaders, {closure#31}(), {closure#35}(), MarketingModule

### Community 1 - "Community 1"
Cohesion: 0.05
Nodes (13): {closure#1}(), {closure#17}(), {closure#2}(), {closure#23}(), {closure#25}(), {closure#28}(), {closure#3}(), PageTokens (+5 more)

### Community 2 - "Community 2"
Cohesion: 0.06
Nodes (4): ConfigurationImport, {closure#11}(), {closure#13}(), {closure#15}()

### Community 5 - "Community 5"
Cohesion: 0.12
Nodes (5): ConfigurationTranscoder, {closure#2}(), {closure#4}(), {closure#5}(), ConnectionScreens

### Community 8 - "Community 8"
Cohesion: 0.29
Nodes (6): license, name, require, php, yahnis-elsts/plugin-update-checker, type

### Community 10 - "Community 10"
Cohesion: 0.71
Nodes (6): {closure#1}(), {closure#2}(), getmcp_extensions_guard_has_blocked(), getmcp_extensions_guard_marketing_missing(), getmcp_extensions_guard_ready(), getmcp_extensions_guard_row()

### Community 19 - "Community 19"
Cohesion: 0.83
Nodes (3): clientAuthCard(), mount(), syncNativeOAuthSettings()

## Knowledge Gaps
- **5 isolated node(s):** `license`, `name`, `php`, `yahnis-elsts/plugin-update-checker`, `type`
  These have ≤1 connection - possible missing edges. (Counts symbols only; 158 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **13 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `MarketingModule` connect `Community 0` to `Community 1`, `Community 2`, `Community 3`, `Community 5`, `Community 17`, `Community 18`?**
  _High betweenness centrality (0.154) - this node is a cross-community bridge._
- **Why does `Runtime` connect `Community 3` to `Community 17`, `Community 5`?**
  _High betweenness centrality (0.122) - this node is a cross-community bridge._
- **Why does `AuthenticationSettings` connect `Community 0` to `Community 1`, `Community 5`?**
  _High betweenness centrality (0.114) - this node is a cross-community bridge._
- **Are the 18 inferred relationships involving `AuthenticationSettings` (e.g. with `.prepare()` and `.read()`) actually correct?**
  _`AuthenticationSettings` has 18 INFERRED edges - AST-inferred connections that need verification._
- **Are the 12 inferred relationships involving `PageTokens` (e.g. with `{closure#1}()` and `{closure#2}()`) actually correct?**
  _`PageTokens` has 12 INFERRED edges - AST-inferred connections that need verification._
- **Are the 14 inferred relationships involving `ProviderTokens` (e.g. with `{closure#28}()` and `.discover()`) actually correct?**
  _`ProviderTokens` has 14 INFERRED edges - AST-inferred connections that need verification._
- **Are the 9 inferred relationships involving `MarketingModule` (e.g. with `{closure#4}()` and `.call()`) actually correct?**
  _`MarketingModule` has 9 INFERRED edges - AST-inferred connections that need verification._