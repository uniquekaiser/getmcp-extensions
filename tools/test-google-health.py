"""Local Google Health and connection regressions on the single shared fixture."""
from pathlib import Path
import json, subprocess, sys

ROOT = Path(__file__).resolve().parents[1]
CONTAINER = 'getmcp-telegram-qa-wordpress-1'
PLUGIN = '/var/www/html/wp-content/plugins/getmcp-extensions/'
OUT = ROOT/'evidence/google-health'
OUT.mkdir(parents=True, exist_ok=True)
tests = ['google-health-wordpress.php', 'connections-wordpress.php', 'remote-catalogue.php', 'remote-catalogue-oauth.php']
results = []
for test in tests:
    cmd = ['docker','exec',CONTAINER,'wp','--allow-root','eval-file',PLUGIN+'tests/'+test]
    p = subprocess.run(cmd, cwd=ROOT, capture_output=True, text=True, encoding='utf-8')
    (OUT/(test+'.log')).write_text(p.stdout+p.stderr, encoding='utf-8')
    try:
        report = json.loads(p.stdout)
    except json.JSONDecodeError:
        report = {}
    results.append(dict(test=test, status='passed' if p.returncode == 0 else 'failed', checks=report.get('passed'), command=cmd))
    print(test+': '+results[-1]['status']+' ('+str(report.get('passed','unreported'))+' checks)', flush=True)
    (OUT/'summary.json').write_text(json.dumps(results, indent=2)+'\n', encoding='utf-8')
    if p.returncode:
        print(p.stdout+p.stderr)
        sys.exit(p.returncode)
