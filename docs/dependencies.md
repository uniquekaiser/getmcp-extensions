# Production dependencies

Plugin Update Checker **5.7** is pinned in composer.json and bundled as production runtime. Official source: https://github.com/YahnisElsts/plugin-update-checker/tree/v5.7. The downloaded official tag archive SHA-256 was `3d4b06e5ea5122b444f45638a6fbb12c9ecaa73450c5598251640c6f11eee73c`. Only runtime code/assets/translations and required license notices are bundled; examples and development configuration are excluded. Line endings are normalized for portable source/package hashes.

Read its MIT license under vendor/yahnis-elsts/plugin-update-checker/license.txt. Parsedown's retained MIT notice is in the bundled source. Read LICENSE-NOTICE.md for integration-adapter copyright and GPL requirements.

GetMCP remains a separately acquired WordPress dependency. The repository distributes reviewed integration adapters and a hash manifest, not the complete vendor plugin or an installation credential/license. Fixture tests require an operator-supplied reviewed build in an ignored directory. Existing licensing/quotas apply.

Graphify and Python/Node/PHP/Docker are development tools and are not installed on customer WordPress hosts by the add-on. Their generated architecture map, tests and release tools are excluded from the plugin ZIP.
