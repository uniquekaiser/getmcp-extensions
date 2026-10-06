"""Credential-free feature handoff from the public add-on baseline; no Git writes/publishing."""
from pathlib import Path
import difflib,hashlib,json,subprocess,zipfile
ROOT=Path(__file__).resolve().parents[1]
def git(*args):return subprocess.check_output(['git',*args],cwd=ROOT)
def sha(data):return hashlib.sha256(data).hexdigest()
base=git('rev-parse','HEAD').decode().strip()
baseline_names=set(git('ls-tree','-r','--name-only',base).decode().splitlines())
names=git('diff','--name-only',base).decode().splitlines()+git('ls-files','--others','--exclude-standard').decode().splitlines()
names=sorted(set(names))
files={};manifest=[];patch=git('diff','--binary',base)
for name in names:
 p=ROOT/name
 if p.is_file():new=p.read_bytes();files['changed-files/'+name]=new
 else:new=None
 old=git('show',base+':'+name) if name in baseline_names else None
 manifest.append({'path':name,'change':'added' if old is None else 'deleted' if new is None else 'modified','baseline_sha256':sha(old) if old is not None else None,'new_sha256':sha(new) if new is not None else None,'canonical_new_sha256':sha(new.replace(b'\r\n',b'\n')) if new is not None else None})
 if old is None and new is not None:
  text=new.decode('utf-8').replace('\r\n','\n')
  lines=difflib.unified_diff([],text.splitlines(keepends=True),fromfile='/dev/null',tofile='b/'+name)
  diff=''.join(line if line.endswith('\n') else line+'\n\\ No newline at end of file\n' for line in lines)
  patch+=('diff --git a/'+name+' b/'+name+'\nnew file mode 100644\n'+diff).encode('utf-8')
files['feature.patch']=patch
files['changed-files.json']=(json.dumps({'baseline_commit':base,'ui_baseline_tag':'v1.1.2','files':manifest},indent=2)+'\n').encode()
archive=ROOT/'dist/getmcp-extensions-1.2.0.zip'
files['install/'+archive.name]=archive.read_bytes()
files['install/SHA256SUMS']=(sha(archive.read_bytes())+'  '+archive.name+'\n').encode()
for name in ['docs/connections-1.2.0.md','docs/verification-1.2.0.md','docs/installation.md','TESTING.md','LICENSE-NOTICE.md','compatibility.json']:
 files['documentation/'+name]=(ROOT/name).read_bytes()
for folder in ['', 'minimum/']:
 for name in ['connections.json','connections-package.json','updater-contract.json']:
  files['verification/'+folder+name]=(ROOT/'evidence'/folder/name).read_bytes()
for folder in ['connections-1.2.0','minimum/connections-1.2.0']:
 files['verification/'+folder+'/summary.json']=(ROOT/'evidence'/folder/'summary.json').read_bytes()
 files['verification/'+folder+'/regressions.json']=(ROOT/'evidence'/folder/'regressions.json').read_bytes()
local=json.loads((ROOT/'evidence/local-checks.json').read_bytes())
for runtime in local['php']:
 runtime['runtime']=runtime['auth_tests'][0]['output'].split('"php": "')[1].split('"')[0]
files['verification/local-checks.json']=(json.dumps(local,indent=2)+'\n').encode()
files['verification/connections-browser-template.json']=(ROOT/'evidence/connections-browser-template.json').read_bytes()
files['verification/handoff-validation.json']=(ROOT/'evidence/handoff-validation.json').read_bytes()
files['README-HANDOFF.md']=('''# GetMCP Extensions 1.2.0 local handoff

Developed by Synergetic Dev — https://synergetic.dev/

Install only the ZIP under install/ after review and backup. No customer site was deployed.
The public 1.1.2 baseline is '''+base+'''. Clone the public repository,
checkout this commit and apply feature.patch using git apply. The manifest contains
SHA-256 of the baseline Git blobs and resulting raw files; Git canonicalizes text
line endings; canonical_new_sha256 verifies patched text independently of host EOL.
Deleted generated chunks are explicitly listed. Changed-files/ is
also available for direct review. tools/build-ui.py uses the v1.1.2 tag solely for
reviewed UI seams; readable behavior stays in admin/. Vendor files are unchanged.

The installable package includes the existing OAuth, user-picker, personal provider,
Page-token, Remote MCP and gateway modules. This feature patch is separate from
their already-reviewed baseline. compatibility.json records required vendor hashes.
No schema changes or credential migration occur. Roll back by restoring the genuine
1.1.2 add-on ZIP, preserving the database, salts and owned MU endpoint guard. New
templates/servers remain persisted; the new template catalogue and client UI vanish.

See documentation for APIs, permissions, template setup, converter limitations,
verification and migration/rollback. Evidence contains synthetic-fixture results,
never provider tokens or customer configuration. Hosted CI and publication skipped.
''').encode()
files['SHA256SUMS']=( ''.join(sha(data)+'  '+name+'\n' for name,data in sorted(files.items())) ).encode()
out=ROOT/'dist/getmcp-extensions-1.2.0-developer-handoff.zip'
with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as z:
 for name,data in sorted(files.items()):
  i=zipfile.ZipInfo(name,(2026,10,6,0,0,0));i.compress_type=zipfile.ZIP_DEFLATED;z.writestr(i,data)
with zipfile.ZipFile(out) as z:assert z.testzip() is None
print(json.dumps({'handoff':str(out),'sha256':sha(out.read_bytes()),'changed_files':len(manifest),'files':len(files)}))
