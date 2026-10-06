# Public token-free updates and releases

The public repository and release packages are hosted at https://github.com/uniquekaiser/getmcp-extensions. Client sites contact GitHub directly without authentication. No licensing relay, GitHub token or connection credential is involved in delivery.

The package bundles Plugin Update Checker 5.7. Update discovery accepts only the latest non-prerelease release and its exact `getmcp-extensions-X.Y.Z.zip` asset. The updater rejects source archives, tags without releases, wrong versions/filenames and foreign package hosts. Fresh and cached metadata share validation and icon fallbacks; existing artwork is preserved. Git checkouts and source archives lack the package-only DISTRIBUTION marker and do not self-update.

Existing 1.0.0/1.1.0 distributions have no public updater. One manual installation of 1.1.1 bootstraps dashboard updates. Do not rewrite historical packages to disguise that requirement.

## Maintainer release procedure

1. Review the full change range, user-visible impact and AGENTS.md local testing policy.
2. Align main header, version constant, readme stable tag and categorized CHANGELOG.md. Record actual WordPress/PHP verification.
3. Run `python tools/verify.py`, relevant disposable WordPress tests, `python tools/graph.py`, and `python tools/build.py`. The builder is deterministic and rejects missing updater/license files.
4. Use the syn-release and wp-github-updater skill audits when available. Validate exact notes generated from CHANGELOG.md. Inspect all package entries and the public Git diff for secrets.
5. Inspect workflow triggers before pushing. This repository deliberately has no hosted workflows; do not dispatch CI or add/change workflows implicitly.
6. Commit the reviewed files, create an annotated `vX.Y.Z` tag, push the exact commit/tag, then create one release with the audited ZIP and SHA256SUMS. Use `--notes-file` with generated categorized notes.
7. Re-read the published body, download the public package anonymously, compare SHA-256/root/main header and test WordPress's metadata and normal upgrade path in an owned fixture.
8. Report passed, failed, skipped and unavailable coverage separately. Publication does not authorize deployment to customer sites.

No workflow, billing or branch protection changes are required for this local-first release path. Graphify, tests and tools are public source material but excluded from the installed ZIP.
