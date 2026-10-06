# Security

Report vulnerabilities privately through [Synergetic Dev](https://synergetic.dev/). Do not post credentials, tokens, customer records or working exploit URLs in public issues. Include the affected version and a minimal synthetic reproduction.

Public releases are the supported distribution channel. Compatibility is restricted to reviewed builds. Multisite and unreviewed vendor versions are not supported. See docs/installation.md for safe rollback and persistent endpoint-guard requirements.

Upstream tokens and custom headers are encrypted with the installation's existing encryption keys. They must never enter Git, public documentation, MCP schemas or release archives. Public update requests use no connection credentials.
