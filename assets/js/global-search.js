(() => {
  const boot = () => {
    if (window.__miDineroGlobalSearchReady) return;

    const modal = document.getElementById('globalSearchModal');
    const input = document.getElementById('globalSearchInput');
    const results = document.getElementById('globalSearchResults');
    if (!modal || !input || !results) return;

    window.__miDineroGlobalSearchReady = true;
    let timer = null;
    let controller = null;
    let last = null;

    const esc = v => String(v ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
    const money = n => `S/ ${new Intl.NumberFormat('es-PE',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(n || 0))}`;
    const empty = (title, detail) => {
      results.innerHTML = `<div class="global-search-empty"><b>${esc(title)}</b><span>${esc(detail)}</span></div>`;
    };

    const endpoint = () => {
      const base = String(window.APP?.apiBase || '/api').replace(/\/$/, '');
      return `${base}/search.php`;
    };

    function openSearch() {
      modal.classList.add('show');
      modal.setAttribute('aria-hidden','false');
      document.body.classList.add('global-search-open');
      setTimeout(() => {
        input.focus({preventScroll:true});
        if (input.value.trim()) run(input.value, true);
      }, 20);
    }

    function closeSearch() {
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden','true');
      document.body.classList.remove('global-search-open');
    }

    function render(rows) {
      if (!Array.isArray(rows) || !rows.length) {
        empty('Sin resultados','Prueba con otro nombre, monto o persona.');
        return;
      }
      let current = '';
      results.innerHTML = rows.map(r => {
        const group = r.kind || 'Resultado';
        const head = group !== current ? (current = group, `<div class="global-search-group">${esc(group)}</div>`) : '';
        const amount = r.amount === null || r.amount === undefined || r.amount === '' ? '' : `<strong class="${esc(r.tone || '')}">${money(r.amount)}</strong>`;
        return `${head}<a href="${esc(r.url || '#')}" class="global-search-result" data-search-result>
          <span class="global-search-result-icon ${esc(r.tone || '')}">${esc(r.icon || '•')}</span>
          <span class="global-search-result-copy"><b>${esc(r.title || 'Resultado')}</b><small>${esc(r.subtitle || '')}</small></span>
          ${amount}<i>›</i></a>`;
      }).join('');
    }

    async function run(value, force = false) {
      const q = String(value || '').trim();
      if (!force && q === last) return;
      last = q;
      if (controller) controller.abort();
      if (!q) {
        empty('Busca cualquier cosa de tus finanzas','Prueba: WIN, alquiler, 300, Lissette…');
        return;
      }

      controller = new AbortController();
      results.innerHTML = '<div class="global-search-loading">Buscando…</div>';
      try {
        const url = `${endpoint()}?q=${encodeURIComponent(q)}&_=${Date.now()}`;
        const response = await fetch(url, {
          method:'GET',
          cache:'no-store',
          credentials:'same-origin',
          signal:controller.signal,
          headers:{
            'Accept':'application/json',
            'X-Requested-With':'XMLHttpRequest',
            'X-Client-Id':window.MiDinero?.clientId || ''
          }
        });
        const text = await response.text();
        let data;
        try { data = JSON.parse(text); }
        catch (_) { throw new Error(`Respuesta inválida (${response.status})`); }
        if (!response.ok || !data?.ok) throw new Error(data?.message || `HTTP ${response.status}`);
        render(data.results || []);
      } catch (error) {
        if (error?.name === 'AbortError') return;
        console.error('[Mi Dinero] Buscador global:', error);
        empty('No se pudo buscar','Recarga una vez la página. Si continúa, revisa que api/search.php haya sido reemplazado.');
      }
    }

    // Delegación: funciona aunque el sidebar o la cabecera sean actualizados por la navegación SPA.
    document.addEventListener('click', event => {
      const trigger = event.target.closest('[data-global-search-trigger]');
      if (trigger) {
        event.preventDefault();
        openSearch();
        return;
      }
      const closer = event.target.closest('[data-global-search-close]');
      if (closer) {
        event.preventDefault();
        closeSearch();
        return;
      }
      const link = event.target.closest('[data-search-result]');
      if (link) {
        event.preventDefault();
        const href = link.href;
        closeSearch();
        if (window.MiDinero?.softNavigate) window.MiDinero.softNavigate(href,{push:true});
        else location.href = href;
      }
    });

    input.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(() => run(input.value), 160);
    });
    input.addEventListener('search', () => run(input.value, true));

    document.addEventListener('keydown', event => {
      if ((event.ctrlKey || event.metaKey) && String(event.key).toLowerCase() === 'k') {
        event.preventDefault();
        modal.classList.contains('show') ? closeSearch() : openSearch();
      } else if (event.key === 'Escape' && modal.classList.contains('show')) {
        closeSearch();
      }
    });

    window.MiDineroGlobalSearch = {open:openSearch, close:closeSearch, search:q=>run(q,true)};
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once:true});
  else boot();
})();
