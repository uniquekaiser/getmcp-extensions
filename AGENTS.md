<!-- codex-local-testing-policy:v1 -->
## Local testing and GitHub Actions minutes

David's project policy: run tests locally whenever feasible to keep GitHub Actions minutes low and available mainly for deliberate release validation and publication.

- Before using hosted CI, discover the repository's required checks and run all feasible relevant lint, type checks, unit tests, integration/runtime tests, security checks, builds, and package/contract checks locally. Use the real repository commands and local services or supported containers where available.
- Preserve full required compatibility and security coverage, including supported version/platform matrices and edition/package boundaries. A local result proves only the environments actually tested. Report missing runtimes, services, credentials, platforms, or hosted-only checks as unavailable; distinguish passed, failed, skipped, and unavailable checks with commands and reasons. Never call an unperformed check passing.
- Fix and debug locally, rerunning affected local checks after changes. Do not use GitHub Actions for iterative debugging or repeated speculative reruns. Reuse still-valid evidence for the same commit/artifact and environment; rerun when changes invalidate it.
- Before any push or hosted dispatch, inspect workflow triggers (branch/tag push, pull request, release, workflow_run, and manual dispatch) and expected runs. Avoid unnecessary duplicate workflows or repeated matrix runs for the same evidence, while retaining every required compatibility/security job and protection. Do not trigger the same release checks through both automatic and manual paths.
- Reserve hosted CI mainly for deliberate release validation/publication after local preflight. If a non-release task needs an unavoidable hosted check, explain the local limitation, required coverage, expected trigger/runs, and request David's direction before triggering it. Honor any explicit authorization already given for that specific check.
- Never bypass required checks, branch protections, security gates, or release gates to save minutes. Workflow, billing, security, and runner changes require their own authorization; this policy grants none.
- Report local verification and any remaining hosted/release verification separately. For releases, follow `syn-release` when applicable and verify the actual published artifacts and distribution path before claiming completion.
<!-- /codex-local-testing-policy:v1 -->

## Graphify
Check graphify-out/graph.json before tracing authored-code relationships. Use code-only tools/graph.py after changes. Dependencies and generated adapters are excluded; inspect them directly when needed. Do not imply semantic documentation extraction occurred. Keep machine paths, fixture databases and credentials out of Git. No background hook or hosted workflow is enabled.
