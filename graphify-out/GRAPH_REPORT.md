# Graph Report - getmcp-extensions  (2026-10-16)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 354 nodes · 511 edges · 28 communities (9 shown, 19 thin omitted)
- Extraction: 80% EXTRACTED · 20% INFERRED · 0% AMBIGUOUS · INFERRED: 101 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `d6337bd1`
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
- Community 19
- Community 21
- Community 22
- Community 23
- Community 24

## God Nodes (most connected - your core abstractions)
1. `GoogleHealth` - 29 edges
2. `Runtime` - 28 edges
3. `AuthenticationSettings` - 27 edges
4. `PageTokens` - 26 edges
5. `ProviderTokens` - 24 edges
6. `ProviderConnections` - 21 edges
7. `MarketingModule` - 20 edges
8. `ConfigurationImport` - 10 edges
9. `Updater` - 10 edges
10. `MarketingTemplates` - 9 edges

## Surprising Connections (you probably didn't know these)
- `{closure#4}()` --calls--> `MarketingModule`  [INFERRED]
  includes/class-connection-screens.php → includes/class-marketing-module.php
- `{closure#2}()` --calls--> `Runtime`  [INFERRED]
  includes/class-remote-catalogue.php → includes/class-runtime.php
- `{closure#2}()` --calls--> `AuthenticationSettings`  [INFERRED]
  includes/class-connection-screens.php → includes/class-authentication-settings.php
- `{closure#28}()` --calls--> `AuthenticationSettings`  [INFERRED]
  includes/class-marketing-module.php → includes/class-authentication-settings.php
- `{closure#35}()` --calls--> `AuthenticationSettings`  [INFERRED]
  includes/class-marketing-module.php → includes/class-authentication-settings.php

## Import Cycles
- None detected.

## Communities (28 total, 19 thin omitted)

### Community 0 - "Community 0"
Cohesion: 0.07
Nodes (6): AuthenticationSettings, SettingsConflict, ConnectionHeaders, {closure#31}(), {closure#35}(), MarketingModule

### Community 1 - "Community 1"
Cohesion: 0.06
Nodes (10): {closure#1}(), {closure#2}(), {closure#23}(), {closure#28}(), {closure#3}(), PageTokens, ProviderConnections, ProviderExecution (+2 more)

### Community 2 - "Community 2"
Cohesion: 0.07
Nodes (4): ConfigurationImport, {closure#11}(), {closure#13}(), {closure#15}()

### Community 5 - "Community 5"
Cohesion: 0.17
Nodes (4): ConfigurationTranscoder, {closure#2}(), {closure#4}(), {closure#5}()

### Community 8 - "Community 8"
Cohesion: 0.29
Nodes (6): license, name, require, php, yahnis-elsts/plugin-update-checker, type

### Community 12 - "Community 12"
Cohesion: 0.62
Nodes (6): clientAuthCard(), expandNativeAuthMenu(), isWordPressMode(), mount(), scheduleNativeMenuExpansion(), syncNativeOAuthSettings()

### Community 21 - "Community 21"
Cohesion: 0.31
Nodes (4): build(), literal_entries(), main(), public_url()

### Community 23 - "Community 23"
Cohesion: 0.07
Nodes (7): GoogleHealth, MarketingManagementTool, {closure#17}(), {closure#18}(), {closure#20}(), {closure#25}(), ProviderDiscovery

### Community 24 - "Community 24"
Cohesion: 0.71
Nodes (6): {closure#1}(), {closure#2}(), getmcp_extensions_guard_has_blocked(), getmcp_extensions_guard_marketing_missing(), getmcp_extensions_guard_ready(), getmcp_extensions_guard_row()

## Knowledge Gaps
- **5 isolated node(s):** `license`, `name`, `php`, `yahnis-elsts/plugin-update-checker`, `type`
  These have ≤1 connection - possible missing edges. (Counts symbols only; 189 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **19 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Runtime` connect `Community 10` to `Community 0`, `Community 1`, `Community 3`, `Community 5`, `Community 14`, `Community 17`, `Community 19`, `Community 20`, `Community 22`?**
  _High betweenness centrality (0.126) - this node is a cross-community bridge._
- **Why does `MarketingModule` connect `Community 0` to `Community 1`, `Community 2`, `Community 4`, `Community 5`, `Community 17`, `Community 23`?**
  _High betweenness centrality (0.108) - this node is a cross-community bridge._
- **Why does `GoogleHealth` connect `Community 23` to `Community 0`, `Community 1`, `Community 10`, `Community 4`?**
  _High betweenness centrality (0.087) - this node is a cross-community bridge._
- **Are the 10 inferred relationships involving `GoogleHealth` (e.g. with `.prepare()` and `{closure#17}()`) actually correct?**
  _`GoogleHealth` has 10 INFERRED edges - AST-inferred connections that need verification._
- **Are the 9 inferred relationships involving `Runtime` (e.g. with `{closure#2}()` and `.enabled()`) actually correct?**
  _`Runtime` has 9 INFERRED edges - AST-inferred connections that need verification._
- **Are the 19 inferred relationships involving `AuthenticationSettings` (e.g. with `.prepare()` and `.read()`) actually correct?**
  _`AuthenticationSettings` has 19 INFERRED edges - AST-inferred connections that need verification._
- **Are the 12 inferred relationships involving `PageTokens` (e.g. with `{closure#1}()` and `{closure#2}()`) actually correct?**
  _`PageTokens` has 12 INFERRED edges - AST-inferred connections that need verification._