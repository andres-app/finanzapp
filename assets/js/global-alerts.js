(() => {
  if (window.__MiDineroAlertsBooted) return;
  window.__MiDineroAlertsBooted = true;

  const modal = document.getElementById('globalAlertsModal');
  const list = document.getElementById('globalAlertsList');
  const api = file => `${window.APP?.apiBase || '/api'}/${file}`;
  let cache = [];
  let controller = null;
  let timer = null;

  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const seenKey = 'midinero_alerts_seen_v1';
  const loadSeen = () => { try { return JSON.parse(localStorage.getItem(seenKey) || '{}') || {}; } catch (_) { return {}; } };
  const saveSeen = seen => { try { localStorage.setItem(seenKey, JSON.stringify(seen)); } catch (_) {} };

  function updateBadges() {
    const seen = loadSeen();
    const unseen = cache.filter(a => !seen[a.id]).length;
    document.querySelectorAll('[data-alerts-badge]').forEach(el => {
      el.hidden = unseen < 1;
      el.textContent = unseen > 9 ? '9+' : String(unseen);
    });
  }

  function markAllSeen() {
    const seen = loadSeen();
    const now = Date.now();
    cache.forEach(a => { seen[a.id] = now; });
    const cutoff = now - 1000 * 60 * 60 * 24 * 45;
    Object.keys(seen).forEach(k => { if (Number(seen[k]) < cutoff) delete seen[k]; });
    saveSeen(seen);
    updateBadges();
  }

  function render() {
    if (!list) return;
    if (!cache.length) {
      list.innerHTML = '<div class="global-alerts-empty"><span>✓</span><b>Todo bajo control</b><p>No hay alertas financieras que requieran tu atención ahora.</p></div>';
      updateBadges();
      return;
    }
    const seen = loadSeen();
    list.innerHTML = cache.map(a => `
      <a class="global-alert-item ${esc(a.severity || 'info')} ${seen[a.id] ? '' : 'unseen'}" href="${esc(a.href || '#')}" data-alert-link>
        <span class="global-alert-icon">${esc(a.icon || '•')}</span>
        <div><b>${esc(a.title)}</b><p>${esc(a.body)}</p></div>
        <span class="global-alert-arrow">›</span>
      </a>`).join('');
    updateBadges();
  }

  async function load() {
    if (controller) controller.abort();
    controller = new AbortController();
    try {
      const r = await fetch(api('alerts.php'), {credentials:'same-origin', cache:'no-store', signal:controller.signal, headers:{'X-Client-Id':window.MiDinero?.clientId || ''}});
      const j = await r.json();
      if (!r.ok || j.ok === false) throw new Error(j.message || 'No se pudieron cargar las alertas');
      cache = Array.isArray(j.alerts) ? j.alerts : [];
      render();
    } catch (e) {
      if (e.name === 'AbortError') return;
      if (list && modal?.classList.contains('show')) list.innerHTML = '<div class="global-alerts-empty"><span>!</span><b>No se pudieron cargar las alertas</b><p>Intenta nuevamente en unos segundos.</p></div>';
    } finally {
      controller = null;
    }
  }

  function open() {
    if (!modal) return;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden','false');
    document.body.classList.add('alerts-open');
    markAllSeen();
    load();
  }
  function close() {
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden','true');
    document.body.classList.remove('alerts-open');
  }

  document.addEventListener('click', e => {
    const trigger = e.target.closest('[data-global-alerts-trigger]');
    if (trigger) { e.preventDefault(); open(); return; }
    if (e.target.closest('[data-global-alerts-close]')) { e.preventDefault(); close(); return; }
    const alertLink = e.target.closest('[data-alert-link]');
    if (alertLink) close();
  });
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && modal?.classList.contains('show')) close(); });
  window.addEventListener('midinero:pagechange', () => { clearTimeout(timer); timer = setTimeout(load, 250); });
  window.addEventListener('midinero:realtime', () => { clearTimeout(timer); timer = setTimeout(load, 350); });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load, {once:true});
  else load();
})();
