/* Add the add-on's WordPress sign-in mode to GetMCP 1.7's Client Authentication
 * selector and render its per-user allowlist controls in that same card. */
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
  let loading = '';
  let nativeSignIn = false;
  const submitBound = new WeakSet();
  const modeLabel = 'WordPress sign-in (GetMCP)';

  // GetMCP's custom select caps every option menu at 108px. That shows only
  // three rows and hides the newly added sign-in choice below the scroll.
  // Expand only this authentication menu, while respecting the available
  // viewport space when it opens near the top or bottom of the screen.
  function expandNativeAuthMenu() {
    Array.from(document.querySelectorAll('ul[role="listbox"]')).forEach(menu => {
      const hasNativeOption = Array.from(menu.querySelectorAll('[role="option"]'))
        .some(option => option.textContent.trim() === modeLabel);
      if (!hasNativeOption) return;

      const popup = menu.parentElement && menu.parentElement.parentElement;
      if (!popup) return;
      const rect = popup.getBoundingClientRect();
      const space = popup.style.bottom
        ? rect.top - 4
        : window.innerHeight - rect.top - 4;
      const height = Math.max(0, Math.min(240, space));
      const value = height + 'px';
      if (menu.style.maxHeight !== value) menu.style.maxHeight = value;
    });
  }

  let menuAdjustmentQueued = false;
  function scheduleNativeMenuExpansion() {
    if (menuAdjustmentQueued) return;
    menuAdjustmentQueued = true;
    window.requestAnimationFrame(() => {
      menuAdjustmentQueued = false;
      expandNativeAuthMenu();
    });
  }

  function clientAuthCard(form) {
    const heading = Array.from(form.querySelectorAll('h1,h2,h3,h4,[role="heading"]'))
      .find(node => node.textContent.trim() === 'Client Authentication');
    if (!heading) return null;
    let node = heading;
    while (node.parentElement && node.parentElement !== form) node = node.parentElement;
    return node.parentElement === form ? node : heading.parentElement;
  }

  function isWordPressMode(form) {
    const card = clientAuthCard(form);
    if (!card) return false;
    const selector = card.querySelector('[role="combobox"]');
    return !!selector && selector.textContent.includes(modeLabel);
  }

  function syncNativeOAuthSettings(form) {
    const card = clientAuthCard(form);
    if (!card) return;
    const controls = card.querySelector('[data-getmcp-native-oauth]');
    if (controls) controls.hidden = !isWordPressMode(form);
    const save = form.querySelector('button[type="submit"]');
    if (save) save.hidden = isWordPressMode(form);
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
    if (loading === serverId) return;
    const stale = panel.querySelector('[data-getmcp-native-oauth]');
    if (stale) stale.remove();
    form.hidden = false;
    mounted = serverId;
    nativeSignIn = false;
    loading = serverId;

    const card = document.createElement('section');
    card.dataset.getmcpNativeOauth = '1';
    card.className = 'mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4';
    card.innerHTML = '<h4 class="text-sm font-semibold text-gray-900">Allowed WordPress users</h4>' +
      '<p class="mt-1 text-sm text-gray-600">Users must sign in with WordPress before using this server. Only the users selected here can connect; administrators are not automatically allowed.</p>' +
      '<div data-native-oauth-content class="mt-3"><p class="text-sm text-gray-500">Loading allowed users…</p></div>';
    const authCard = clientAuthCard(form);
    if (authCard) authCard.appendChild(card);
    else form.appendChild(card);
    if (!submitBound.has(form)) {
      form.addEventListener('submit', event => {
        if (!isWordPressMode(form)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const saveUsers = form.querySelector('[data-save-getmcp-users]');
        if (saveUsers) saveUsers.click();
      }, true);
      submitBound.add(form);
    }

    const content = card.querySelector('[data-native-oauth-content]');
    const el = (tag, text, cls) => { const node = document.createElement(tag); if (text !== undefined) node.textContent = text; if (cls) node.className = cls; return node; };
    const button = (label, cls) => { const node = el('button', label, 'button ' + (cls || '')); node.type = 'button'; return node; };
    const showError = message => { let node = content.querySelector('[data-error]'); if (!node) { node = el('p', '', 'mt-3 text-sm text-red-700'); node.dataset.error = '1'; content.appendChild(node); } node.textContent = message; };

    request('/getmcp/v1/servers/' + encodeURIComponent(serverId)).then(server => {
      const config = parseConfig(server.auth_config);
      let allowed = Array.isArray(config.allowed_user_ids) ? config.allowed_user_ids.map(Number).filter(id => Number.isInteger(id) && id > 0) : [];
      const native = server.auth_type === 'oauth' && config.provider === 'getmcp';
      nativeSignIn = native;
      form.hidden = false;
      content.replaceChildren();

      const status = el('p', native ? 'WordPress sign-in is active. Save here to update who may connect.' : 'Choose WordPress sign-in (GetMCP) above, then select who may connect.', 'text-sm ' + (native ? 'text-green-700' : 'text-gray-600'));
      content.appendChild(status);

      const selected = el('div', undefined, 'mt-3 flex flex-wrap gap-2');
      const searchLabel = el('label', 'Search WordPress users', 'mt-3 block text-sm font-medium text-gray-700');
      const search = el('input', undefined, 'mt-1 w-full rounded border border-gray-300 px-3 py-2');
      search.type = 'search'; search.maxLength = 100; search.placeholder = 'Search by name or username…';
      const results = el('div', undefined, 'mt-2 max-h-56 space-y-1 overflow-y-auto');
      const note = el('p', 'An empty list denies everyone, including administrators.', 'mt-2 text-xs text-gray-500');
      const actions = el('div', undefined, 'mt-3 flex flex-wrap gap-2');
      const save = button(native ? 'Save allowed users' : 'Save WordPress sign-in', 'button-primary');
      save.dataset.saveGetmcpUsers = '1';
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
      loading = '';
      content.replaceChildren();
      showError(error && error.message ? error.message : 'Could not load server authentication settings.');
    }).finally(() => { loading = ''; });
  }

  const observer = new MutationObserver(() => {
    mount();
    scheduleNativeMenuExpansion();
  });
  observer.observe(document.documentElement, {childList: true, subtree: true});
  window.addEventListener('resize', scheduleNativeMenuExpansion);
  window.addEventListener('scroll', scheduleNativeMenuExpansion, true);
  window.addEventListener('hashchange', () => { mounted = ''; mount(); });
  mount();
  scheduleNativeMenuExpansion();
})();
