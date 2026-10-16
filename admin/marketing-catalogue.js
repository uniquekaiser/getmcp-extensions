/** Catalogue adapter for native builds with a static template list. */
(() => {
  const config = window.getmcpMarketingCatalogue;
  if (!config?.templates?.length) return;
  const request = async (path, body) => {
    const response = await fetch(config.base + path, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-WP-Nonce':config.nonce}, body:JSON.stringify(body)});
    const result = await response.json(); if (!response.ok) throw new Error(result.message || 'Template installation failed.'); return result;
  };
  const install = (template, button, card) => {
    if (card.querySelector('form')) return;
    const form = document.createElement('form');
    const label = document.createElement('label'); label.textContent = 'Server name';
    const input = document.createElement('input'); input.required = true; input.value = template.name; input.maxLength = 120; input.className = 'w-full border rounded p-2'; label.append(input);
    const notice = document.createElement('p'); notice.textContent = 'Create a new draft server. Configure OAuth and allowed users, then verify harmless reads before activation. No provider account is contacted.';
    const submit = document.createElement('button'); submit.type = 'submit'; submit.className = 'button button-primary'; submit.textContent = 'Create draft server';
    const cancel = document.createElement('button'); cancel.type = 'button'; cancel.className = 'button'; cancel.textContent = 'Cancel'; cancel.onclick = () => form.remove();
    const status = document.createElement('p'); status.setAttribute('role','status');
    form.append(label, notice, submit, cancel, status); card.append(form);
    let created = null;
    form.onsubmit = async event => {
      event.preventDefault(); submit.disabled = true; cancel.disabled = true;
      try {
        created = await request('servers', {name:input.value.trim(), status:'draft', auth_type:'none'});
        await request(`templates/${encodeURIComponent(template.slug)}/install`, {server_id:created.uuid});
        location.href = `admin.php?page=getmcp-servers#/servers/${encodeURIComponent(created.uuid)}/auth`;
      } catch (error) {
        status.textContent = error.message + (created ? ' The empty draft was retained; review it in Servers before trying again.' : '');
        // No automatic retries or repeated draft creation on a partial failure.
        if (!created) { submit.disabled = false; cancel.disabled = false; }
      }
    };
  };
  let queued = false;
  const render = () => {
    queued = false;
    const root = document.getElementById('getmcp-admin-root'); if (!root) return;
    const heading = [...root.querySelectorAll('h1')].find(el => el.textContent === 'Templates');
    if (!heading) { document.querySelectorAll('[data-marketing-template]').forEach(el => el.remove()); return; }
    const content = heading.closest('main'); if (!content) return;
    const summary = [...content.querySelectorAll('p')].find(el => el.textContent.includes('pre-built tool configurations for popular APIs'));
    if (summary && !summary.textContent.includes('extension templates')) summary.textContent += ` · ${config.templates.length} extension templates`;
    // Integrated bundles already render the five templates; do not duplicate them.
    const query = (content.querySelector('input[placeholder="Search templates..."]')?.value || '').trim().toLowerCase();
    const category = content.querySelector('button[aria-label="Filter by category"]')?.textContent.trim() || 'All';
    const visible = config.templates.filter(t => (!query || `${t.name} ${t.description} ${t.category} synergetic`.toLowerCase().includes(query)) && ['All',t.category].includes(category));
    document.querySelectorAll('[data-marketing-template]').forEach(el => { if (!visible.some(t => t.slug === el.dataset.marketingTemplate)) el.remove(); });
    const empty = [...content.querySelectorAll('h3')].find(el => el.textContent === 'No templates found')?.parentElement;
    if (empty && visible.length) empty.style.setProperty('display','none','important');
    else if (empty && !content.querySelector('#getmcp-telegram-extension-card')) empty.style.removeProperty('display');
    let grid = content.querySelector('.grid.grid-cols-1.md\\:grid-cols-2.lg\\:grid-cols-3');
    if (!grid && visible.length) { grid = document.createElement('div'); grid.className = 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6'; (empty?.parentElement || content).append(grid); }
    if (!grid) return;
    visible.forEach(template => {
      if (content.querySelector(`[data-marketing-template="${template.slug}"]`) || [...content.querySelectorAll('h3')].some(el => el.textContent === template.name)) return;
      const card = document.createElement('section'); card.dataset.marketingTemplate = template.slug; card.className = 'bg-white rounded-xl shadow-sm border border-gray-200 p-6 flex flex-col';
      const badge = document.createElement('span'); badge.className = 'text-xs text-gray-500 mb-4'; badge.textContent = `${template.category} · Extension`;
      const title = document.createElement('h3'); title.className = 'text-base font-semibold mb-1'; title.textContent = template.name;
      const description = document.createElement('p'); description.className = 'text-sm text-gray-500 flex-1 mb-4'; description.textContent = template.description;
      const count = document.createElement('p'); count.className = 'text-xs text-gray-400 mb-4'; count.textContent = `${template.tools.length} tools · Configure personal OAuth after installation`;
      const button = document.createElement('button'); button.className = 'button'; button.textContent = 'Install'; button.onclick = () => install(template, button, card);
      card.append(badge,title,description,count,button); grid.append(card);
    });
  };
  const schedule = () => { if (!queued) { queued = true; requestAnimationFrame(render); } };
  new MutationObserver(schedule).observe(document.getElementById('wpbody-content') || document.body,{childList:true,subtree:true});
  document.addEventListener('input',schedule); window.addEventListener('hashchange',schedule); render();
})();
