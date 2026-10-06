"""Reproducible release ZIP with a package-only updater marker and exact hash inventory."""
from pathlib import Path
import hashlib,json,re,zipfile
ROOT=Path(__file__).resolve().parents[1]
version=re.search(r'\* Version:\s*(\S+)',(ROOT/'getmcp-extensions.php').read_text()).group(1)
assert re.fullmatch(r'\d+\.\d+\.\d+',version)
assert f'Stable tag: {version}' in (ROOT/'readme.txt').read_text()
required=['getmcp-extensions.php','includes/class-updater.php','compatibility.json',
 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php','vendor/yahnis-elsts/plugin-update-checker/license.txt']
assert all((ROOT/p).is_file() for p in required),'Missing runtime or legal dependency.'
files={}
for folder in ['admin','build','compat','includes','assets','vendor']:
 for p in (ROOT/folder).rglob('*'):
  if p.is_file(): files[p.relative_to(ROOT).as_posix()]=p.read_bytes()
for name in ['getmcp-extensions.php','compatibility.json','readme.txt','CHANGELOG.md','README.md','LICENSE','LICENSE-NOTICE.md']:
 files[name]=(ROOT/name).read_bytes()
files['DISTRIBUTION']=f'GetMCP Extensions {version}\n'.encode()
assert all(not any(x in p.split('/') for x in ['.git','.github','tests','tools','docs','graphify-out','evidence']) for p in files)
out=ROOT/'dist';out.mkdir(exist_ok=True)
archive=out/f'getmcp-extensions-{version}.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED,compresslevel=9) as z:
 for name,data in sorted(files.items()):
  entry=zipfile.ZipInfo('getmcp-extensions/'+name,(2026,10,6,0,0,0));entry.compress_type=zipfile.ZIP_DEFLATED;entry.external_attr=0o100644<<16
  z.writestr(entry,data)
with zipfile.ZipFile(archive) as z:
 assert z.testzip() is None
 assert all(z.read('getmcp-extensions/'+n)==d for n,d in files.items())
digest=hashlib.sha256(archive.read_bytes()).hexdigest()
(out/'SHA256SUMS').write_text(f'{digest}  {archive.name}\n')
(out/'files.json').write_text(json.dumps({n:hashlib.sha256(d).hexdigest() for n,d in sorted(files.items())},indent=2)+'\n')
print(json.dumps({'version':version,'files':len(files),'zip':str(archive),'sha256':digest}))
