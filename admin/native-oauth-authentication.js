/* Restore the add-on's native WordPress sign-in option on the GetMCP 1.7
 * Authentication screen. GetMCP 1.7 removed this choice from its bundled UI;
 * the server-side OAuth provider and access checks remain available. */
(function () {
  'use strict';

  const api = window.wp && window.wp.apiFetch;
  if (!api) return;

  const parseConfig = value => {
    if (value && typeof value === 'object') return value;
    try { return JSON.parse(value || '{}') || {}; } catch (_) { return {}; }
  };
  const request = (path, options) => api(Object.assign({path}, options || {}));
  let mounted = '';
  let nativeSignIn = false;

  function clientAuthCard(form) {
    const heading = Array.from(form.querySelectorAll('h1,h2,h3,h4,[role="heading"]'))
      .find(node => node.textContent.trim() === 'Client Authentication');
    if (!heading) return null;
    let node = heading;
    while (node.parentElement && node.parentElement !== form) node = node.parentElement;
    return node.parentElement === form ? node : heading.parentElement;
  }

  function syncNativeOAuthSettings(form) {
    const card = clientAuthCard(form);
    if (!card) return;
    const selector = card.querySelector('[role="combobox"]');
    const currentMode = selector ? selector.textContent.trim() : '';
    const oauthDescription = Array.from(card.querySelectorAll('p'))
      .find(node => node.textContent.trim().startsWith('Each MCP user signs into their own upstream account'));
    const oauthFields = oauthDescription && oauthDescription.parentElement;
    let hint = card.querySelector('[data-getmcp-native-oauth-hint]');
    const nativeOAuthSelected = nativeSignIn && /OAuth\s*2\.0/i.test(currentMode);
    if (!nativeOAuthSelected) {
      if (oauthFields && oauthFields.hidden) oauthFields.hidden = false;
      if (hint) hint.remove();
      return;
    }
    if (oauthFields) {
      if (!hint) {
        hint = document.createElement('p');
        hint.dataset.getmcpNativeOauthHint = '1';
        hint.className = 'mt-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800';
        hint.textContent = 'WordPress sign-in is handled by GetMCP for this server. These external identity-provider fields are not needed; manage allowed users in the WordPress sign-in panel above.';
        oauthFields.parentElement.insertBefore(hint, oauthFields);
      }
      if (!oauthFields.hidden) oauthFields.hidden = true;
    }
  }

  function mount() {
    const match = (window.location.hash || '').match(/^#\/servers\/([0-9a-f-]+)\/auth(?:[/?]|$)/i);
    const panel = document.querySelector('#tabpanel-auth');
    const form = panel && panel.querySelector('form');
    if (!match || !panel || !form) return;
    const serverId = match[1];
    if (mounted === serverId && panel.querySelector('[data-getmcp-native-oauth]')) {
      syncNativeOAuthSettings(form);
      return;
    }
    const stale = panel.querySelector('[data-getmcp-native-oauth]');
    if (stale) stale.remove();
    form.hidden = false;
    mounted = serverId;
    nativeSignIn = false;

    const card = document.createElement('section');
    card.dataset.getmcpNativeOauth = '1';
    card.className = 'mb-5 rounded-lg border border-blue-200 bg-blue-50 p-4';
    card.innerHTML = '<h3 class="text-sm font-semibold text-gray-900">WordPress sign-in (GetMCP)</h3>' +
      '<p class="mt-1 text-sm text-gray-600">Require users to sign in with their WordPress account before they can use this server. Access is limited to the users you select; administrators are not automatically allowed.</p>' +
      '<div data-native-oauth-content class="mt-3"><p class="text-sm text-gray-500">Loading server authentication…</p></div>';
    form.parentNode.insertBefore(card, form);

    const content = card.querySelector('[data-native-oauth-content]');
    const el = (tag, text, cls) => { const node = document.createElement(tag); if (text !== undefined) node.textContent = text; if (cls) node.className = cls; return node; };
    const button = (label, cls) => { const node = el('button', label, 'button ' + (cls || '')); node.type = 'button'; return node; };
    const showError = message => { let node = content.querySelector('[data-error]'); if (!node) { node = el('p', '', 'mt-3 text-sm text-red-700'); node.dataset.error = '1'; content.appendChild(node); } node.textContent = message; };

    request('/getmcp/v1/servers/' + encodeURIComponent(serverId)).then(server => {
      const config = parseConfig(server.auth_config);
      let allowed = Array.isArray(config.allowed_user_ids) ? config.allowed_user_ids.map(Number).filter(id => Number.isInteger(id) && id > 0) : [];
      const native = server.auth_type === 'oauth' && config.provider === 'getmcp';
      nativeSignIn = native;
      // Keep the complete GetMCP Authentication form visible. It contains
      // native client-authentication choices and the separate server-stored
      // outbound credential fields; hiding its OAuth card made the add-on
      // look as if it had replaced part of GetMCP's own settings.
      form.hidden = false;
      content.replaceChildren();

      const status = el('p', native ? 'WordPress sign-in is active for this server. GetMCP’s full Authentication settings remain available below.' : 'Enable WordPress sign-in for this server. This replaces any external OAuth provider configured in Client Authentication; its client credentials will be cleared. GetMCP’s full Authentication settings remain available below.', 'text-sm ' + (native ? 'text-green-700' : 'text-gray-600'));
      content.appendChild(status);

      const selected = el('div', undefined, 'mt-3 flex flex-wrap gap-2');
      const searchLabel = el('label', 'Search WordPress users', 'mt-3 block text-sm font-medium text-gray-700');
      const search = el('input', undefined, 'mt-1 w-full rounded border border-gray-300 px-3 py-2');
      search.type = 'search'; search.maxLength = 100; search.placeholder = 'Search by name or username…';
      const results = el('div', undefined, 'mt-2 max-h-56 space-y-1 overflow-y-auto');
      const note = el('p', 'An empty list denies everyone, including administrators.', 'mt-2 text-xs text-gray-500');
      const actions = el('div', undefined, 'mt-3 flex flex-wrap gap-2');
      const save = button(native ? 'Save allowed users' : 'Enable WordPress sign-in', 'button-primary');
      const viewNative = button('View GetMCP authentication settings');
      viewNative.addEventListener('click', () => form.scrollIntoView({behavior: 'smooth', block: 'start'}));
      actions.appendChild(viewNative);
      actions.appendChild(save);
      content.append(searchLabel, search, selected, results, note, actions);
      syncNativeOAuthSettings(form);

      const chosen = new Map();
      if (allowed.length) {
        request('/getmcp/v1/oauth-users?include=' + encodeURIComponent(allowed.join(',')))
          .then(data => { (data.users || []).forEach(user => chosen.set(Number(user.id), user)); drawSelected(); })
          .catch(() => {});
      }
      let searchTimer;
      let searchSeq = 0;
      const drawSelected = () => {
        selected.replaceChildren();
        if (!allowed.length) { selected.appendChild(el('span', 'No users selected', 'text-sm text-gray-500')); return; }
        allowed.forEach(id => {
          const entry = chosen.get(id);
          const chip = el('span', entry ? `${entry.name} (${entry.login})` : `User #${id}`, 'inline-flex items-center gap-2 rounded border bg-white px-2 py-1 text-sm');
          const remove = button('×'); remove.setAttribute('aria-label', 'Remove user ' + id);
          remove.addEventListener('click', () => { allowed = allowed.filter(value => value !== id); drawSelected(); });
          chip.appendChild(remove); selected.appendChild(chip);
        });
      };
      drawSelected();

      const searchUsers = async term => {
        const seq = ++searchSeq;
        results.replaceChildren();
        if (term.trim().length < 2) return;
        results.appendChild(el('p', 'Searching…', 'text-sm text-gray-500'));
        try {
          const data = await request('/getmcp/v1/oauth-users?search=' + encodeURIComponent(term.trim()) + '&page=1&per_page=20');
          if (seq !== searchSeq) return;
          results.replaceChildren();
          (data.users || []).forEach(user => {
            const row = el('div', undefined, 'flex items-center justify-between gap-3 rounded border bg-white px-3 py-2');
            row.appendChild(el('span', `${user.name} (${user.login})`, 'text-sm'));
            const add = button(allowed.includes(Number(user.id)) ? 'Added' : 'Add', 'button-secondary');
            add.disabled = !user.eligible || allowed.includes(Number(user.id));
            add.addEventListener('click', () => { const id = Number(user.id); if (!allowed.includes(id)) { allowed.push(id); chosen.set(id, user); drawSelected(); searchUsers(search.value); } });
            row.appendChild(add); results.appendChild(row);
          });
          if (!(data.users || []).length) results.appendChild(el('p', 'No matching eligible users.', 'text-sm text-gray-500'));
        } catch (_) { if (seq === searchSeq) { results.replaceChildren(); results.appendChild(el('p', 'Could not search WordPress users. Check GetMCP server permissions and try again.', 'text-sm text-red-700')); } }
      };
      search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => searchUsers(search.value), 250); });

      save.addEventListener('click', async () => {
        save.disabled = true;
        try {
          if (!native && config.provider && config.provider !== 'getmcp' && (config.client_id || config.authorize_url || config.token_url)) {
            if (!window.confirm('Switch this server from its external OAuth provider to WordPress sign-in? The external OAuth configuration and saved client secret will be removed.')) { save.disabled = false; return; }
          }
          await request('/getmcp/v1/servers/' + encodeURIComponent(serverId), {
            method: 'PATCH',
            data: {auth_type: 'oauth', auth_config: {provider: 'getmcp', allowed_user_ids: allowed}}
          });
          window.location.reload();
        } catch (error) {
          showError(error && error.message ? error.message : 'Could not save WordPress sign-in settings.');
          save.disabled = false;
        }
      });

    }).catch(error => {
      content.replaceChildren();
      showError(error && error.message ? error.message : 'Could not load server authentication settings.');
    });
  }

  const observer = new MutationObserver(mount);
  observer.observe(document.documentElement, {childList: true, subtree: true});
  window.addEventListener('hashchange', () => { mounted = ''; mount(); });
  mount();
})();
