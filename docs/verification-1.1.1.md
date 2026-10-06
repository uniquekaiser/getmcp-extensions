# GetMCP Extensions 1.1.1 verification

Prepared 2026-10-06. Public-source and fixture checks below refer to the 1.1.1 package. No customer-site deployment or live provider account change was performed.

## Passed locally before publication

- PHP 8.2.30, 8.3.29 and 8.4.16: 111 PHP files syntax-checked (runtime, bundled updater and fixture scripts); 62 OAuth and 33 user-access checks on each runtime. Nine JavaScript files passed syntax checks. The public inventory scanner found no known tokens, private keys or customer-site URLs.
- WordPress 7.1.2/PHP 8.3/MariaDB 11.4 and WordPress 6.2.2/PHP 8.2/MariaDB 11.4: install/activate the actual release ZIP and run 215 marketing, gateway, protocol, HTTP OAuth/access, native-list and module/guard integration assertions per environment. Provider writes use synthetic HTTP.
- Both environments: 16 additional updater checks exercise the actual Plugin Update Checker and WordPress update-transient/details paths, fresh/cache behavior, tokenless requests, publisher/icons, HTML changelog, downgrades and rejection of missing/wrong/foreign assets or source fallbacks.
- Browser on the installed ZIP: publisher and active version visible; Google Ads Authentication edited/saved/reloaded with a blank secret, offline/consent parameters retained, encrypted secret hash independently unchanged. The broader three-provider/import/Page flows are historical 1.1.0 evidence.
- Reproducible ZIP: one installed root, complete compatibility manifest and updater/license dependency, package-only update marker, no source-only tools/tests/Graphify outputs, exact file hashes and ZIP CRC. Public updater audit passed. syn-release scan had zero errors; its private-changelog heuristic warning is resolved by the actual public plugins_api HTML proof.
- Graphify: authored-code AST map, 222 nodes, 293 edges, 17 communities; no model/API calls and zero model token cost. AST inferred edges require source verification. Four closure-heavy admin factories yield file nodes without detailed symbols; generated compatibility/dependency code is intentionally excluded.

## Publication and update-path proof

The repository uses a local-first release path and no hosted workflows. The release ZIP, tag/commit, public anonymous download, icon responses and normal WordPress fixture upgrade are verified separately after publication; no pre-publication fixture check is described as a public delivery test. See the follow-up delivery results committed after publication.

Older 1.0.0/1.1.0 distributions do not contain this updater. They require one manual bootstrap installation. An older-version fixture using the new updater validates its upgrade behavior; it does not establish historical updater support.

## Failed

No unresolved failure in completed checks. Intermediate local work caught a missing compatibility manifest in the new builder, an updater syntax error, and automatic line-ending conversion during an earlier handoff patch check. These were corrected before publication. A disposable database initialization was retried after its service became ready.

## Skipped

Production deployment, live provider writes and hosted Actions. PHP 8.0/8.1 are outside the supported minimum. WordPress.org directory submission/propagation is not a delivery channel for this release.

## Unavailable / separate acceptance

Real Google/Meta consent and token-extension/Page messaging, optional official Meta tool-catalogue authentication, original React build/type checks, multisite (unsupported), intermediate WordPress/platform/hosting/database combinations and other licensed editions. WordPress 6.2.2 verifies the 6.2 release line; exact 6.2.0 was not exercised.

The canonical commands are in TESTING.md and docs/releases.md. Evidence contains only check names/counts and synthetic fixture metadata; credentials and installed database exports are excluded from the public repository.
