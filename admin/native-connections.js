/** Readable pages using GetMCP's own React, UI kit, API helper and toast context. */
window.createGetMCPConnectionsPage = function({React, UI, api, useToast}) {
  const {createElement: h, useState, useEffect} = React;
  const {Button, Card, Badge, CopyButton, Input, Select, PageHeader, PageContainer, ConfirmDialog, EmptyState, Skeleton} = UI;
  const Picker = createOAuthUserPicker(React, path => api(path));
  const words = value => (value || '').replaceAll('_', ' ');
  return function ConnectionsPage({portal = false}) {
    const {addToast} = useToast();
    const [items, setItems] = useState([]), [form, setForm] = useState(null);
    const [error, setError] = useState(''), [loading, setLoading] = useState(true), [busy, setBusy] = useState(false);
    const [preview, setPreview] = useState(null), [secret, setSecret] = useState('');
    const [kind, setKind] = useState('gateway'), [query, setQuery] = useState(''), [memberQuery, setMemberQuery] = useState('');
    const [confirm, setConfirm] = useState(null);
    const [entryMode,setEntryMode] = useState('url'), [document,setDocument] = useState(''), [importPreview,setImportPreview] = useState(null);
    const [headers,setHeaders] = useState([]), [sharedKind,setSharedKind] = useState('bearer'), [sharedName,setSharedName] = useState('X-API-Key'), [sharedUser,setSharedUser] = useState('');
    const [providerPreview,setProviderPreview] = useState(null);
    const load = async () => { const r = await api(portal ? 'my-connections' : 'connections'); setItems(r.connections || r.servers || []); };
    const action = async fn => {
      setBusy(true); setError('');
      try { await fn(); } catch (e) { const message = e.message || 'The operation could not be completed.'; setError(message); addToast(message, 'error'); }
      finally { setBusy(false); }
    };
    useEffect(() => { load().catch(e => setError(e.message || 'Could not load connections.')).finally(() => setLoading(false)); }, [portal]);
    const update = (key, value) => setForm(p => ({...p, [key]: value}));
    const remote = (key, value) => setForm(p => ({...p, remote: {...p.remote, [key]: value}}));
    const edit = item => { setForm({...item, allowed_user_ids: item.allowed_user_ids || [], server_ids: item.server_ids || [], remote: item.remote || {auth_mode: 'oauth'}}); setSecret(''); setPreview(null); setError(''); setMemberQuery(''); setHeaders((item.header_names || []).map(name=>({name,value:''}))); setEntryMode('url'); setImportPreview(null); setDocument(''); };
    const button = (label, fn, variant = 'outline') => h(Button, {variant, disabled: busy || (label === 'Create gateway' && !available('gateway')) || (label === 'Add Remote MCP' && !available('remote-mcp')), onClick: () => action(fn)}, label);
    const field = (label, value, fn, type = 'text', extra = {}) => h(Input, {label, type, value: value || '', disabled: busy, onChange: e => fn(e.target.value), ...extra});
    const checkbox = (label, checked, fn) => h('label', {className: 'flex items-center gap-2 text-sm text-gray-700'}, h('input', {type: 'checkbox', checked, disabled: busy, className: 'rounded border-gray-300 text-brand-600 focus:ring-brand-500', onChange: e => fn(e.target.checked)}), label);
    const save = async () => {
      const body = {kind: form.kind, name: form.name, slug: form.slug, status: form.status, allowed_user_ids: form.allowed_user_ids};
      if (form.kind === 'gateway') body.server_ids = form.server_ids; else body.remote = form.remote;
      if (form.kind === 'remote-mcp') body.connection_headers = headers.filter(row=>row.name.trim()).map(row=>({name:row.name.trim(),value:row.value}));
      if (secret && form.remote.auth_mode !== 'none') {
        if(form.remote.auth_mode==='oauth') body.credentials={client_secret:secret};
        else if(sharedKind==='basic') body.credentials={headers:{Authorization:'Basic '+btoa(String.fromCharCode(...new TextEncoder().encode(sharedUser+':'+secret)))}};
        else body.credentials={headers:{[sharedKind==='api-key'?sharedName:'Authorization']:sharedKind==='bearer'?'Bearer '+secret:secret}};
      }
      const saved = form.import_token ? await api('connections/import-confirm',{method:'POST',data:{preview_token:form.import_token,index:form.import_index,configuration:body}}) : await api('connections' + (form.id ? '/' + form.id : ''), {method: form.id ? 'PUT' : 'POST', data: body});
      edit(saved); await load(); addToast(form.kind === 'gateway' ? 'Gateway saved. Selected servers are available to its allowed users.' : 'Remote MCP connection saved.', 'success');
    };
    const flags = window.getmcpExtensions?.effective_features || {};
    const available = value => value === 'gateway' ? flags.project_gateways !== false : flags.remote_mcp !== false;
    const title = portal ? 'My MCP Connections' : form ? (form.id ? 'Edit ' + form.name : form.kind === 'gateway' ? 'Create gateway' : 'Add Remote MCP') : 'Gateways & Remote MCP';
    const selected = items.filter(item => item.kind === kind && (item.name + ' ' + item.slug).toLowerCase().includes(query.toLowerCase()));
    const candidates = items.filter(item => item.kind !== 'gateway' && item.id > 0);
    const previewSections = [['tools/list','tools','Tools'], ['resources/list','resources','Resources'], ['resources/templates/list','resourceTemplates','Resource templates'], ['prompts/list','prompts','Prompts']];
    return h(PageContainer, null,
      h(PageHeader, {title, description: portal ? 'Connect your own upstream accounts for the servers you can access.' : 'Group servers by project and connect remote MCP services.', actions: !portal && !form && h(React.Fragment, null,
        button('Create gateway', () => edit({kind: 'gateway', name: '', slug: '', status: 'active'}), 'primary'), button('Add Remote MCP', () => edit({kind: 'remote-mcp', name: '', slug: '', status: 'active'})))}),
      !portal && h('p', {className:'text-sm text-gray-500'}, 'Feature switches and compatibility: ', h('a',{href:'admin.php?page=getmcp-extensions'},'GetMCP Extensions')),
      error && h('div', {role: 'alert', className: 'rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-600'}, error, !form && button('Retry', load)),
      loading ? h(Skeleton, {variant: 'card'}) : h('div', {className: 'space-y-6'},
        portal ? h(React.Fragment, null,
          !items.length && h(Card, null, h(EmptyState, {title: 'No account connections available', description: 'Your administrator can make upstream OAuth servers available through a gateway.'})),
          ...items.map(item => h(Card, {key: item.id, title: item.name, actions: h(Badge, {variant: item.connected && !item.reconnect_required ? 'success' : 'neutral', dot: true}, item.reconnect_required ? 'Reconnect required' : item.connected ? 'Connected' : 'Not connected')},
            item.kind==='rest-provider' && h('div',{className:'space-y-3 mb-4'},
              h('p',null,`Application: ${item.has_application_credentials?'configured':'missing credentials'} · Scopes: ${item.readiness?.required_scopes_present===true?'required scopes present':item.readiness?.required_scopes_present===false?'required scopes missing':'not yet verified'} · Harmless read: ${item.readiness?.read_verified?'verified':'not verified'}`),
              item.expires_at && h('p',null,'Token expiry: '+new Date(item.expires_at*1000).toLocaleString()),
              item.connected && button('Discover accounts and assets',async()=>{setProviderPreview(await api('my-connections/'+item.id+'/discover',{method:'POST',data:{}}));await load();}),
              item.supports_pages && item.connected && h(React.Fragment,null,
                button('Discover Instagram Pages',async()=>{await api('my-connections/'+item.id+'/assets',{method:'POST',data:{}});await load();}),
                h(Select,{label:'Selected Instagram Page',value:item.assets?.selected_page_id||'',options:[{value:'',label:'Choose a Page'},...(item.assets?.pages||[]).map(p=>({value:p.id,label:p.name+' · @'+p.instagram_username}))],onChange:e=>action(async()=>{const p=item.assets.pages.find(p=>p.id===e.target.value);if(p){await api('my-connections/'+item.id+'/assets',{method:'PUT',data:{page_id:p.id,instagram_id:p.instagram_id}});await load();}})}),
                item.can_enable_messaging && item.assets?.messaging_verified && button('Enable reviewed messaging drafts',async()=>{await api('servers/'+item.uuid+'/enable-messaging',{method:'POST',data:{}});await load();}),
                item.assets?.selected_page_id && button('Verify messaging access',async()=>{await api('my-connections/'+item.id+'/assets',{method:'POST',data:{operation:'verify'}});await load();}),
                h('p',null,item.assets?.messaging_verified?'Messaging access verified for this Page.':'Messaging requires granted permissions and a successful harmless conversation read.'))),
            h('div', {className: 'flex flex-wrap gap-3'}, button(item.connected ? 'Reconnect account' : 'Connect account', async () => { const r = await api('my-connections/' + item.id, {method: 'POST', data: {}}); window.location.assign(r.authorization_url); }, 'primary'),
              item.connected && button('Disconnect account', () => setConfirm({kind: 'disconnect', item}))))),
          providerPreview && h(Card,{title:'Discovery result — reporting is not yet verified'},h('pre',{className:'overflow-auto max-h-64'},JSON.stringify(providerPreview,null,2))))
        : form ? h(React.Fragment, null,
          form.kind==='remote-mcp' && !form.id && h(Card,{title:'Add server'},h('div',{role:'tablist','aria-label':'How to add the server',className:'flex gap-3'},button('Server URL',()=>setEntryMode('url')),button('Paste configuration',()=>setEntryMode('paste'))),
            entryMode==='paste' && h('div',{className:'space-y-4 mt-4'},
              h('label',null,'Configuration',h('textarea',{'aria-label':'Configuration',className:'block w-full rounded border p-3',rows:8,value:document,onChange:e=>setDocument(e.target.value)})),
              h('p',null,'Paste client JSON, basic YAML, Codex TOML, a Claude/Codex add command or a bare HTTPS URL. Preview maps supported fields; existing servers are preserved.'),
              button('Preview configuration',async()=>{setImportPreview(await api('connections/import-preview',{method:'POST',data:{document}}));setDocument('');}),
              importPreview && importPreview.entries.map(row=>h('div',{key:row.index,className:'rounded border p-3'},h('strong',null,row.name),h('p',null,row.supported?row.remote.endpoint:row.reason),row.unmapped_fields?.length>0&&h('p',null,'Unmapped fields: '+row.unmapped_fields.join(', ')),row.supported&&button('Use these settings',()=>{const token=importPreview.preview_token;edit({...row,kind:'remote-mcp',status:'draft',import_token:token,import_index:row.index});}))))),
          h(Card, {title: 'Endpoint settings', actions: form.id && h(CopyButton, {text: form.url, label: 'Copy URL'})}, h('div', {className: 'space-y-4'},
            h('div', {className: 'grid grid-cols-1 md:grid-cols-2 gap-4'}, field('Name', form.name, v => update('name', v), 'text', {required: true}), field('URL slug', form.slug, v => update('slug', v), 'text', {helperText: 'Used in the MCP connection URL.'})),
            h(Select, {label: 'Status', value: form.status, disabled: busy, onChange: e => update('status', e.target.value), options: ['active','paused','draft'].map(value => ({value, label: value[0].toUpperCase() + value.slice(1)}))}),
            form.url && h('code', {className: 'block text-sm font-mono text-gray-700 break-all'}, form.url))),
          h(Card, {title: 'Who can use this endpoint?', subtitle: 'WordPress login is required. Administrators must also be explicitly selected.'}, h(Picker, {value: form.allowed_user_ids, onChange: ids => update('allowed_user_ids', ids)})),
          form.kind === 'gateway' ? h(Card, {title: 'Member servers', subtitle: 'Allowed gateway users can use every selected server. Direct-server allowlists apply only to direct connections.'}, h('div', {className: 'space-y-4'},
            field('Search member servers', memberQuery, setMemberQuery, 'search'), h('p', {className: 'text-sm text-gray-500'}, `${form.server_ids.length} selected`),
            h('div', {className: 'max-h-64 overflow-y-auto space-y-3'}, ...candidates.filter(item => item.name.toLowerCase().includes(memberQuery.toLowerCase())).map(item => h('div', {key: item.id}, checkbox(`${item.name} (${item.status})`, form.server_ids.includes(item.id), checked => update('server_ids', checked ? [...form.server_ids, item.id] : form.server_ids.filter(id => id !== item.id)))))),
            !candidates.length && h('p', {className: 'text-sm text-gray-500'}, 'Add a native server or Remote MCP connection first.')))
          : h(Card, {title: 'Upstream connection', subtitle: 'These credentials connect GetMCP to the upstream service. They are separate from WordPress login.'}, h('div', {className: 'space-y-4'},
            field('Public HTTPS MCP endpoint', form.remote.endpoint, v => remote('endpoint', v), 'url', {required: true}),
            h(Select, {label: 'Upstream authentication', value: form.remote.auth_mode, disabled: busy, onChange: e => { remote('auth_mode', e.target.value); setSecret(''); }, options: [{value:'none',label:'None'}, {value:'shared',label:'Shared operator credentials'}, {value:'oauth',label:'Each user connects with OAuth'}]}),
            form.remote.auth_mode === 'oauth' && h(React.Fragment,null,h('p',null,'Users sign in to the service from My MCP Connections. OAuth endpoints and client registration are discovered automatically when supported.'),h('details',null,h('summary',null,'Advanced OAuth settings'),h('div', {className: 'space-y-4 mt-3'}, field('OAuth client ID', form.remote.client_id, v => remote('client_id', v), 'text', {helperText:'Optional if the upstream supports client registration.'}), field('OAuth scopes', form.remote.scope, v => remote('scope', v)), field('Protected resource metadata URL', form.remote.resource_metadata_url, v => remote('resource_metadata_url', v), 'url', {helperText:'Optional. Discovered automatically when supported.'}),field('OAuth client secret',secret,setSecret,'password',{autoComplete:'new-password',helperText:'Blank keeps the saved secret.'})))),
            form.remote.auth_mode==='shared' && h('div',{className:'space-y-3'},h(Select,{label:'Credential type',value:sharedKind,onChange:e=>{setSharedKind(e.target.value);setSecret('');},options:[{value:'bearer',label:'Bearer token'},{value:'basic',label:'Basic authentication'},{value:'api-key',label:'API key header'}]}),sharedKind==='api-key'&&field('API key header name',sharedName,setSharedName),sharedKind==='basic'&&field('Username',sharedUser,setSharedUser),field(sharedKind==='basic'?'Password':'Token or API key',secret,setSecret,'password',{autoComplete:'new-password',helperText:'Blank keeps the saved credential.'})),
            h('fieldset',null,h('legend',null,'Custom headers'),h('p',{className:'text-sm'},'Headers are encrypted. Saved values stay hidden; a blank value keeps the existing header. Remove a row to delete its saved header.'),headers.map((row,i)=>h('div',{key:i,className:'grid grid-cols-3 gap-2 my-2'},field('Header '+(i+1)+' name',row.name,value=>setHeaders(headers.map((r,j)=>j===i?{...r,name:value}:r))),field('Header '+(i+1)+' value',row.value,value=>setHeaders(headers.map((r,j)=>j===i?{...r,value}:r)),'password',{autoComplete:'new-password'}),button('Remove header '+(i+1),()=>setHeaders(headers.filter((_,j)=>j!==i))))),button('Add custom header',()=>setHeaders([...headers,{name:'',value:''}]))),
            checkbox('Explicitly publish to the original /mcp gateway', !!form.remote.publish_original, v => remote('publish_original', v)))),
          h('div', {className: 'flex flex-wrap items-center gap-3'}, h(Button, {variant:'primary', loading:busy, disabled:!form.name.trim(), onClick:() => action(save)}, 'Save'),
            form.id && button(form.kind === 'gateway' ? 'Preview capabilities' : 'Test & discover capabilities', async () => { setPreview(await api('connections/' + form.id + '/preview')); await load(); addToast('Capability discovery completed.','success'); }),
            button('Close', () => { setForm(null); setPreview(null); }), form.id && button('Delete', () => setConfirm({kind:'delete',item:form}), 'danger')),
          preview && h(Card, {title:'Available capabilities'}, h('div',{className:'space-y-6'}, ...previewSections.map(([method,field,label]) => {
            const entries = preview[method]?.[field] || [];
            return h('section',{key:method,'aria-label':label}, h('h3',{className:'text-base font-semibold text-gray-900'}, `${label} (${entries.length})`),
              preview[method]?.nextCursor && h('p',{className:'text-sm text-gray-500'},'Showing the first page. MCP clients can retrieve subsequent pages.'),
              !entries.length ? h('p',{className:'text-sm text-gray-500'},'None available.') : h('ul',{className:'max-h-64 overflow-y-auto rounded border'}, ...entries.map((entry,i) => h('li',{key:i,className:'px-3 py-2 border-b border-gray-200'},
                h('p',{className:'text-sm font-medium text-gray-900 break-all'},entry.name || entry.uri || entry.uriTemplate), entry.description && h('p',{className:'text-sm text-gray-500'},entry.description)))))
          }))))
        : h(React.Fragment, null,
          h('div',{className:'flex flex-wrap gap-3'}, ...[['gateway','Gateways'],['remote-mcp','Remote MCP']].map(([value,label]) => h(Button,{key:value,variant:kind === value ? 'primary':'outline','aria-pressed':kind === value,onClick:() => { setKind(value); setQuery(''); }},label))),
          field('Search connections',query,setQuery,'search'),
          !selected.length && h(Card,null,h(EmptyState,{title:query ? 'No matching connections' : kind === 'gateway' ? 'No project gateways yet':'No remote connections yet',description:'Create an endpoint with its own servers and allowed users.'})),
          ...selected.map(item => h(Card,{key:item.id,title:item.name,actions:h(Badge,{variant:item.status === 'active' ? 'success':'neutral',dot:true},words(item.status))}, h('div',{className:'space-y-4'},
            item.kind === 'remote-mcp' && h('p',{className:'text-sm text-gray-500'},'Your connection: ' + words(item.connection_status?.state || 'not_tested')),
            h('code',{className:'block font-mono text-sm text-gray-700 break-all'},item.url), h('div',{className:'flex flex-wrap gap-3'},h(CopyButton,{text:item.url,label:'Copy URL'}),button('Edit',() => edit(item)))))))),
      h(ConfirmDialog,{open:!!confirm,title:confirm?.kind === 'delete' ? 'Delete this endpoint?':'Disconnect your account?',
        message:confirm?.kind === 'delete' ? 'This endpoint and its upstream account connections will be removed.':'GetMCP will remove your saved upstream credentials. You can connect again later.', confirmLabel:confirm?.kind === 'delete' ? 'Delete endpoint':'Disconnect',loading:busy,
        onClose:() => { if (!busy) setConfirm(null); }, onConfirm:() => action(async () => {
          await api((confirm.kind === 'delete' ? 'connections/':'my-connections/') + confirm.item.id,{method:'DELETE'});
          if (confirm.kind === 'delete') { setForm(null); setPreview(null); }
          setConfirm(null); await load(); addToast(confirm.kind === 'delete' ? 'Endpoint deleted.':'Account disconnected.','success');
        })}));
  };
};
