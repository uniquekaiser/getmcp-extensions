"""Local candidate handoff of only this feature's reviewed paths; no Git writes or publication."""
from pathlib import Path
import difflib,hashlib,json,re,subprocess,tempfile,zipfile
ROOT=Path(__file__).resolve().parents[1]
def git(*args):return subprocess.check_output(['git',*args],cwd=ROOT)
def sha(data):return hashlib.sha256(data).hexdigest()
names=['CHANGELOG.md','README.md','readme.txt','getmcp-extensions.php','includes/class-runtime.php',
 'includes/class-remote-catalogue.php','admin/native-connections.js','admin/remote-catalogue.js','admin/connections.css',
 'assets/remote-catalogue.json','tools/import-remote-catalogue.py','tools/handoff-catalogue.py','tools/build-ui.py',
 'build/index.js','compat/versions/1.7.0/build/index.js','compat/admin/class-admin.php','compat/versions/1.7.0/admin/class-admin.php',
 'tests/test-remote-catalogue.py','tests/remote-catalogue.php','tests/remote-catalogue-oauth.php','tests/remote-catalogue-flow.js','tests/remote-catalogue-navigation.php',
 'docs/remote-catalogue.md','docs/verification-remote-catalogue.md','docs/testing/test-definitions.json',
 'graphify-out/graph.json','graphify-out/graph.html','graphify-out/manifest.json','graphify-out/GRAPH_REPORT.md']
base=git('rev-parse','v1.2.4^{commit}').decode().strip();files={};manifest=[];patch=bytearray();prior_files={}
for name in names:
 current=(ROOT/name).read_bytes()
 result=subprocess.run(['git','show',base+':'+name],cwd=ROOT,capture_output=True)
 prior=result.stdout if result.returncode==0 else None
 if prior==current:continue
 files['changed-files/'+name]=current
 manifest.append({'path':name,'baseline_sha256':sha(prior) if prior is not None else None,'new_sha256':sha(current),'canonical_new_sha256':sha(current.replace(b'\r\n',b'\n'))})
 if prior is None:
  text=current.decode('utf-8').replace('\r\n','\n');diff=''.join(difflib.unified_diff([],text.splitlines(keepends=True),fromfile='/dev/null',tofile='b/'+name))
  patch.extend(('diff --git a/'+name+' b/'+name+'\nnew file mode 100644\n'+diff).encode())
 else:
  prior_files[name]=prior;patch.extend(git('diff','--binary',base,'--',name))
files['feature.patch']=bytes(patch)
files['changed-files.json']=(json.dumps({'baseline_commit':base,'baseline_tag':'v1.2.4','candidate_version':'1.3.0','files':manifest},indent=2)+'\n').encode()
with tempfile.TemporaryDirectory(prefix='getmcp-catalogue-patch-') as folder:
 target=Path(folder).resolve()
 for name,data in prior_files.items():
  dest=(target/name).resolve();assert dest.is_relative_to(target);dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data)
 patch_file=target/'feature.patch';patch_file.write_bytes(patch)
 subprocess.run(['git','apply','--check',str(patch_file)],cwd=target,check=True,capture_output=True)
package=ROOT/'dist/getmcp-extensions-1.3.0.zip';files['install/'+package.name]=package.read_bytes()
local=json.loads((ROOT/'evidence/local-checks.json').read_text())
for runtime in local['php']:runtime['runtime']=Path(runtime['runtime']).parent.parent.parent.name
files['verification/local-checks.json']=(json.dumps(local,indent=2)+'\n').encode()
for name in ['connections-800.png','navigation-gateways.png','navigation-catalogue.png','remote-catalogue-integration.json']:
 files['verification/'+name]=(ROOT/'evidence'/name).read_bytes()
files['README-HANDOFF.md']=(
 '# GetMCP Extensions 1.3.0 local candidate\n\nDeveloped by Synergetic Dev - https://synergetic.dev/\n\n'
 'Not a GitHub release or live deployment. Apply feature.patch to the baseline commit in changed-files.json; '
 'the patch passed git apply --check against those baseline files. Review the manifest and docs before installation. '
 'The runtime ZIP contains no fixture, evidence, developer tooling or customer configuration. The synthetic OAuth '
 'responses in developer tests are intentionally named fixture-only; no real provider credentials are included. '
 'Existing vendor files and database schemas are unchanged. Retain salts and database for rollback, restore the '
 'prior add-on ZIP, and keep the owned endpoint guard. Read docs/remote-catalogue.md for setup, APIs, maintenance '
 'and rollback, and docs/verification-remote-catalogue.md for passed, skipped and unavailable coverage.\n').encode()
patterns=[rb'gh[pousr]_[A-Za-z0-9]{25,}',rb'github_pat_[A-Za-z0-9_]{25,}',rb'ya29\.',rb'AIza[A-Za-z0-9_-]{20,}',rb'wpdev\.synergetic\.dev',rb'dashja@gmail\.com',rb'C:\\Users\\dashj']
for name,data in files.items():
 if name.endswith(('.zip','.png')):continue
 if any(re.search(p,data)for p in patterns):raise SystemExit('Credential/customer context marker: '+name)
files['SHA256SUMS']=''.join(sha(data)+'  '+name+'\n'for name,data in sorted(files.items())).encode()
out=ROOT/'dist/getmcp-extensions-1.3.0-candidate-handoff.zip'
with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED,compresslevel=9)as archive:
 for name,data in sorted(files.items()):
  entry=zipfile.ZipInfo(name,(2026,10,15,0,0,0));entry.compress_type=zipfile.ZIP_DEFLATED;archive.writestr(entry,data)
with zipfile.ZipFile(out)as archive:assert archive.testzip()is None
(ROOT/'dist/SHA256SUMS').write_text(sha(package.read_bytes())+'  '+package.name+'\n'+sha(out.read_bytes())+'  '+out.name+'\n')
print(json.dumps({'handoff':str(out),'sha256':sha(out.read_bytes()),'changed_files':len(manifest),'patch_check':'passed'}))
