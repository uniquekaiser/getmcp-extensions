"""Copy and verify the 1.8 runtime profile for the shared local fixture."""
from __future__ import annotations

import hashlib
import json
import os
import shutil
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CORE = Path(tempfile.gettempdir()) / 'getmcp-180-review-20261031' / 'getmcp'
PROFILE = 'vendor-original-1.8.0'


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes().replace(b'\r\n', b'\n')).hexdigest()


reviewed = json.loads((ROOT / 'compatibility-1.8-migration.json').read_text(encoding='utf-8'))
if not CORE.is_dir() or not all((CORE / name).is_file() and digest(CORE / name) == sha for name, sha in reviewed.items()):
    raise SystemExit('The extracted official 1.8 core does not match the reviewed migration manifest.')

parent = Path(tempfile.mkdtemp(prefix='getmcp-180-real-profile-'))
addon = parent / 'getmcp-extensions'
addon.mkdir()
for name in ('admin', 'build', 'compat', 'includes', 'assets', 'vendor', 'tests'):
    shutil.copytree(ROOT / name, addon / name, ignore=shutil.ignore_patterns('__pycache__', '.playwright-cli'))
for name in ('getmcp-extensions.php', 'compatibility.json', 'compatibility-1.8-migration.json', 'readme.txt', 'CHANGELOG.md', 'README.md', 'LICENSE', 'LICENSE-NOTICE.md'):
    shutil.copy2(ROOT / name, addon / name)

manifest_path = addon / 'compatibility.json'
manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
old = manifest['profiles']['vendor-original-1.7.0']
paths = {name for name in old if (CORE / name).is_file()}
paths.update(p.relative_to(CORE).as_posix() for folder in ('includes', 'admin') for p in (CORE / folder).rglob('*.php'))
paths.update(p.relative_to(CORE).as_posix() for p in (CORE / 'build').glob('*.js'))
paths.add('getmcp.php')
expected_profile = {name: digest(CORE / name) for name in sorted(paths)}
if manifest['profiles'].get(PROFILE) != expected_profile:
    raise SystemExit('The repository 1.8 profile does not match the reviewed core.')
classes = {
    'GetMCP\\Api\\ServersController': 'compat/versions/1.8.0/includes/api/class-servers-controller.php',
    'GetMCP\\Auth\\OAuthUserAccess': 'compat/includes/auth/class-oauth-user-access.php',
    'GetMCP\\Builtin\\BuiltinTools': 'compat/versions/1.8.0/includes/builtin/class-builtin-tools.php',
    'GetMCP\\Core\\ServerManager': 'compat/versions/1.8.0/includes/core/class-server-manager.php',
    'GetMCP\\Execution\\ToolExecutor': 'compat/versions/1.8.0/includes/execution/class-tool-executor.php',
    'GetMCP\\Protocol\\JsonRpcRouter': 'compat/versions/1.8.0/includes/protocol/class-json-rpc-router.php',
    'GetMCP\\Protocol\\ToolsCallHandler': 'compat/versions/1.8.0/includes/protocol/class-tools-call-handler.php',
}
for name, file in {
    'Delegation': 'class-delegation.php', 'FeatureHandler': 'class-feature-handler.php',
    'FeatureManager': 'class-feature-manager.php', 'FeatureModule': 'class-feature-module.php',
    'ManagementTool': 'class-management-tool.php', 'ProjectGateway': 'class-project-gateway.php',
}.items():
    classes['GetMCP\\Gateway\\' + name] = 'compat/includes/gateway/' + file
for name, file in {
    'HeaderSchema': 'class-header-schema.php', 'McpClient': 'class-mcp-client.php',
    'RemoteException': 'class-remote-exception.php', 'SafeHttp': 'class-safe-http.php',
    'UpstreamConnections': 'class-upstream-connections.php', 'UpstreamOAuth': 'class-upstream-oauth.php',
}.items():
    classes['GetMCP\\Remote\\' + name] = 'compat/includes/remote/' + file
if not all((addon / name).is_file() for name in classes.values()):
    raise SystemExit('A required compatibility adapter is missing.')
if manifest.get('exclusive_classes_by_profile', {}).get(PROFILE) != classes:
    raise SystemExit('The repository 1.8 adapter mappings do not match the reviewed fixture.')

source = addon.as_posix().replace("'", "''")
override = parent / 'real-profile.override.yaml'
override.write_text(
    "services:\n  wordpress:\n    environment:\n      GETMCP_180_QA_REAL_PROFILE: '1'\n      GETMCP_180_QA_UI: '0'\n"
    "    volumes:\n      - type: bind\n        source: '" + source + "'\n"
    "        target: /var/www/html/wp-content/plugins/getmcp-extensions\n        read_only: true\n",
    encoding='utf-8',
)
print(json.dumps({'addon': str(addon), 'override': str(override), 'core_files': len(paths), 'adapters': len(classes)}))
