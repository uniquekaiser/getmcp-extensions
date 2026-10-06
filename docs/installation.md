# Installation, compatibility and rollback

Install the versioned release ZIP and activate GetMCP first. The add-on does not change GetMCP files. Its runtime checks reviewed build profiles and fails closed for unknown builds or conflicting early-loaded classes. The reviewed profiles include the original 1.6.0 distribution and prior prerequisite/integrated snapshots; see compatibility diagnostics in the Extensions screen.

Upgrading an existing standalone add-on replaces only `getmcp-extensions/`. Back up the database/files and preserve salts. Do not reinstall GetMCP or copy compatibility adapters into its directory. Existing credentials, gateways, slugs and user lists remain in place. Browser personal grants are not automatically created from broker grants.

The guard at `wp-content/mu-plugins/getmcp-extensions-guard.php` is installed atomically and refuses to replace unrelated code. Its directory must be writable during activation/owned guard updates. Marked endpoints remain blocked if the add-on or a required module is missing. Data is preserved.

For rollback, pause enhanced Page-token definitions before returning to a version that lacks their runtime, retain the current owned guard, then restore the compatible add-on ZIP. Deactivation is reversible but intentionally blocks affected endpoints. Permanent retirement requires migrating/deleting protected definitions and independently verifying access before explicitly removing the owned guard. No automatic uninstall purge is included.

PHP 8.2 is the supported minimum. Single-site WordPress is required. Actual tested runtimes and remaining acceptance limits appear in TESTING.md; a requirement declaration is not proof of every intermediate hosting combination.
