(() => {
  if (window.MiDinero?.__booted) return;

  const registry = new Map();
  const loadedScripts = new Set();
  let currentInstance = null;
  let currentPage = '';
  let currentUrl = location.href;
  let navController = null;
  let realtimeSource = null;
  let realtimeTimer = null;
  let pendingRealtimeEvents = [];
  let initialized = false;

  const makeClientId = () =>
    (window.crypto?.randomUUID?.() || `md-${Date.now()}-${Math.random().toString(36).slice(2)}`).slice(0, 80);

  const clientId = makeClientId();
  const contentEl = () => document.querySelector('section.content');
  const normalizeUrl = value => new URL(value, location.href);
  const stripSlash = path => path.length > 1 ? path.replace(/\/+$/, '') : path;

  function routePaths() {
    const routes = window.APP?.routes || {};
    return [...new Set(Object.values(routes).map(v => {
      try { return stripSlash(new URL(v, location.origin).pathname); }
      catch (_) { return ''; }
    }).filter(Boolean))];
  }

  function isAppRoute(urlLike) {
    let url;
    try { url = normalizeUrl(urlLike); } catch (_) { return false; }
    if (url.origin !== location.origin) return false;
    const path = stripSlash(url.pathname);
    const roots = routePaths();
    return roots.some(root => path === root || (root.endsWith('/configuracion') && path.startsWith(root + '/')));
  }

  function isSettingsPath(urlLike) {
    let url;
    try { url = normalizeUrl(urlLike); } catch (_) { return false; }
    const cfg = window.APP?.routes?.configuracion;
    if (!cfg) return false;
    const root = stripSlash(new URL(cfg, location.origin).pathname);
    const path = stripSlash(url.pathname);
    return path === root || path.startsWith(root + '/');
  }

  function clientHeaders(headers = {}) {
    const out = new Headers(headers);
    out.set('X-Client-Id', clientId);
    return Object.fromEntries(out.entries());
  }

  function register(name, init) {
    if (!name || typeof init !== 'function') return;
    registry.set(name, init);
  }

  async function destroyCurrent() {
    const instance = currentInstance;
    currentInstance = null;
    if (!instance) return;
    try {
      if (typeof instance === 'function') await instance();
      else if (typeof instance.destroy === 'function') await instance.destroy();
    } catch (error) {
      console.warn('[Mi Dinero] No se pudo limpiar el módulo anterior:', error);
    }
  }

  async function initCurrent() {
    const root = contentEl();
    if (!root) return;
    currentPage = root.dataset.page || '';
    const init = registry.get(currentPage);
    currentInstance = null;
    if (typeof init === 'function') {
      try {
        currentInstance = (await init({root, app: api})) || null;
      } catch (error) {
        console.error(`[Mi Dinero] Error iniciando ${currentPage}:`, error);
      }
    }
    try { window.finBindMoney?.(root); } catch (_) {}
  }

  function updateSidebar(page, parsedDocument = null) {
    let parsedActiveHref = '';
    const parsedActive = parsedDocument?.querySelector('.side-nav a.active');
    if (parsedActive) parsedActiveHref = parsedActive.getAttribute('href') || '';

    document.querySelectorAll('.side-nav a').forEach(anchor => {
      let active = false;
      if (parsedActiveHref) {
        try {
          active = stripSlash(new URL(anchor.href, location.origin).pathname) === stripSlash(new URL(parsedActiveHref, location.origin).pathname);
        } catch (_) {}
      }
      if (!parsedActiveHref && page && window.APP?.routes?.[page]) {
        try {
          active = stripSlash(new URL(anchor.href, location.origin).pathname) === stripSlash(new URL(APP.routes[page], location.origin).pathname);
        } catch (_) {}
      }
      anchor.classList.toggle('active', active);
    });
  }

  function scriptDescriptor(script) {
    return {
      src: script.getAttribute('src') || '',
      type: script.getAttribute('type') || '',
      text: script.textContent || '',
      async: script.hasAttribute('async'),
      defer: script.hasAttribute('defer')
    };
  }

  function markExistingScripts() {
    document.querySelectorAll('script[src]').forEach(script => {
      try { loadedScripts.add(new URL(script.src, location.href).href); } catch (_) {}
    });
  }

  async function ensureExternalScript(desc) {
    const src = new URL(desc.src, location.href).href;
    if (loadedScripts.has(src)) return;
    const existing = [...document.scripts].some(s => {
      try { return s.src && new URL(s.src, location.href).href === src; } catch (_) { return false; }
    });
    if (existing) {
      loadedScripts.add(src);
      return;
    }
    await new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = src;
      if (desc.type) script.type = desc.type;
      script.async = false;
      script.onload = () => { loadedScripts.add(src); resolve(); };
      script.onerror = () => reject(new Error(`No se pudo cargar ${src}`));
      document.head.appendChild(script);
    });
  }

  function executeInlineScript(desc) {
    if (desc.type && !['text/javascript', 'application/javascript', 'module'].includes(desc.type)) return;
    const script = document.createElement('script');
    if (desc.type) script.type = desc.type;
    script.textContent = desc.text;
    document.head.appendChild(script);
    script.remove();
  }

  async function executeScripts(descriptors) {
    for (const desc of descriptors) {
      if (desc.src) await ensureExternalScript(desc);
      else executeInlineScript(desc);
    }
  }

  function navigationFallback(url) {
    location.href = url instanceof URL ? url.href : String(url);
  }

  async function softNavigate(urlLike, options = {}) {
    const target = normalizeUrl(urlLike);
    if (!isAppRoute(target)) {
      navigationFallback(target);
      return false;
    }

    if (navController) navController.abort();
    navController = new AbortController();
    const controller = navController;
    const root = contentEl();
    const scrollY = window.scrollY;
    document.body.classList.add('app-navigating');

    try {
      const response = await fetch(target.href, {
        method: 'GET',
        headers: {
          'Accept': 'text/html',
          'X-Requested-With': 'MiDinero-Navigation',
          'Cache-Control': 'no-cache',
          'X-Client-Id': clientId
        },
        credentials: 'same-origin',
        cache: 'no-store',
        signal: controller.signal
      });

      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const finalUrl = new URL(response.url || target.href, location.href);
      if (!isAppRoute(finalUrl)) {
        navigationFallback(finalUrl);
        return false;
      }

      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const incoming = doc.querySelector('section.content');
      if (!incoming || !root) throw new Error('La página no contiene el área de aplicación.');

      const scripts = [...incoming.querySelectorAll('script')].map(scriptDescriptor);
      incoming.querySelectorAll('script').forEach(s => s.remove());

      await destroyCurrent();
      document.body.classList.remove('menu-open', 'modal-open', 'settings-modal-open');
      root.dataset.page = incoming.dataset.page || '';
      root.innerHTML = incoming.innerHTML;

      const title = doc.querySelector('title')?.textContent?.trim();
      if (title) document.title = title;
      const incomingHeaderTitle = doc.querySelector('#appHeaderPageTitle')?.textContent?.trim();
      const currentHeaderTitle = document.getElementById('appHeaderPageTitle');
      if (currentHeaderTitle && incomingHeaderTitle) currentHeaderTitle.textContent = incomingHeaderTitle;
      updateSidebar(root.dataset.page || '', doc);

      await executeScripts(scripts);

      const urlForHistory = finalUrl.pathname + finalUrl.search + finalUrl.hash;
      if (options.push !== false) history.pushState({midinero:true}, '', urlForHistory);
      else if (options.replace) history.replaceState({midinero:true}, '', urlForHistory);
      currentUrl = finalUrl.href;

      await initCurrent();

      if (options.preserveScroll) window.scrollTo({top: scrollY, left: 0, behavior: 'instant'});
      else window.scrollTo({top: 0, left: 0, behavior: 'instant'});

      window.dispatchEvent(new CustomEvent('midinero:pagechange', {detail:{page:root.dataset.page || '', url:finalUrl.href}}));
      return true;
    } catch (error) {
      if (error?.name === 'AbortError') return false;
      console.error('[Mi Dinero] Navegación dinámica:', error);
      if (!options.noFallback) navigationFallback(target);
      return false;
    } finally {
      if (navController === controller) {
        navController = null;
        document.body.classList.remove('app-navigating');
      }
    }
  }

  async function softRefresh(options = {}) {
    return softNavigate(location.href, {
      push: false,
      preserveScroll: options.preserveScroll !== false,
      noFallback: !!options.noFallback
    });
  }

  function userIsBusy() {
    if (document.querySelector('.modal.show, .settings-create-modal.show')) return true;
    const active = document.activeElement;
    return !!(active && contentEl()?.contains(active) && active.matches('input,select,textarea,[contenteditable="true"]'));
  }

  async function applyRealtimeRefresh() {
    if (!pendingRealtimeEvents.length) return;
    if (userIsBusy()) {
      clearTimeout(realtimeTimer);
      realtimeTimer = setTimeout(applyRealtimeRefresh, 700);
      return;
    }

    const events = pendingRealtimeEvents.splice(0);
    try {
      if (currentInstance && typeof currentInstance.refresh === 'function') {
        await currentInstance.refresh(events);
      } else {
        await softRefresh({preserveScroll:true, noFallback:true});
      }
    } catch (error) {
      console.warn('[Mi Dinero] No se pudo aplicar actualización realtime:', error);
    }
  }

  function queueRealtime(events) {
    if (!events?.length) return;
    pendingRealtimeEvents.push(...events);
    window.dispatchEvent(new CustomEvent('midinero:realtime', {detail:{events}}));
    clearTimeout(realtimeTimer);
    realtimeTimer = setTimeout(applyRealtimeRefresh, 180);
  }

  function connectRealtime() {
    if (!window.EventSource || !window.APP?.apiBase || realtimeSource) return;
    realtimeSource = new EventSource(`${APP.apiBase}/stream.php`);
    realtimeSource.addEventListener('change', event => {
      let data = {};
      try { data = JSON.parse(event.data || '{}'); } catch (_) {}
      let events = Array.isArray(data.events) ? data.events : (Array.isArray(data.types) ? data.types.map(type => ({type})) : []);
      // Los eventos emitidos por esta misma pestaña no deben hacer que su formulario
      // o modal vuelva a cargarse. Las otras pestañas/dispositivos sí se refrescan.
      events = events.filter(item => !item?.source_client_id || item.source_client_id !== clientId);
      queueRealtime(events);
    });
    realtimeSource.addEventListener('error', () => {
      // EventSource reconecta automáticamente. No abrimos conexiones paralelas.
    });
  }

  function closeRealtime() {
    if (realtimeSource) realtimeSource.close();
    realtimeSource = null;
  }

  const api = {
    __booted: true,
    clientId,
    register,
    clientHeaders,
    softNavigate,
    softRefresh,
    initCurrent,
    queueRealtime,
    get currentPage() { return currentPage; }
  };

  window.MiDinero = api;
  window.MiDineroRegister = register;

  // Registrar global: el selector vive en la cabecera y está disponible en todos los módulos.
  function closeGlobalRegisterMenus(except = null) {
    document.querySelectorAll('.global-register-menu.show').forEach(menu => {
      if (menu === except) return;
      menu.classList.remove('show');
      menu.setAttribute('aria-hidden', 'true');
      const trigger = menu.closest('.global-register-wrap')?.querySelector('[data-global-register-trigger]');
      trigger?.setAttribute('aria-expanded', 'false');
    });
  }

  function toggleGlobalRegisterMenu(trigger) {
    const wrap = trigger?.closest('.global-register-wrap');
    const menu = wrap?.querySelector('.global-register-menu');
    if (!menu) return;
    const open = !menu.classList.contains('show');
    closeGlobalRegisterMenus(open ? menu : null);
    menu.classList.toggle('show', open);
    menu.setAttribute('aria-hidden', open ? 'false' : 'true');
    trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  document.addEventListener('click', event => {
    const registerTrigger = event.target.closest('[data-global-register-trigger]');
    if (registerTrigger) {
      event.preventDefault();
      event.stopPropagation();
      toggleGlobalRegisterMenu(registerTrigger);
      return;
    }

    const registerAction = event.target.closest('[data-global-register-action]');
    if (registerAction) {
      event.preventDefault();
      event.stopPropagation();
      const action = registerAction.dataset.globalRegisterAction || '';
      closeGlobalRegisterMenus();
      if (['expense','income','transfer','allocate'].includes(action) && window.APP?.routes?.dashboard) {
        const target = new URL(APP.routes.dashboard, location.href);
        target.searchParams.set('action', action);
        softNavigate(target.href, {push:true});
      }
      return;
    }

    if (event.target.closest('.register-menu-saving')) {
      closeGlobalRegisterMenus();
    } else if (!event.target.closest('.global-register-wrap')) {
      closeGlobalRegisterMenus();
    }
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') closeGlobalRegisterMenus();
  });

  document.addEventListener('click', event => {
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const anchor = event.target.closest('a[href]');
    if (!anchor || anchor.hasAttribute('download') || anchor.target === '_blank') return;
    if (anchor.closest('[data-settings-root]') && (anchor.matches('[data-settings-tab]') || anchor.matches('[data-settings-jump]'))) return;
    if (anchor.dataset.noSpa !== undefined) return;

    let target;
    try { target = normalizeUrl(anchor.href); } catch (_) { return; }
    if (!isAppRoute(target)) return;
    event.preventDefault();
    softNavigate(target.href, {push:true});
  });

  window.addEventListener('popstate', () => {
    const target = location.href;
    // Las subpestañas de Configuración ya usan su propio history.pushState.
    if (currentPage === 'configuracion' && isSettingsPath(target)) {
      currentUrl = target;
      return;
    }
    if (isAppRoute(target)) softNavigate(target, {push:false});
  });

  window.addEventListener('beforeunload', closeRealtime);
  document.addEventListener('focusout', () => {
    if (pendingRealtimeEvents.length) {
      clearTimeout(realtimeTimer);
      realtimeTimer = setTimeout(applyRealtimeRefresh, 180);
    }
  });

  const boot = async () => {
    if (initialized) return;
    initialized = true;
    markExistingScripts();
    currentUrl = location.href;
    await initCurrent();
    connectRealtime();
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, {once:true});
  else queueMicrotask(boot);
})();
