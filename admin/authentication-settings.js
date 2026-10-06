/** Readable advanced Authentication editor. Saved secrets never enter this component. */
window.createGetMCPAuthenticationAdvanced = function(React) {
  const {createElement: h, useState, useEffect} = React;
  const request = (path, options = {}) => wp.apiFetch({path: '/getmcp/v1/' + path, ...options});
  const Picker = window.createOAuthUserPicker(React, path => request(path));
  return function AuthenticationAdvanced({config, server, onChange}) {
    const parse = value => { try { return typeof value === 'string' ? JSON.parse(value) : value || {}; } catch (_) { return {}; } };
    const [rows, setRows] = useState([]), [clear, setClear] = useState([]), [replace, setReplace] = useState(false);
    const [personal, setPersonal] = useState(null), [notice, setNotice] = useState(''), [busy, setBusy] = useState(false);
    useEffect(() => {
      let live = true;
      if (!server?.uuid || !parse(config).token_url) { setPersonal(null); return; }
      request(`servers/${server.uuid}/provider-configuration`).then(data => { if (live) setPersonal(data); }).catch(() => { if (live) setPersonal(null); });
      return () => { live = false; };
    }, [server?.uuid, config]);
    const savePersonal = async () => {
      setBusy(true); setNotice('');
      try { setPersonal(await request(`servers/${server.uuid}/provider-configuration`, {method:'PUT', data:{configuration_revision:personal.configuration_revision, personal_provider:personal.personal_provider}})); setNotice('Personal access saved.'); }
      catch (error) { setNotice(error.message || 'Reload before saving personal access.'); }
      finally { setBusy(false); }
    };
    useEffect(() => { setRows(Object.entries(parse(config).extra_authorize_params || {}).map(([name, value]) => ({name, value: String(value)}))); setClear([]); setReplace(false); }, [config]);
    const update = (nextRows, nextClear = clear, nextReplace = replace) => {
      setRows(nextRows); setClear(nextClear); setReplace(nextReplace);
      const params = Object.fromEntries(Object.keys(parse(config).extra_authorize_params || {}).map(k => [k, null]));
      Object.assign(params, Object.fromEntries(nextRows.filter(r => r.name.trim()).map(r => [r.name.trim(), r.value])));
      onChange({params, clear: nextClear, replace: nextReplace});
    };
    return h('details', {className: 'rounded border p-4'}, h('summary', {className: 'cursor-pointer font-medium'}, 'Advanced authentication settings'),
      h('p', {className: 'text-sm my-3'}, 'Omitted settings and blank secret fields keep their saved values. Changing provider or application identity clears obsolete credentials.'),
      personal && h('fieldset', {className:'my-4'}, h('legend', null, 'Personal provider connections'),
        h('p', null, 'Only selected WordPress users can connect their own accounts directly. An empty list denies everyone. Gateway membership can also grant access.'),
        h(Picker, {value:personal.personal_provider.allowed_user_ids || [], onChange:ids=>setPersonal({...personal,personal_provider:{...personal.personal_provider,allowed_user_ids:ids}})}),
        h('button', {type:'button',disabled:busy,onClick:savePersonal,className:'rounded border px-3 py-2'}, busy ? 'Saving…' : 'Save personal access'),
        notice && h('p',{role:'status'},notice)),
      h('fieldset', null, h('legend', null, 'Additional authorization parameters'),
        rows.map((row, i) => h('div', {key: i, className:'flex gap-2 my-2'},
          h('input', {className:'rounded border p-2', 'aria-label':`Authorization parameter ${i+1} name`, value:row.name, onChange:e=>update(rows.map((r,j)=>j===i?{...r,name:e.target.value}:r))}),
          h('input', {className:'rounded border p-2', 'aria-label':`Authorization parameter ${i+1} value`, value:row.value, onChange:e=>update(rows.map((r,j)=>j===i?{...r,value:e.target.value}:r))}),
          h('button', {type:'button', onClick:()=>update(rows.filter((_,j)=>j!==i))}, 'Remove parameter'))),
        h('button', {type:'button', className:'rounded border px-3 py-2', onClick:()=>update([...rows,{name:'',value:''}])}, 'Add authorization parameter')),
      h('label', {className:'block my-3'}, h('input', {type:'checkbox', checked:replace, onChange:e=>update(rows,clear,e.target.checked)}), ' Replace advanced configuration on this save (omitted fields will be removed)'),
      h('fieldset', null, h('legend', null, 'Explicitly clear saved credentials'),
        [['auth_credentials','OAuth application secret / client credential'],['outbound_auth_credentials','Server-stored API credential'],['test_auth_credentials','Test credential']].map(([key,label])=>h('label',{key,className:'block my-2'},h('input',{type:'checkbox',checked:clear.includes(key),onChange:e=>update(rows,e.target.checked?[...clear,key]:clear.filter(k=>k!==key))}),' Clear ',label))));
  };
};
