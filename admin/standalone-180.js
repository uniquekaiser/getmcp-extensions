/** Standalone Extensions screens for core 1.8; no private webpack module IDs. */
(function (window, document) {
  'use strict';

  function apiPath(path, params) {
    const parts = path.split('?');
    const route = parts.shift();
    const query = new URLSearchParams(parts.join('?'));
    Object.entries(params || {}).forEach(([key, value]) => {
      if (value !== undefined && value !== null) query.set(key, String(value));
    });
    const mapped = route === 'oauth-users' ? 'allowed-users/search' : route;
    return '/getmcp/v1/' + mapped + (query.toString() ? '?' + query.toString() : '');
  }
  window.getmcpExtensions180ApiPath = apiPath;

  function mount() {
    const target = document.getElementById('getmcp-extensions-180');
    if (!target) return;
    const config = window.getmcpExtensions180;
    const wp = window.wp;
    if (!config || !wp?.element || !wp?.apiFetch) {
      target.textContent = 'Could not load the connection screen. Reload this page to try again.';
      return;
    }
    const React = wp.element, h = React.createElement;
    if (wp.apiFetch.createNonceMiddleware) wp.apiFetch.use(wp.apiFetch.createNonceMiddleware(config.nonce));
    const api = (path, options = {}) => {
      const {params, ...request} = options;
      return wp.apiFetch({...request, path: apiPath(path, params)});
    };
    const announce = (message, kind) => {
      const node = document.getElementById('getmcp-extensions-180-notice');
      if (node) { node.textContent = message; node.dataset.kind = kind || ''; }
    };
    const useToast = () => ({addToast: announce});
    const Button = ({children, variant, loading, className = '', type = 'button', ...props}) =>
      h('button', {...props, type, disabled: props.disabled || loading, className: `button ${variant === 'primary' ? 'button-primary' : ''} ${className}`}, children);
    const Card = ({children, title, subtitle, actions}) => h('section', {className: 'gme180-card'},
      (title || actions) && h('div', {className: 'gme180-card-heading'}, title && h('h2', null, title), actions),
      subtitle && h('p', null, subtitle), children);
    const Badge = ({children}) => h('span', {className: 'gme180-badge'}, children);
    const Input = ({label, helperText, className = '', ...props}) => h('label', {className: 'gme180-field'},
      label && h('span', null, label), h('input', {...props, className}), helperText && h('small', null, helperText));
    const Select = ({label, options = [], helperText, ...props}) => h('label', {className: 'gme180-field'},
      label && h('span', null, label), h('select', props, ...options.map(option => h('option', {key: option.value, value: option.value}, option.label))), helperText && h('small', null, helperText));
    const CopyButton = ({text, label = 'Copy'}) => h(Button, {onClick: async () => {
      try { await navigator.clipboard.writeText(text); announce('Copied.', 'success'); }
      catch (_) { announce('Could not copy. Select the text and copy it manually.', 'error'); }
    }}, label);
    const PageHeader = ({title, description, actions}) => h('header', {className: 'gme180-heading'},
      h('div', null, h('h1', null, title), description && h('p', null, description)), actions && h('div', null, actions));
    const PageContainer = ({children}) => h('main', {className: 'gme180-page'}, children);
    const ConfirmDialog = ({open, title, message, confirmLabel, loading, onClose, onConfirm}) => open &&
      h('div', {className: 'gme180-dialog-backdrop'}, h('div', {className: 'gme180-dialog', role: 'dialog', 'aria-modal': true, 'aria-label': title},
        h('h2', null, title), h('p', null, message), h(Button, {disabled: loading, onClick: onClose}, 'Cancel'), ' ',
        h(Button, {variant: 'primary', loading, onClick: onConfirm}, confirmLabel || 'Confirm')));
    const EmptyState = ({title, description}) => h('div', null, h('h3', null, title), h('p', null, description));
    const Skeleton = () => h('p', {role: 'status'}, 'Loading…');
    const UI = {Button, Card, Badge, Input, Select, CopyButton, PageHeader, PageContainer, ConfirmDialog, EmptyState, Skeleton};
    const Picker = window.createOAuthUserPicker(React, api);
    function ProviderAccess() {
      const [servers, setServers] = React.useState([]), [page, setPage] = React.useState(1), [more, setMore] = React.useState(false);
      const [selected, setSelected] = React.useState(''), [data, setData] = React.useState(null), [sources, setSources] = React.useState([]);
      const [dirty, setDirty] = React.useState(false), [busy, setBusy] = React.useState(false), [error, setError] = React.useState('');
      const [publicServer, setPublicServer] = React.useState(''), [publicPolicy, setPublicPolicy] = React.useState(null), [publicBusy, setPublicBusy] = React.useState(false);
      React.useEffect(() => {
        let active = true;
        api('servers', {params: {page, per_page: 50}}).then(result => {
          if (!active) return;
          const rows = Array.isArray(result) ? result : (result.items || []);
          setServers(old => page === 1 ? rows : [...old, ...rows.filter(row => !old.some(previous => previous.uuid === row.uuid))]);
          setMore(rows.length === 50);
        }).catch(e => { if (active) setError(e.message || 'Could not load servers.'); });
        return () => { active = false; };
      }, [page]);
      React.useEffect(() => {
        let active = true;
        setData(null); setSources([]); setDirty(false); setError('');
        if (selected) api('servers/' + encodeURIComponent(selected) + '/provider-configuration').then(value => {
          if (!active) return;
          setData(value); setSources(Object.entries(value.token_sources || {}).map(([slug, source]) => ({slug, source})));
        }).catch(e => { if (active) setError(e.message || 'Could not load provider settings.'); });
        return () => { active = false; };
      }, [selected]);
      React.useEffect(() => {
        let active = true; setPublicPolicy(null);
        if (publicServer) wp.apiFetch({path: '/getmcp-extensions/v1/servers/' + encodeURIComponent(publicServer) + '/public-upstream'}).then(value => { if (active) setPublicPolicy(value); }).catch(e => { if (active) setError(e.message || 'Could not load public upstream settings.'); });
        return () => { active = false; };
      }, [publicServer]);
      const savePublic = async () => {
        setPublicBusy(true); setError('');
        try {
          const value = await wp.apiFetch({path: '/getmcp-extensions/v1/servers/' + encodeURIComponent(publicServer) + '/public-upstream', method: 'PUT', data: publicPolicy});
          setPublicPolicy(value); announce('Public upstream settings saved.', 'success');
        } catch (e) { setError(e.message || 'Could not save public upstream settings.'); }
        finally { setPublicBusy(false); }
      };
      const save = async () => {
        setBusy(true); setError('');
        try {
          const payload = {configuration_revision: data.configuration_revision, personal_provider: data.personal_provider || {allowed_user_ids: []}};
          if (dirty) {
            if (sources.some(row => !/^[a-zA-Z0-9_-]+$/.test(row.slug)) || new Set(sources.map(row => row.slug)).size !== sources.length) throw new Error('Each tool needs a unique valid slug.');
            payload.token_sources = Object.fromEntries(sources.map(row => [row.slug, row.source]));
          }
          const saved = await api('servers/' + encodeURIComponent(selected) + '/provider-configuration', {method: 'PUT', data: payload});
          setData(saved); setSources(Object.entries(saved.token_sources || {}).map(([slug, source]) => ({slug, source}))); setDirty(false);
          announce('Provider access saved.', 'success');
        } catch (e) { setError(e.message || 'Could not save provider access.'); }
        finally { setBusy(false); }
      };
      const eligible = servers.filter(server => server.auth_type === 'oauth' && server.uuid);
      return h(PageContainer, null,
        h(PageHeader, {title: 'Personal provider access', description: 'Choose who may connect a personal account and which tools use a selected Page token.'}),
        error && h('p', {role: 'alert'}, error),
        h(Card, {title: 'Provider server'},
          h(Select, {label: 'OAuth server', value: selected, onChange: e => setSelected(e.target.value), options: [{value: '', label: 'Choose a server'}, ...eligible.map(server => ({value: server.uuid, label: server.name}))]}),
          more && h(Button, {onClick: () => setPage(page + 1)}, 'Load more servers'),
          h('p', null, 'Configure the OAuth application in the native server editor. Saved credentials are never shown here.')),
        data && h(Card, {title: 'Allowed WordPress users'},
          h('p', null, 'Eligible users may connect their own account when selected below, permitted through a project gateway, or when access for all users is enabled.'),
          h('label', {className:'block my-2'}, h('input', {type:'checkbox', checked:data.personal_provider?.allow_all_users === true, onChange:e => setData({...data, personal_provider:{...data.personal_provider, allow_all_users:e.target.checked}})}), ' Allow all eligible users to connect their own account'),
          h(Picker, {value: data.personal_provider?.allowed_user_ids || [], onChange: ids => setData({...data, personal_provider: {...data.personal_provider, allowed_user_ids: ids}})})),
        data && h(Card, {title: 'Provider token sources'},
          h('p', null, 'Use a Page token only for a reviewed tool that requires one.'),
          ...sources.map((row, index) => h('div', {key: index},
            h(Input, {label: 'Tool slug ' + (index + 1), value: row.slug, onChange: e => { setSources(sources.map((item, i) => i === index ? {...item, slug: e.target.value} : item)); setDirty(true); }}),
            h(Select, {label: 'Token source ' + (index + 1), value: row.source, options: [{value: 'user', label: 'User'}, {value: 'page', label: 'Selected Page'}], onChange: e => { setSources(sources.map((item, i) => i === index ? {...item, source: e.target.value} : item)); setDirty(true); }}),
            h(Button, {onClick: () => { setSources(sources.filter((_, i) => i !== index)); setDirty(true); }}, 'Remove token source'))),
          h(Button, {onClick: () => { setSources([...sources, {slug: '', source: 'user'}]); setDirty(true); }}, 'Add token source')),
        data && h(Button, {variant: 'primary', loading: busy, onClick: save}, 'Save provider access'),
        h(Card, {title: 'Public read-only upstream'},
          h('p', null, 'For an API that is publicly readable without credentials, allow existing project Gateway members to use its GET tools without forwarding Gateway credentials. Every tool must use the same HTTPS origin.'),
          h(Select, {label: 'API-key server', value: publicServer, onChange: e => setPublicServer(e.target.value), options: [{value: '', label: 'Choose a server'}, ...servers.filter(server => server.auth_type === 'api-key' && server.id).map(server => ({value: String(server.id), label: server.name}))]}),
          publicPolicy && h(React.Fragment, null,
            h(Input, {label: 'Public HTTPS origin', value: publicPolicy.origin, placeholder: 'https://api.example.com', onChange: e => setPublicPolicy({...publicPolicy, origin: e.target.value}), helperText: 'Leave empty to disable this access. Existing server sign-in and project permissions stay in effect.'}),
            h(Button, {variant: 'primary', loading: publicBusy, onClick: savePublic}, 'Save public upstream'))));
    }
    window.getmcpExtensions = config.extensions;
    window.getmcpRemoteCatalogue = config.catalogue;
    const tools = window.createGetMCPConnectionTools({React, api});
    window.GetMCPExtensionsCodexConnect = tools.CodexConnect;
    window.GetMCPExtensionsProjectGateways = tools.ProjectGateways;
    let Page;
    if (config.page === 'settings') {
      Page = window.createGetMCPExtensionsPage({React, UI, useToast});
    } else if (config.page === 'providers') {
      Page = ProviderAccess;
    } else {
      const Connections = window.createGetMCPConnectionsPage({React, UI, api, useToast});
      Page = () => h(Connections, {portal: config.page === 'portal', catalogue: config.page === 'catalogue'});
    }
    if (wp.element.createRoot) wp.element.createRoot(target).render(h(Page));
    else wp.element.render(h(Page), target);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, {once: true});
  else mount();
})(window, document);
