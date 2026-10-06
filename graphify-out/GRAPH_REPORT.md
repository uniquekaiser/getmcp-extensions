# Graph Report - getmcp-extensions  (2026-10-06)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 227 nodes · 299 edges · 18 communities (6 shown, 12 thin omitted)
- Extraction: 78% EXTRACTED · 22% INFERRED · 0% AMBIGUOUS · INFERRED: 66 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `b70466e1`
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
- Community 12

## God Nodes (most connected - your core abstractions)
1. `PageTokens` - 26 edges
2. `AuthenticationSettings` - 24 edges
3. `ProviderTokens` - 22 edges
4. `Runtime` - 20 edges
5. `MarketingModule` - 17 edges
6. `ProviderConnections` - 17 edges
7. `Updater` - 10 edges
8. `ProviderDiscovery` - 9 edges
9. `ConfigurationImport` - 7 edges
10. `ProviderExecution` - 6 edges

## Surprising Connections (you probably didn't know these)
- `{closure#26}()` --calls--> `AuthenticationSettings`  [INFERRED]
  includes/class-marketing-module.php → includes/class-authentication-settings.php
- `{closure#33}()` --calls--> `AuthenticationSettings`  [INFERRED]
  includes/class-marketing-module.php → includes/class-authentication-settings.php
- `{closure#1}()` --calls--> `PageTokens`  [INFERRED]
  includes/class-marketing-module.php → includes/class-page-tokens.php
- `{closure#2}()` --calls--> `PageTokens`  [INFERRED]
  includes/class-marketing-module.php → includes/class-page-tokens.php
- `{closure#26}()` --calls--> `PageTokens`  [INFERRED]
  includes/class-marketing-module.php → includes/class-page-tokens.php

## Import Cycles
- None detected.

## Communities (18 total, 12 thin omitted)

### Community 0 - "Community 0"
Cohesion: 0.07
Nodes (6): AuthenticationSettings, SettingsConflict, ConnectionHeaders, {closure#29}(), {closure#33}(), MarketingModule

### Community 1 - "Community 1"
Cohesion: 0.08
Nodes (8): {closure#15}(), {closure#21}(), {closure#23}(), {closure#26}(), ProviderConnections, ProviderDiscovery, ProviderReadiness, ProviderTokens

### Community 2 - "Community 2"
Cohesion: 0.07
Nodes (3): ConfigurationImport, {closure#11}(), {closure#13}()

### Community 5 - "Community 5"
Cohesion: 0.11
Nodes (5): {closure#1}(), {closure#2}(), {closure#3}(), PageTokens, ProviderExecution

### Community 8 - "Community 8"
Cohesion: 0.29
Nodes (6): license, name, require, php, yahnis-elsts/plugin-update-checker, type

### Community 10 - "Community 10"
Cohesion: 0.71
Nodes (6): {closure#1}(), {closure#2}(), getmcp_extensions_guard_has_blocked(), getmcp_extensions_guard_marketing_missing(), getmcp_extensions_guard_ready(), getmcp_extensions_guard_row()

## Knowledge Gaps
- **5 isolated node(s):** `name`, `type`, `license`, `php`, `yahnis-elsts/plugin-update-checker`
  These have ≤1 connection - possible missing edges. (Counts symbols only; 129 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **12 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `MarketingModule` connect `Community 0` to `Community 2`, `Community 3`, `Community 12`, `Community 5`?**
  _High betweenness centrality (0.226) - this node is a cross-community bridge._
- **Why does `Runtime` connect `Community 3` to `Community 0`, `Community 9`?**
  _High betweenness centrality (0.165) - this node is a cross-community bridge._
- **Why does `AuthenticationSettings` connect `Community 0` to `Community 1`, `Community 5`?**
  _High betweenness centrality (0.114) - this node is a cross-community bridge._
- **Are the 12 inferred relationships involving `PageTokens` (e.g. with `{closure#1}()` and `{closure#2}()`) actually correct?**
  _`PageTokens` has 12 INFERRED edges - AST-inferred connections that need verification._
- **Are the 16 inferred relationships involving `AuthenticationSettings` (e.g. with `.prepare()` and `.read()`) actually correct?**
  _`AuthenticationSettings` has 16 INFERRED edges - AST-inferred connections that need verification._
- **Are the 13 inferred relationships involving `ProviderTokens` (e.g. with `{closure#26}()` and `.discover()`) actually correct?**
  _`ProviderTokens` has 13 INFERRED edges - AST-inferred connections that need verification._
- **Are the 2 inferred relationships involving `Runtime` (e.g. with `.activate()` and `.enabled()`) actually correct?**
  _`Runtime` has 2 INFERRED edges - AST-inferred connections that need verification._