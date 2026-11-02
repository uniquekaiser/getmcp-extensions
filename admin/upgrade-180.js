(function () {
  'use strict';
  const config = window.getmcpUpgrade180;
  const input = document.getElementById('getmcp-upgrade-server-id');
  const review = document.getElementById('getmcp-upgrade-review');
  const migrate = document.getElementById('getmcp-upgrade-migrate');
  const result = document.getElementById('getmcp-upgrade-result');
  if (!config || !input || !review || !migrate || !result) return;
  let reviewed = null;
  const clear = () => { reviewed = null; migrate.hidden = true; };
  input.addEventListener('input', clear);
  async function request(method, id, body) {
    const response = await fetch(config.base + encodeURIComponent(id), {
      method,
      credentials: 'same-origin',
      headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : undefined
    });
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'The request failed.');
    return data;
  }
  review.addEventListener('click', async () => {
    clear();
    const id = input.value;
    if (!/^[1-9]\d*$/.test(id)) { result.textContent = 'Enter a positive server ID.'; return; }
    review.disabled = true;
    result.textContent = 'Reviewing…';
    try {
      const plan = await request('GET', id);
      if (input.value !== id) return;
      if (plan.state === 'eligible') {
        reviewed = { id, revision: plan.revision };
        migrate.hidden = false;
        result.textContent = 'This isolated server passed the limited sign-in checks. Review your backup before continuing.';
      } else {
        result.textContent = 'This server cannot use the limited migration: ' + (plan.reasons || []).join(', ');
      }
    } catch (error) { result.textContent = error.message; }
    finally { review.disabled = false; }
  });
  migrate.addEventListener('click', async () => {
    if (!reviewed || input.value !== reviewed.id) return;
    const current = reviewed;
    clear();
    review.disabled = true;
    result.textContent = 'Migrating…';
    try {
      await request('POST', current.id, { revision: current.revision });
      result.textContent = 'The sign-in server was migrated. Other affected Extensions features still require compatibility work.';
    } catch (error) { result.textContent = error.message + ' Review the server again.'; }
    finally { review.disabled = false; }
  });
}());
