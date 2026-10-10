"""Local PHP/JS, updater contract and public-source inventory checks; no hosted CI."""
from pathlib import Path
import json,os,re,shutil,subprocess,sys,tempfile
ROOT=Path(__file__).resolve().parents[1]
reports=[]
php_candidates=[Path(os.environ.get('APPDATA',''))/f'Local/lightning-services/php-{v}+1/bin/win64/php.exe' for v in ['8.2.30','8.3.29','8.4.16']]
if shutil.which('php'):php_candidates.append(Path(shutil.which('php')))
runtimes=[p for p in php_candidates if p.is_file()]
if not runtimes:raise SystemExit('No PHP runtime: PHP verification unavailable, release gate cannot pass.')
php_files=list(ROOT.rglob('*.php'));php_files=[p for p in php_files if not any(x in p.relative_to(ROOT).parts for x in ['dist','evidence','fixtures','.distribution-tests','.auth-root'])]
vendor=os.environ.get('GETMCP_VENDOR_ROOT')
for php in runtimes:
 failures=[]
 for p in php_files:
  r=subprocess.run([str(php),'-l',str(p)],capture_output=True,text=True)
  if r.returncode:failures.append({'file':p.relative_to(ROOT).as_posix(),'error':r.stdout+r.stderr})
 tests=[]
 if vendor:
  fixture=ROOT/'tests/.auth-root';shutil.copytree(vendor,fixture,dirs_exist_ok=True);shutil.copytree(ROOT/'compat',fixture,dirs_exist_ok=True)
  env=dict(os.environ,GETMCP_TEST_ROOT=str(fixture))
  for test in ['native-oauth-security.php','oauth-user-access.php']:
   r=subprocess.run([str(php),'-d','auto_prepend_file='+str(ROOT/'tests/addon/fixture-bootstrap.php'),str(ROOT/'tests'/test)],env=env,capture_output=True,text=True)
   tests.append({'test':test,'passed':r.returncode==0,'output':r.stdout+r.stderr})
 reports.append({'runtime':str(php),'linted':len(php_files),'failures':failures,'auth_tests':tests or 'unavailable: set GETMCP_VENDOR_ROOT to an owned vendor fixture'})
js=[]
for p in ROOT.rglob('*.js'):
 if any(x in p.relative_to(ROOT).parts for x in ['dist','evidence','fixtures','.distribution-tests','.auth-root','graphify-out']):continue
 r=subprocess.run(['node','--check',str(p)],capture_output=True,text=True)
 js.append({'file':p.relative_to(ROOT).as_posix(),'passed':r.returncode==0,'error':r.stderr if r.returncode else ''})
text=(ROOT/'includes/class-updater.php').read_text()
assert 'latest_release' in text and 'vcs_update_detection_strategies' in text
assert 'enableReleaseAssets' in text and 'DISTRIBUTION' in text and '/.git' in text
assert 'setAuthentication' not in text and 'GITHUB_TOKEN' not in text
workflows=list((ROOT/'.github/workflows').glob('*.yml'))
assert len(workflows)==1 and workflows[0].name=='release.yml','Expected only the reviewed release workflow.'
workflow=workflows[0].read_text(encoding='utf-8')
assert "push:" in workflow and "tags:" in workflow and "'v*'" in workflow
assert 'pull_request:' not in workflow and 'workflow_dispatch:' not in workflow and 'workflow_run:' not in workflow
# Reject credentials/private machine context in publishable text; synthetic fixture credentials are explicitly named.
bad=[]
for p in ROOT.rglob('*'):
 if not p.is_file() or p.suffix not in {'.php','.js','.json','.md','.txt','.py','.yml'}:continue
 if any(x in p.relative_to(ROOT).parts for x in ['.git','evidence','dist','fixtures','.distribution-tests','.auth-root','graphify-out']):continue
 data=p.read_text(encoding='utf-8',errors='replace')
 if re.search(r'gh[pousr]_[A-Za-z0-9]{25,}|github_pat_[A-Za-z0-9_]{25,}|-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----|https://wpdev\.synergetic\.dev|dashja@gmail\.com',data):
  if not ('BEGIN OPENSSH' in data and 'END OPENSSH' in data and 'leave blank to keep it' in data and '…' in data):bad.append(p.relative_to(ROOT).as_posix())
report={'php':reports,'javascript':js,'public_inventory_failures':bad,'hosted_ci':'tag-triggered stable release workflow configured; hosted run occurs only on version-tag push','provider_live_writes':'not performed'}
out=ROOT/'evidence';out.mkdir(exist_ok=True);(out/'local-checks.json').write_text(json.dumps(report,indent=2))
print(json.dumps({'php_runtimes':len(reports),'php_files':len(php_files),'javascript_files':len(js),'public_inventory_failures':bad}))
assert not bad
assert all(not r['failures'] and (not isinstance(r['auth_tests'],list) or all(t['passed'] for t in r['auth_tests'])) for r in reports)
assert all(t['passed'] for t in js)
