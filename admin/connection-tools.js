/** Client setup and project navigation, independent of GetMCP's compiled UI. */
window.createGetMCPConnectionTools = function({React, api}) {
  const {createElement:h,useState,useEffect} = React;
  const quote = value => JSON.stringify(String(value));
  function CodexConnect({serverId,connection}) {
    const [open,setOpen]=useState(false),[data,setData]=useState(connection),[error,setError]=useState(''),[copied,setCopied]=useState(false);
    const load=async()=>{setOpen(!open);if(!data){try{setData(await api('connection-config/'+serverId));}catch(e){setError(e.message||'Could not load connection settings.');}}};
    const slug=data?.slug || 'getmcp';
    const auth=data?.auth_type || 'oauth';
    const tokenAuth=data && !['oauth','none'].includes(auth);
    const unsupported=tokenAuth && (data.auth_location==='query' || !['basic','bearer','api-key'].includes(auth));
    const authConfig=auth==='bearer'?'bearer_token_env_var = "GETMCP_TOKEN"\n':tokenAuth && !unsupported?`[mcp_servers.${quote(slug)}.env_http_headers]\n${quote(data.auth_header || 'Authorization')} = "GETMCP_AUTH_HEADER"\n`:'';
    const text=data ? `[mcp_servers.${quote(slug)}]\nurl = ${quote(data.url)}\n`+authConfig : '';
    return h('section',{className:'mt-4 space-y-3'},h('button',{type:'button',className:'rounded border px-4 py-2 text-sm',onClick:load},'Codex Desktop'),
      open && h('div',{className:'rounded border p-4 space-y-3'},
        error ? h('p',{role:'alert'},error) : !data ? h('p',null,'Loading connection settings…') : h(React.Fragment,null,
          h('p',null,'In Codex Desktop, open Settings → MCP servers → Add server. Select HTTP, enter this URL, then save and authenticate when requested.'),
          h('code',{className:'block break-all'},data.url),
          h('p',null,'Or merge this server entry into ~/.codex/config.toml (on Windows: %USERPROFILE%\\.codex\\config.toml). Keep your existing entries.'),
          h('textarea',{'aria-label':'Codex config.toml',readOnly:true,value:text,rows:tokenAuth?5:3,className:'w-full rounded border p-3 font-mono text-sm'}),
          h('button',{type:'button',className:'rounded border px-3 py-2',onClick:async()=>{try{await navigator.clipboard.writeText(text);setCopied(true);}catch(_){setError('Select and copy the configuration above.');}}},copied?'Copied':'Copy config.toml'),
          unsupported ? h('p',{role:'alert'},'This authentication mode needs manual client configuration. Prefer native WordPress OAuth; the URL-only snippet above is incomplete for this mode.') : tokenAuth ? h('p',null,auth==='bearer'?'Set GETMCP_TOKEN locally to your own GetMCP bearer credential.':auth==='basic'?'Set GETMCP_AUTH_HEADER locally to your full Basic authorization header (Basic followed by the base64 encoding of username:password).':'Set GETMCP_AUTH_HEADER locally to your own API key.',' Credentials are never included in this configuration.') : auth!=='none' && h('p',null,'Authenticate in Codex Desktop, or run: ',h('code',null,'codex mcp login '+slug)),
          h('a',{href:'https://developers.openai.com/codex/mcp/',target:'_blank',rel:'noopener noreferrer'},'Codex MCP setup guide'))));
  }
  function ProjectGateways() {
    const [items,setItems]=useState(null);
    useEffect(()=>{let active=true;api('connections').then(r=>{if(active)setItems((r.servers||[]).filter(s=>s.kind==='gateway'));}).catch(()=>{});return()=>{active=false;};},[]);
    if(!items)return null;
    return h('section',{className:'mb-6 rounded-lg border bg-white p-5 space-y-3','aria-label':'Project gateways'},
      h('h2',{className:'text-lg font-semibold'},'Project gateways ('+items.length+')'),
      h('p',null,'Each project gateway has its own server selection and allowed users. The original /mcp gateway settings are below.'),
      h('a',{href:'admin.php?page=getmcp-connections#/connections',className:'inline-block rounded border px-4 py-2'},'Manage project gateways'),
      h('ul',{className:'max-h-64 overflow-y-auto'},...items.map(s=>h('li',{key:s.id,className:'py-2'},h('strong',null,s.name),' · '+s.slug+' · '+s.status,h('code',{className:'block text-sm break-all'},s.url)))));
  }
  return {CodexConnect,ProjectGateways};
};
