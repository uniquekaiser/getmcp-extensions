// Shared React component factory. Inject React and GetMCP's authenticated API helper.
// Kept readable because the captured vendor package contains no original JSX sources.
function createOAuthUserPicker(React, api) {
  const h = React.createElement;
  return function OAuthUserPicker({value = [], onChange}) {
    const [query, setQuery] = React.useState('');
    const [page, setPage] = React.useState(1);
    const [results, setResults] = React.useState([]);
    const [more, setMore] = React.useState(false);
    const [loading, setLoading] = React.useState(false);
    const [error, setError] = React.useState('');
    const [labels, setLabels] = React.useState({});
    const [lookupError, setLookupError] = React.useState('');
    const [retry, setRetry] = React.useState(0);
    const idsKey = value.join(',');

    React.useEffect(() => {
      let active = true;
      const term = query.trim();
      if (term.length < 2) {
        setResults([]); setMore(false); setLoading(false); setError('');
        return () => { active = false; };
      }
      setLoading(true); setError('');
      const timer = setTimeout(async () => {
        try {
          const data = await api(`oauth-users?search=${encodeURIComponent(term)}&page=${page}&per_page=20`);
          if (active) {
            setResults(data.users); setMore(data.has_more);
            setLabels(old => Object.assign({}, old, Object.fromEntries(data.users.map(u => [u.id, u]))));
          }
        } catch (_) {
          if (active) { setResults([]); setMore(false); setError('Could not search users. Try again.'); }
        } finally { if (active) setLoading(false); }
      }, 300);
      return () => { active = false; clearTimeout(timer); };
    }, [query, page, retry]);

    React.useEffect(() => {
      let active = true;
      setLookupError('');
      // Resolve only selected IDs, in bounded batches. Never download the directory.
      const ids = value.filter(id => !labels[id]);
      (async () => {
        try {
          const found = {};
          for (let i = 0; i < ids.length; i += 100) {
            const data = await api(`oauth-users?include=${encodeURIComponent(ids.slice(i, i + 100).join(','))}`);
            data.users.forEach(u => { found[u.id] = u; });
            if (!active) return;
          }
          if (active) setLabels(old => Object.assign({}, old, found));
        } catch (_) { if (active) setLookupError('Could not load selected user names. IDs are preserved. Retry to reload.'); }
      })();
      return () => { active = false; };
    }, [idsKey, retry]);

    const name = id => labels[id] ? `${labels[id].name} (${labels[id].login})` : `User #${id}`;
    return h('fieldset', {className: 'space-y-3'},
      h('legend', {className: 'font-medium'}, 'Allowed WordPress users'),
      h('p', {className: 'text-sm text-gray-500'}, 'Only selected users can authorize clients or use tokens for this server. Admins are also restricted. An empty list denies everyone.'),
      h('div', {className: 'flex flex-wrap gap-2 max-h-48 overflow-y-auto', 'aria-label': 'Selected WordPress users'},
        value.map(id => h('span', {key: id, className: 'inline-flex items-center gap-2 rounded border px-2 py-1'},
          h('span', null, name(id)),
          labels[id] && !labels[id].eligible ? h('span', null, 'Account disabled') : null,
          h('button', {type: 'button', 'aria-label': `Remove ${name(id)}`, onClick: () => onChange(value.filter(x => x !== id))}, '×'))),
        !value.length ? h('span', {className: 'text-sm text-gray-500'}, 'No users selected') : null),
      h('label', {className: 'block'},
        h('span', {className: 'block text-sm font-medium'}, 'Search WordPress users'),
        h('input', {type: 'search', value: query, maxLength: 100, placeholder: 'Search by name or username…', className: 'mt-1 w-full rounded border px-3 py-2',
          onChange: event => { setQuery(event.target.value); setPage(1); setResults([]); setMore(false); }})),
      h('div', {role: 'status', 'aria-live': 'polite', className: 'text-sm text-gray-500'},
        loading ? 'Searching…' : query.trim().length < 2 ? 'Type at least 2 characters to search.' : !error && !results.length ? 'No matching users.' : `Page ${page}`),
      error || lookupError ? h('div', {role: 'alert'}, error || lookupError,
        h('button', {type: 'button', className: 'ml-2 underline', onClick: () => setRetry(x => x + 1)}, 'Retry')) : null,
      !loading && results.length ? h('ul', {className: 'max-h-64 overflow-y-auto rounded border', 'aria-label': 'Matching WordPress users'},
        results.map(user => h('li', {key: user.id, className: 'flex items-center justify-between gap-2 px-3 py-2'},
          h('span', null, `${user.name} (${user.login})`),
          h('button', {type: 'button', disabled: !user.eligible || value.includes(user.id),
            'aria-label': `Add ${user.name} (${user.login})`,
            onClick: () => { if (!value.includes(user.id)) onChange([...value, user.id]); },
            className: 'rounded border px-2 py-1'}, value.includes(user.id) ? 'Selected' : !user.eligible ? 'Unavailable' : 'Add')))) : null,
      h('div', {className: 'flex gap-3'},
        page > 1 ? h('button', {type: 'button', disabled: loading, onClick: () => setPage(p => p - 1)}, 'Previous results') : null,
        more ? h('button', {type: 'button', disabled: loading, onClick: () => setPage(p => p + 1)}, 'Next results') : null));
  };
}
