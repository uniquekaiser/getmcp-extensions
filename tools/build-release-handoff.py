"""Build a credential-free developer handoff from committed source only."""
from pathlib import Path
import argparse
import difflib
import hashlib
import json
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]


def git(*args: str) -> bytes:
    return subprocess.check_output(['git', *args], cwd=ROOT)


def sha(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def blob(revision: str, path: str) -> bytes | None:
    result = subprocess.run(['git', 'show', f'{revision}:{path}'], cwd=ROOT,
                            stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    return result.stdout if result.returncode == 0 else None


parser = argparse.ArgumentParser()
parser.add_argument('--version', required=True)
parser.add_argument('--base-tag', required=True,
                    help='Previous public release tag used as the patch baseline.')
parser.add_argument('--output-dir', default=str(ROOT / 'dist'))
args = parser.parse_args()
assert re.fullmatch(r'\d+\.\d+\.\d+', args.version), 'Invalid release version.'
base = args.base_tag
head = git('rev-parse', 'HEAD').decode().strip()
tag = f'v{args.version}'
tag_commit = git('rev-parse', f'{tag}^{{commit}}').decode().strip()
assert head == tag_commit, 'Build handoff from the exact tagged commit.'
git('rev-parse', f'{base}^{{commit}}')

package = ROOT / 'dist' / f'getmcp-extensions-{args.version}.zip'
verification = ROOT / 'docs' / f'verification-{args.version}.md'
assert package.is_file(), 'Build the install package first.'
assert verification.is_file(), 'A versioned verification report is required.'

changed = git('diff', '--no-renames', '--name-only', '--diff-filter=ACDMRTUXB', base, tag).decode().splitlines()
files: dict[str, bytes] = {}
manifest = []
for name in sorted(set(changed)):
    prior = blob(base, name)
    current = blob(tag, name)
    manifest.append({
        'path': name,
        'baseline_sha256': sha(prior) if prior is not None else None,
        'new_sha256': sha(current) if current is not None else None,
        'canonical_new_sha256': sha(current.replace(b'\r\n', b'\n')) if current is not None else None,
    })
    if current is not None:
        files[f'changed-files/{name}'] = current

patch = git('diff', '--no-renames', '--binary', base, tag)
files['feature.patch'] = patch
files['changed-files.json'] = (json.dumps({
    'base_tag': base, 'base_commit': git('rev-parse', f'{base}^{{commit}}').decode().strip(),
    'release_tag': tag, 'release_commit': head, 'version': args.version,
    'files': manifest,
}, indent=2, ensure_ascii=False) + '\n').encode()
files[f'install/{package.name}'] = package.read_bytes()
files[f'documentation/{verification.name}'] = verification.read_bytes()
files['documentation/CHANGELOG.md'] = blob(tag, 'CHANGELOG.md') or b''
files['README-HANDOFF.md'] = (
    f'# GetMCP Extensions {args.version} developer handoff\n\n'
    'Developed by Synergetic Dev — https://synergetic.dev/\n\n'
    f'This package contains the tracked source changes from `{base}` through `{tag}`, '
    'a binary-capable patch, baseline/result SHA-256 manifest, install ZIP, changelog, '
    'and verification report. The handoff is built from the tagged Git tree; it does not '
    'read local test evidence, WordPress databases, credentials, or ignored files.\n\n'
    f'Apply `feature.patch` only to the recorded base commit. Verify `changed-files.json` '
    'before integration. Install the add-on ZIP separately through WordPress after review. '
    'For rollback, reinstall the previous add-on package while preserving site data, salts, '
    'credentials, allowlists, and gateway membership.\n'
).encode()

secret_patterns = [
    rb'gh[pousr]_[A-Za-z0-9]{25,}', rb'github_pat_[A-Za-z0-9_]{25,}',
    b'-----' + rb'BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY' + b'-----', rb'ya29\.',
    rb'AIza[A-Za-z0-9_-]{20,}', rb'wpdev\.synergetic\.dev', rb'dashja@gmail\.com',
]
for name, data in files.items():
    # GetMCP's admin UI includes a literal, ellipsis-only key placeholder.
    # Exempt only that exact placeholder; real PEM content still fails closed.
    scanned = re.sub(
        b'-----' + rb'BEGIN OPENSSH PRIVATE KEY-----\\n.{0,10}?\\n-----END OPENSSH PRIVATE KEY' + b'-----',
        b'[fixture-only key placeholder]', data)
    if any(re.search(pattern, scanned) for pattern in secret_patterns):
        raise SystemExit(f'Credential or customer-context marker found in handoff input: {name}')

out = Path(args.output_dir)
out.mkdir(parents=True, exist_ok=True)
handoff = out / f'getmcp-extensions-{args.version}-developer-handoff.zip'
with zipfile.ZipFile(handoff, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
    for name, data in sorted(files.items()):
        entry = zipfile.ZipInfo(name, (2026, 10, 6, 0, 0, 0))
        entry.compress_type = zipfile.ZIP_DEFLATED
        entry.external_attr = 0o100644 << 16
        archive.writestr(entry, data)
with zipfile.ZipFile(handoff) as archive:
    assert archive.testzip() is None

assets = [package, handoff]
(out / 'SHA256SUMS').write_text(
    ''.join(f'{sha(path.read_bytes())}  {path.name}\n' for path in assets),
    encoding='utf-8', newline='\n')
print(json.dumps({
    'version': args.version, 'base_tag': base, 'release_tag': tag,
    'changed_files': len(manifest), 'handoff': str(handoff),
    'handoff_sha256': sha(handoff.read_bytes()),
    'install_sha256': sha(package.read_bytes()),
}, indent=2))
