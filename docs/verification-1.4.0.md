# GetMCP Extensions 1.4.0 verification

This release combines the Google Health work recorded in [the 1.4.0-beta.1 verification](verification-1.4.0-beta.1.md) with the migration and compatibility work recorded in [the GetMCP 1.8.0 upgrade assessment](verification-1.8.0-upgrade-assessment.md). Those reports record the test environments, fixture limitations and outstanding acceptance separately.

The 1.8.0 rehearsals used the reviewed core package and synthetic records in the shared local WordPress fixture. They exercised standard servers, personal grants, remote connections and gateways, including successful and refused access, with rollback and already-upgraded cases. The minimum WordPress rehearsal used a database snapshot originating from a newer WordPress installation; it is not evidence of a clean minimum-version installation. Live provider consent and real provider acceptance remain unverified.

Existing sites upgraded before historical gateway access was captured retain their current access rules and receive an administrator review notice where history cannot be established. The release does not infer or create unknown grants. Unsupported configurations are deferred for review. Preserve database backups and WordPress encryption salts during upgrades.

GitHub publication and dashboard update availability require independent verification against the published release; this document alone does not establish either.
