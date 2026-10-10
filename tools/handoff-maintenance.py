"""Credential-free maintenance patch, reviewed files and local verification; never publishes."""
from pathlib import Path
import difflib, hashlib, json, re, subprocess, zipfile

ROOT = Path(__file__).resolve().parents[1]
def git(*args):
    return subprocess.check_output(['git', *args], cwd=ROOT)
def sha(data):
    return hashlib.sha256(data).hexdigest()

version = re.search(r'\* Version:\s*(\S+)', (ROOT/'getmcp-extensions.php').read_text()).group(1)
baseline = git('rev-parse', 'HEAD').decode().strip()
tracked = set(git('ls-tree', '-r', '--name-only', baseline).decode().splitlines())
names = sorted(name for name in set(git('diff', '--name-only', baseline).decode().splitlines() + git('ls-files', '--others', '--exclude-standard').decode().splitlines()) if not name.startswith('.playwright-cli/'))
files = {}; manifest = []; patch = git('diff', '--binary', baseline)
for name in names:
    path = ROOT/name; new = path.read_bytes() if path.is_file() else None
    old = git('show', baseline+':'+name) if name in tracked else None
    if new is not None:
        files['changed-files/'+name] = new
    manifest.append({'path': name, 'baseline_sha256': sha(old) if old is not None else None,
                     'new_sha256': sha(new) if new is not None else None,
                     'canonical_new_sha256': sha(new.replace(b'\r\n', b'\n')) if new is not None else None})
    if old is None and new is not None:
        diff = ''.join(difflib.unified_diff([], new.decode().replace('\r\n','\n').splitlines(keepends=True), fromfile='/dev/null', tofile='b/'+name))
        patch += ('diff --git a/'+name+' b/'+name+'\nnew file mode 100644\n'+diff).encode()
files['feature.patch'] = patch
files['changed-files.json'] = (json.dumps({'baseline_commit': baseline, 'version': version, 'files': manifest}, indent=2)+'\n').encode()
package = ROOT/'dist'/('getmcp-extensions-'+version+'.zip')
files['install/'+package.name] = package.read_bytes()
files['documentation/verification-'+version+'.md'] = (ROOT/'docs'/('verification-'+version+'.md')).read_bytes()
local = json.loads((ROOT/'evidence/local-checks.json').read_bytes())
for runtime in local['php']:
    runtime['runtime'] = json.loads(runtime['auth_tests'][0]['output'])['php']
files['verification/local-checks.json'] = (json.dumps(local, indent=2)+'\n').encode()
for folder in ['connections-'+version+'-getmcp-1.6.0', 'connections-'+version+'-getmcp-1.7.0',
               'minimum/connections-'+version+'-getmcp-1.6.0', 'minimum/connections-'+version+'-getmcp-1.7.0']:
    source = ROOT/'evidence'/folder
    for name in ['summary.json', 'connection-status.json', 'wordpress.json', 'execution.json', 'discovery.json', 'marketing-lifecycle.json', 'connections.json']:
        files['verification/'+folder+'/'+name] = (source/name).read_bytes()
files['README-HANDOFF.md'] = (
    '# GetMCP Extensions '+version+' maintenance handoff\n\n'
    'Developed by Synergetic Dev — https://synergetic.dev/\n\n'
    'Baseline commit: '+baseline+'. Apply feature.patch to this commit. The changed-file manifest contains original, resulting and normalized SHA-256 hashes. '
    'The install ZIP includes both the legacy 1.6 app and isolated 1.7 app assets. Install only after site approval and backup. No GitHub release is published by this tool. '
    'The package contains no customer configuration or credentials. Synthetic test credentials are explicitly fixture-only. '
    'See documentation for behavior, verification limits, migration and rollback. No schema or credential migration is needed; restore the prior add-on package to roll back, retaining the database, salts and MU guard.\n'
).encode()
for name, data in files.items():
    if name.endswith(('.php','.js','.json','.md','.txt','.py','.patch','.html')):
        text = data.decode('utf-8')
        # The UI includes a literal PEM-shaped textarea placeholder with no
        # key material; remove only that exact placeholder before the secret scan.
        pem_placeholder = ('-----' + 'BEGIN OPENSSH PRIVATE KEY' + '-----' + r'\\n.{0,10}?\\n' +
                           '-----' + 'END OPENSSH PRIVATE KEY' + '-----')
        text = re.sub(pem_placeholder, '[fixture-only key placeholder]', text)
        secret_markers = [r'gh[pousr]_[A-Za-z0-9]{25,}', r'github_pat_[A-Za-z0-9_]{25,}',
                          '-----' + r'BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY' + '-----',
                          r'ya29\.', r'AIza[A-Za-z0-9_-]{20,}', r'wpdev\.synergetic\.dev', r'dashja@gmail\.com']
        assert not any(re.search(marker, text) for marker in secret_markers), name
        # Minified vendor bundles can contain drive-prefixed strings; scan
        # human-authored text and source files for local paths, while still
        # checking every bundle for credential and site-specific markers above.
        if not name.endswith(('.js', '.patch')):
            assert not re.search('C' + r':[/\\]', text), name
files['SHA256SUMS'] = ''.join(sha(data)+'  '+name+'\n' for name,data in sorted(files.items())).encode()
out = ROOT/'dist'/('getmcp-extensions-'+version+'-developer-handoff.zip')
with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as archive:
    for name,data in sorted(files.items()):
        entry=zipfile.ZipInfo(name,(2026,10,6,0,0,0)); entry.compress_type=zipfile.ZIP_DEFLATED
        archive.writestr(entry,data)
with zipfile.ZipFile(out) as archive:
    assert archive.testzip() is None
print(json.dumps({'handoff':str(out),'sha256':sha(out.read_bytes()),'changed_files':len(manifest),'install_sha256':sha(package.read_bytes())}))
