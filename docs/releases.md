# Public token-free updates and releases

The public repository and release packages are hosted at https://github.com/uniquekaiser/getmcp-extensions. Client sites contact GitHub directly without authentication. No licensing relay, GitHub token or connection credential is involved in delivery.

The package bundles Plugin Update Checker 5.7. Update discovery accepts only the latest non-prerelease release and its exact `getmcp-extensions-X.Y.Z.zip` asset. The updater rejects source archives, tags without releases, wrong versions/filenames and foreign package hosts. Fresh and cached metadata share validation and icon fallbacks; existing artwork is preserved. Git checkouts and source archives lack the package-only DISTRIBUTION marker and do not self-update.

Existing 1.0.0/1.1.0 distributions have no public updater. One manual installation of the current release bootstraps dashboard updates. The public 1.1.1 package can upgrade normally to 1.1.2. Do not rewrite historical packages to disguise that requirement.

## Maintainer release procedure

1. Review the full change range, user-visible impact and AGENTS.md local testing policy.
2. Align main header, version constant, readme stable tag and categorized CHANGELOG.md. Record actual WordPress/PHP verification.
3. Run `python tools/sync-readme.py`, `python tools/verify.py`, relevant disposable WordPress tests, `python tools/graph.py`, and `python tools/build.py`. The builder is deterministic and rejects missing updater/license files.
4. Use the syn-release and wp-github-updater skill audits when available. Validate exact notes generated from CHANGELOG.md. Inspect all package entries and the public Git diff for secrets.
5. Run all feasible local checks before pushing. The tag-triggered `.github/workflows/release.yml` validates the version and categorized changelog, builds the deterministic install ZIP and credential-free developer handoff from tracked source, verifies their checksums and archive structure, and creates one stable GitHub Release with both ZIPs, `SHA256SUMS`, and `files.json`. It does not rerun Docker integration tests because those require reviewed GetMCP snapshots and owned local fixtures.
6. Commit the reviewed files, create an annotated `vX.Y.Z` tag, and push the exact commit/tag once. The `vX.Y.Z` tag push is the sole release trigger; do not also dispatch the workflow manually.
7. Wait for the exact-tag workflow to succeed. Re-read the published body, download the public package anonymously, compare SHA-256/root/main header, and test WordPress's metadata and normal upgrade path in an owned fixture.
8. Report passed, failed, skipped and unavailable coverage separately. Publication does not authorize deployment to customer sites.

The workflow requires only the repository's built-in `GITHUB_TOKEN` with `contents: write`; it uses no stored release secrets. Graphify, tests and tools are public source material but excluded from the installed ZIP.
