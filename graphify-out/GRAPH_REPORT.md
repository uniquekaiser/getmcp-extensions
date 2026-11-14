# Graph Report - getmcp-extensions  (2026-11-13)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 481 nodes · 695 edges · 40 communities (12 shown, 28 thin omitted)
- Extraction: 76% EXTRACTED · 24% INFERRED · 0% AMBIGUOUS · INFERRED: 168 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `81616a05`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Community 0
- Community 1
- Community 2
- Community 4
- Community 5
- Community 6
- Community 7
- Community 8
- Community 9
- Community 10
- Community 12
- Community 13
- Community 14
- Community 17
- Community 18
- Community 19
- Community 20
- Community 21
- Community 22
- Community 23
- Community 24
- Community 26
- Community 27
- Community 28
- Community 33
- Community 36
- Community 37
- Community 38

## God Nodes (most connected - your core abstractions)
1. `Runtime` - 49 edges
2. `GoogleHealth` - 31 edges
3. `AuthenticationSettings` - 30 edges
4. `ProviderTokens` - 29 edges
5. `PageTokens` - 26 edges
6. `ProviderConnections` - 24 edges
7. `MarketingModule` - 22 edges
8. `Upgrade180` - 22 edges
9. `Upgrade180Auto` - 22 edges
10. `LegacyBridge` - 20 edges

## Surprising Connections (you probably didn't know these)
- `{closure#2}()` --calls--> `AuthenticationSettings`  [INFERRED]
  includes/class-public-gateway180.php → includes/class-authentication-settings.php
- `{closure#4}()` --calls--> `MarketingModule`  [INFERRED]
  includes/class-connection-screens.php → includes/class-marketing-module.php
- `{closure#4}()` --calls--> `Upgrade180`  [INFERRED]
  includes/class-runtime.php → includes/class-upgrade180.php
- `{closure#6}()` --calls--> `Upgrade180`  [INFERRED]
  includes/class-runtime.php → includes/class-upgrade180.php
- `{closure#2}()` --calls--> `Runtime`  [INFERRED]
  includes/class-remote-catalogue.php → includes/class-runtime.php

## Import Cycles
- None detected.

## Communities (40 total, 28 thin omitted)

### Community 0 - "Community 0"
Cohesion: 0.14
Nodes (3): AuthenticationSettings, ConnectionHeaders, {closure#36}()

### Community 2 - "Community 2"
Cohesion: 0.07
Nodes (4): ConfigurationImport, {closure#11}(), {closure#13}(), {closure#15}()

### Community 4 - "Community 4"
Cohesion: 0.07
Nodes (3): {closure#1}(), ProtocolEnvelope, {closure#2}()

### Community 6 - "Community 6"
Cohesion: 0.06
Nodes (6): remote_catalogue_route(), replace_once(), build(), literal_entries(), main(), public_url()

### Community 8 - "Community 8"
Cohesion: 0.29
Nodes (6): license, name, require, php, yahnis-elsts/plugin-update-checker, type

### Community 12 - "Community 12"
Cohesion: 0.62
Nodes (6): clientAuthCard(), expandNativeAuthMenu(), isWordPressMode(), mount(), scheduleNativeMenuExpansion(), syncNativeOAuthSettings()

### Community 17 - "Community 17"
Cohesion: 0.11
Nodes (3): ConnectionScreens, MarketingTemplates, RemoteCatalogue

### Community 18 - "Community 18"
Cohesion: 0.14
Nodes (5): {closure#2}(), {closure#5}(), {closure#6}(), {closure#11}(), Standalone180

### Community 21 - "Community 21"
Cohesion: 0.17
Nodes (4): ConfigurationTranscoder, {closure#2}(), {closure#4}(), {closure#5}()

### Community 23 - "Community 23"
Cohesion: 0.11
Nodes (3): GoogleHealth, {closure#18}(), {closure#20}()

### Community 24 - "Community 24"
Cohesion: 0.71
Nodes (6): {closure#1}(), {closure#2}(), getmcp_extensions_guard_has_blocked(), getmcp_extensions_guard_marketing_missing(), getmcp_extensions_guard_ready(), getmcp_extensions_guard_row()

### Community 26 - "Community 26"
Cohesion: 0.05
Nodes (14): {closure#1}(), {closure#17}(), {closure#2}(), {closure#23}(), {closure#25}(), {closure#28}(), {closure#3}(), PageTokens (+6 more)

## Knowledge Gaps
- **5 isolated node(s):** `license`, `name`, `php`, `yahnis-elsts/plugin-update-checker`, `type`
  These have ≤1 connection - possible missing edges. (Counts symbols only; 253 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **28 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Runtime` connect `Community 14` to `Community 1`, `Community 33`, `Community 36`, `Community 5`, `Community 9`, `Community 10`, `Community 13`, `Community 17`, `Community 18`, `Community 19`, `Community 21`, `Community 23`, `Community 26`, `Community 27`?**
  _High betweenness centrality (0.205) - this node is a cross-community bridge._
- **Why does `MarketingModule` connect `Community 13` to `Community 2`, `Community 4`, `Community 37`, `Community 9`, `Community 17`, `Community 21`, `Community 22`, `Community 26`?**
  _High betweenness centrality (0.121) - this node is a cross-community bridge._
- **Why does `GoogleHealth` connect `Community 23` to `Community 0`, `Community 4`, `Community 5`, `Community 9`, `Community 13`, `Community 22`, `Community 26`?**
  _High betweenness centrality (0.077) - this node is a cross-community bridge._
- **Are the 26 inferred relationships involving `Runtime` (e.g. with `{closure#2}()` and `.members()`) actually correct?**
  _`Runtime` has 26 INFERRED edges - AST-inferred connections that need verification._
- **Are the 12 inferred relationships involving `GoogleHealth` (e.g. with `.prepare()` and `{closure#17}()`) actually correct?**
  _`GoogleHealth` has 12 INFERRED edges - AST-inferred connections that need verification._
- **Are the 22 inferred relationships involving `AuthenticationSettings` (e.g. with `.prepare()` and `.read()`) actually correct?**
  _`AuthenticationSettings` has 22 INFERRED edges - AST-inferred connections that need verification._
- **Are the 19 inferred relationships involving `ProviderTokens` (e.g. with `.is_server()` and `{closure#28}()`) actually correct?**
  _`ProviderTokens` has 19 INFERRED edges - AST-inferred connections that need verification._