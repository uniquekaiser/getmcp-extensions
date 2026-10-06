"""Run feasible acceptance/regressions in the two owned Docker fixtures, locally only."""
from pathlib import Path
import json,os,subprocess
ROOT=Path(__file__).resolve().parents[1]
tests=['marketing/wordpress.php','marketing/execution.php','marketing/discovery.php','marketing/lifecycle.php','gateway-wordpress.php','gateway-protocol.php','gateway-http-auth.php','gateway-native-list.php','addon/runtime-wordpress.php','connections-wordpress.php']
base=dict(os.environ)
if not base.get('GETMCP_QA_VENDOR'):raise SystemExit('Set GETMCP_QA_VENDOR to an owned vendor fixture.')
for project,image,cli,port,folder in [
 ('getmcp-extensions-release-qa','wordpress:7.1.2-php8.3-apache','wordpress:cli-2.12.0-php8.3','8917','connections-1.2.0'),
 ('getmcp-extensions-release-minqa','wordpress:6.2.2-php8.2-apache','wordpress:cli-2.12.0-php8.2','8918','minimum/connections-1.2.0')]:
 out=ROOT/'evidence'/folder;out.mkdir(parents=True,exist_ok=True)
 env=dict(base,GETMCP_QA_IMAGE=image,GETMCP_QA_CLI_IMAGE=cli,GETMCP_QA_PORT=port,GETMCP_QA_EVIDENCE=str(out))
 results=[]
 for test in tests:
  cmd=['docker','compose','-p',project,'-f','tests/release/compose.yml','run','--rm','cli','eval-file','/fixtures/'+test]
  p=subprocess.run(cmd,cwd=ROOT,env=env,text=True,capture_output=True,encoding='utf-8')
  (out/(test.replace('/','-')+'.log')).write_text(p.stdout+p.stderr,encoding='utf-8')
  results.append({'test':test,'status':'passed' if p.returncode==0 else 'failed','command':cmd})
  print(project+' '+test+': '+results[-1]['status'],flush=True)
  (out/'summary.json').write_text(json.dumps(results,indent=2)+'\n',encoding='utf-8')
  if p.returncode:print(p.stdout+p.stderr);raise SystemExit(p.returncode)
