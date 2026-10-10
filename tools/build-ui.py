"""Build reviewed admin seams against the explicitly supplied GetMCP package."""
from pathlib import Path
import hashlib,subprocess,os,re,shutil
ROOT=Path(__file__).resolve().parents[1]
def replace_once(text,old,new):
    assert text.count(old)==1, 'Reviewed UI anchor changed or is ambiguous: '+old
    return text.replace(old,new,1)
vendor=Path(os.environ.get('GETMCP_UI_VENDOR',''))
if not vendor.is_dir(): raise SystemExit('Set GETMCP_UI_VENDOR to the separately acquired GetMCP package directory.')
version=re.search(r"define\(\s*'GETMCP_VERSION',\s*'([^']+)'",(vendor/'getmcp.php').read_text(encoding='utf-8'))
if not version: raise SystemExit('Could not identify the GetMCP version.')
if version.group(1)=='1.7.0':
    # Retain the 1.7 vendor UI and lazy chunks, adding only the separately maintained add-on screens.
    index=(vendor/'build/index.js').read_text(encoding='utf-8')
    detail_file=next((vendor/'build').glob('890.*.js'))
    detail=detail_file.read_text(encoding='utf-8')
    detail=replace_once(detail,'}),o&&(0,P.jsx)(X','}),(0,P.jsx)(window.GetMCPExtensionsCodexConnect,{serverId:e}),o&&(0,P.jsx)(X')
    digest=hashlib.sha256(detail.encode()).hexdigest()[:8]
    index=replace_once(index,'890:"'+detail_file.stem.split('.',1)[1]+'"','890:"'+digest+'"')
    # Register add-on components in the same webpack runtime used by GetMCP 1.7.
    seam='function re(e){return c().lazy(()=>e().then(e=>{try{window.sessionStorage.removeItem(ae)}catch{}return e}).catch(e=>{let t=!1;try{t="1"===window.sessionStorage.getItem(ae),t||window.sessionStorage.setItem(ae,"1")}catch{t=!0}if(!t)return window.location.reload(),new Promise(()=>{});throw e}))}'
    runtime='window.registerGetMCPConnectionRuntime(s);const addonSettings=re(()=>window.loadGetMCPExtensionsPage().then(Page=>({default:Page}))),nativeConnections=re(()=>window.loadGetMCPConnectionPage().then(Page=>({default:Page}))),myConnections=re(()=>window.loadGetMCPConnectionPage().then(Page=>({default:()=> (0,u.jsx)(Page,{portal:true})})));'
    index=replace_once(index,seam,seam+runtime)
    index=replace_once(index,'path:"/gateway",element:(0,u.jsx)(ce,{})','path:"/gateway",element:(0,u.jsxs)(c().Fragment,{children:[(0,u.jsx)(window.GetMCPExtensionsProjectGateways,{}),(0,u.jsx)(ce,{})]})')
    index=replace_once(index,'path:"/gateway",element:', 'path:"/gateway",element:') if False else index
    # Put explicit extension and personal-connection screens beside native routes.
    index=replace_once(index,'(0,u.jsx)(d.qh,{path:"/gateway",element:', '(0,u.jsx)(d.qh,{path:"/gateway",element:')
    index=replace_once(index,'(0,u.jsx)(d.qh,{path:"/chats",element:', '(0,u.jsx)(d.qh,{path:"/extensions",element:(0,u.jsx)(addonSettings,{})}),(0,u.jsx)(d.qh,{path:"/connections",element:(0,u.jsx)(nativeConnections,{})}),(0,u.jsx)(d.qh,{path:"/my-connections",element:(0,u.jsx)(myConnections,{})}),(0,u.jsx)(d.qh,{path:"/chats",element:')
    # Add navigation entries without changing existing 1.7 items.
    index=replace_once(index,'{key:"gateway",label:(0,b.__)("Gateway","getmcp"),icon:(0,u.jsx)(N.A,{size:20}),path:"/gateway"},','{key:"gateway",label:(0,b.__)("Gateway","getmcp"),icon:(0,u.jsx)(N.A,{size:20}),path:"/gateway"},{key:"extensions",label:(0,b.__)("Extensions","getmcp"),icon:(0,u.jsx)(N.A,{size:20}),path:"/extensions"},{key:"connections",label:(0,b.__)("Gateways & Remote MCP","getmcp"),icon:(0,u.jsx)(N.A,{size:20}),path:"/connections"},{key:"my-connections",label:(0,b.__)("My MCP Connections","getmcp"),icon:(0,u.jsx)(N.A,{size:20}),path:"/my-connections"},')
    # GetMCP 1.7 has a different webpack runtime from the 1.6 app. Keep its
    # patched bundle and lazy chunks isolated so a single add-on ZIP can serve
    # both core versions without loading the wrong SPA on either one.
    out=ROOT/'compat'/'versions'/'1.7.0'/'build'
    out.mkdir(parents=True, exist_ok=True)
    for old in out.glob('*.js'): old.unlink()
    for source in (vendor/'build').glob('*.js'): shutil.copy2(source,out/source.name)
    (out/detail_file.name).unlink()
    (out/('890.'+digest+'.js')).write_text(detail,encoding='utf-8',newline='\n')
    (out/'index.js').write_text(index,encoding='utf-8',newline='\n')
    legacy_assets=ROOT/'build'/'index.asset.php'
    if legacy_assets.is_file(): shutil.copy2(legacy_assets,out/'index.asset.php')
    print('Built GetMCP 1.7.0 UI with add-on routes; copied vendor lazy chunks.')
else:
    def baseline(file): return subprocess.check_output(['git','show','v1.1.2:'+file],cwd=ROOT).decode('utf-8')
    # Recreate the legacy bundle from its matching 1.6-era baseline. The 1.7
    # chunks must never leak into this directory: both app runtimes share chunk
    # ids, and Webpack resolves lazy chunks beside the selected entry script.
    legacy_out=ROOT/'build'
    legacy_out.mkdir(exist_ok=True)
    for old in legacy_out.iterdir():
        if old.is_file(): old.unlink()
    for file in ['build/index.asset.php','build/186.84205ded.js']:
        target=legacy_out/file.split('/',1)[1]
        target.write_bytes(subprocess.check_output(['git','show','v1.1.2:'+file],cwd=ROOT))
    index=baseline('build/index.js')
    chunk=baseline('build/890.cfddfe37.js')
    chunk=replace_once(chunk,'}),o&&(0,E.jsx)(X','}),(0,E.jsx)(window.GetMCPExtensionsCodexConnect,{serverId:e}),o&&(0,E.jsx)(X')
    digest=hashlib.sha256(chunk.encode()).hexdigest()[:8]
    index=replace_once(index,'890:"cfddfe37"','890:"'+digest+'"')
    index=replace_once(index,'890.cfddfe37.js','890.'+digest+'.js')
    index=replace_once(index,'path:"/gateway",element:(0,u.jsx)(ie,{})','path:"/gateway",element:(0,u.jsxs)(c().Fragment,{children:[(0,u.jsx)(window.GetMCPExtensionsProjectGateways,{}),(0,u.jsx)(ie,{})]})')
    template_file=vendor/'build/707.3612ecad.js'
    if not template_file.is_file(): raise SystemExit('Supported UI build is GetMCP 1.6.0 or 1.7.0.')
    templates=template_file.read_text(encoding='utf-8')
    assert hashlib.sha256(templates.encode()).hexdigest()=='b29d6bd9ac8fd1914ea96c933423b3c130e6cd9fa5b30e165a957cd7f5eea344','Unknown GetMCP 1.6 template UI snapshot.'
    templates=replace_once(templates,'],O={','].concat((window.getmcpMarketingTemplates||[]).map(t=>({...t,iconComponent:(0,R.jsx)(y.A,{className:"w-6 h-6"})}))),O={')
    template_hash=hashlib.sha256(templates.encode()).hexdigest()[:8]
    index=replace_once(index,'707:"3612ecad"','707:"'+template_hash+'"')
    index=replace_once(index,'"890.'+digest+'.js"].includes','"890.'+digest+'.js","707.'+template_hash+'.js"].includes')
    (legacy_out/('890.'+digest+'.js')).write_text(chunk,encoding='utf-8',newline='\n')
    (legacy_out/'index.js').write_text(index,encoding='utf-8',newline='\n')
    (legacy_out/('707.'+template_hash+'.js')).write_text(templates,encoding='utf-8',newline='\n')
    print('Built reviewed GetMCP 1.6.0 UI seams; connection '+digest+', templates '+template_hash)
