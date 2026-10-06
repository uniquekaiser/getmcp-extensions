"""Reviewed seams for GetMCP's distributed React build; readable behavior lives in admin/.

Uses the add-on's v1.1.2 Git snapshot, never modifies a vendor checkout, and fails on
unexpected anchors. An upstream source build should import the two components instead.
"""
from pathlib import Path
import hashlib,subprocess,os
ROOT=Path(__file__).resolve().parents[1]
def baseline(file):
    return subprocess.check_output(['git','show','v1.1.2:'+file],cwd=ROOT).decode('utf-8')
def replace_once(text,old,new):
    assert text.count(old)==1, 'Reviewed UI anchor changed: '+old
    return text.replace(old,new,1)
index=baseline('build/index.js')
chunk=baseline('build/890.cfddfe37.js')
chunk=replace_once(chunk,'}),o&&(0,E.jsx)(X','}),(0,E.jsx)(window.GetMCPExtensionsCodexConnect,{serverId:e}),o&&(0,E.jsx)(X')
digest=hashlib.sha256(chunk.encode()).hexdigest()[:8]
index=replace_once(index,'path:"/gateway",element:(0,u.jsx)(ie,{})','path:"/gateway",element:(0,u.jsxs)(c().Fragment,{children:[(0,u.jsx)(window.GetMCPExtensionsProjectGateways,{}),(0,u.jsx)(ie,{})]})')
index=replace_once(index,'890:"cfddfe37"','890:"'+digest+'"')
index=replace_once(index,'890.cfddfe37.js','890.'+digest+'.js')
vendor=Path(os.environ.get('GETMCP_UI_VENDOR',''))
template_file=vendor/'build/707.3612ecad.js'
if not template_file.is_file():raise SystemExit('Set GETMCP_UI_VENDOR to the separately acquired original GetMCP 1.6.0 directory.')
templates=template_file.read_text(encoding='utf-8')
assert hashlib.sha256(templates.encode()).hexdigest()=='b29d6bd9ac8fd1914ea96c933423b3c130e6cd9fa5b30e165a957cd7f5eea344', 'Unknown GetMCP template UI snapshot.'
templates=replace_once(templates,'],O={','].concat((window.getmcpMarketingTemplates||[]).map(t=>({...t,iconComponent:(0,R.jsx)(y.A,{className:"w-6 h-6"})}))),O={')
template_hash=hashlib.sha256(templates.encode()).hexdigest()[:8]
index=replace_once(index,'707:"3612ecad"','707:"'+template_hash+'"')
index=replace_once(index,'"890.'+digest+'.js"].includes','"890.'+digest+'.js","707.'+template_hash+'.js"].includes')
for p in (ROOT/'build').glob('890.*.js'):
    assert p.parent==ROOT/'build'
    p.unlink()
(ROOT/'build'/('890.'+digest+'.js')).write_text(chunk,encoding='utf-8',newline='\n')
(ROOT/'build/index.js').write_text(index,encoding='utf-8',newline='\n')
for p in (ROOT/'build').glob('707.*.js'):p.unlink()
(ROOT/'build'/('707.'+template_hash+'.js')).write_text(templates,encoding='utf-8',newline='\n')
print('Built reviewed UI seams; connection '+digest+', templates '+template_hash)
