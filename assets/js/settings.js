window.MiDineroRegister('configuracion', () => {
  const pageAbort = new AbortController();
  const root = document.querySelector('[data-settings-root]');
  if (!root) return;

  const tabs = [...root.querySelectorAll('[data-settings-tab]')];
  const panels = [...root.querySelectorAll('[data-settings-panel]')];
  const valid = tabs.map(a => a.dataset.settingsTab);
  // Endpoint físico y estable: no depende de mod_rewrite para peticiones POST.
  const autosaveUrl = root.dataset.autosaveUrl || '/api/settings.php';

  function activate(name, push = false) {
    if (!valid.includes(name)) name = 'conceptos';
    tabs.forEach(a => a.classList.toggle('active', a.dataset.settingsTab === name));
    panels.forEach(panel => {
      panel.hidden = panel.dataset.settingsPanel !== name;
    });
    root.dataset.activeTab = name;
    if (openModal) hideCreateModal(openModal);

    if (push) {
      const target = tabs.find(a => a.dataset.settingsTab === name);
      if (target) history.pushState({settingsTab:name}, '', target.href);
    }
    window.scrollTo({top:0, behavior:'instant'});
  }

  tabs.forEach(a => a.addEventListener('click', e => {
    if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    e.preventDefault();
    activate(a.dataset.settingsTab, true);
  }));

  root.querySelectorAll('[data-settings-jump]').forEach(a => a.addEventListener('click', e => {
    e.preventDefault();
    activate(a.dataset.settingsJump, true);
  }));

  window.addEventListener('popstate', () => {
    const path = location.pathname.replace(/\/+$/, '');
    const name = path.split('/').pop();
    activate(valid.includes(name) ? name : (root.dataset.activeTab || 'conceptos'), false);
  }, {signal:pageAbort.signal});



  // Creación: cada tipo usa su propio modal compacto.
  const modalMap = {
    concept: document.getElementById('settingsConceptModal'),
    payment: document.getElementById('settingsPaymentModal'),
    income: document.getElementById('settingsIncomeModal'),
    category: document.getElementById('settingsCategoryModal'),
    goal: document.getElementById('settingsGoalModal')
  };

  let openModal = null;

  function showCreateModal(name) {
    const modal = modalMap[name];
    if (!modal) return;
    if (openModal && openModal !== modal) hideCreateModal(openModal);
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('settings-modal-open');
    openModal = modal;
    requestAnimationFrame(() => {
      const first = modal.querySelector('form input:not([type="hidden"]), form select, form textarea');
      if (first) first.focus({preventScroll:true});
    });
  }

  function hideCreateModal(modal = openModal) {
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    if (openModal === modal) openModal = null;
    if (!document.querySelector('.settings-create-modal.show')) {
      document.body.classList.remove('settings-modal-open');
    }
  }

  root.querySelectorAll('[data-settings-new]').forEach(button => {
    button.addEventListener('click', () => showCreateModal(button.dataset.settingsNew));
  });

  document.querySelectorAll('.settings-create-modal [data-settings-modal-close]').forEach(button => {
    button.addEventListener('click', () => hideCreateModal(button.closest('.settings-create-modal')));
  });

  document.querySelectorAll('.settings-create-modal').forEach(modal => {
    modal.addEventListener('mousedown', event => {
      if (event.target === modal) hideCreateModal(modal);
    });
  });

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && openModal) hideCreateModal(openModal);
  }, {signal:pageAbort.signal});

  const serialize = form => {
    const data = new FormData(form);
    const pairs = [];
    for (const [key, value] of data.entries()) pairs.push(`${key}=${String(value)}`);
    return pairs.sort().join('&');
  };

  function setStatus(form, state, text) {
    const status = form.querySelector('[data-autosave-status]');
    if (!status) return;
    status.classList.remove('saved', 'saving', 'error');
    status.classList.add(state);
    const label = status.querySelector('span');
    if (label) label.textContent = text;
  }

  root.querySelectorAll('form[data-autosave]').forEach(form => {
    let timer = null;
    let lastSaved = serialize(form);
    let saving = false;
    let queued = false;
    let failedSnapshot = null;

    const save = async () => {
      clearTimeout(timer);
      const current = serialize(form);
      if (current === lastSaved) return;
      // No repetir automáticamente exactamente el mismo payload que ya falló.
      if (failedSnapshot === current) return;

      if (!form.reportValidity()) {
        setStatus(form, 'error', 'Revisa datos');
        return;
      }

      // Nunca abortamos una escritura en curso. Abortar fetch no detiene PHP y
      // podía provocar dos guardados simultáneos o respuestas fuera de orden.
      if (saving) {
        queued = true;
        setStatus(form, 'saving', 'Pendiente…');
        return;
      }

      saving = true;
      queued = false;
      const snapshot = current;
      const body = new FormData(form);
      setStatus(form, 'saving', 'Guardando…');

      try {
        const response = await fetch(autosaveUrl, {
          method: 'POST',
          body,
          headers: window.MiDinero?.clientHeaders({
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
            'Cache-Control': 'no-cache'
          }) || {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
            'Cache-Control': 'no-cache'
          },
          credentials: 'same-origin',
          cache: 'no-store',
          signal: pageAbort.signal
        });

        const raw = await response.text();
        let data = null;
        try { data = raw ? JSON.parse(raw) : null; } catch (_) {}
        if (!response.ok || !data?.ok) {
          const detail = data?.message || (response.status === 404
            ? `Ruta de guardado no encontrada (${autosaveUrl})`
            : `No se pudo guardar (HTTP ${response.status})`);
          console.error('[Finanzapp] Respuesta autoguardado:', {
            url: autosaveUrl,
            status: response.status,
            body: raw.slice(0, 500)
          });
          throw new Error(detail);
        }

        lastSaved = snapshot;
        failedSnapshot = null;
        const status = form.querySelector('[data-autosave-status]');
        if (status) status.title = 'Guardado correctamente';
        setStatus(form, 'saved', 'Guardado');
      } catch (error) {
        const message = error?.message || 'No se pudo guardar';
        failedSnapshot = snapshot;
        const status = form.querySelector('[data-autosave-status]');
        if (status) status.title = message;
        console.error('[Finanzapp] Autoguardado:', message);
        setStatus(form, 'error', 'Error al guardar');
      } finally {
        saving = false;
        const latest = serialize(form);
        // Solo procesar otra escritura si el usuario cambió algo durante el guardado.
        if ((queued || latest !== snapshot) && latest !== lastSaved && latest !== failedSnapshot) {
          queued = false;
          timer = setTimeout(save, 120);
        } else {
          queued = false;
        }
      }
    };

    const schedule = delay => {
      clearTimeout(timer);
      const current = serialize(form);
      if (current === lastSaved) return;
      // Un cambio nuevo habilita un nuevo intento.
      if (failedSnapshot !== current) failedSnapshot = null;
      timer = setTimeout(save, delay);
      setStatus(form, 'saving', 'Pendiente…');
    };

    form.addEventListener('input', e => {
      if (e.target.matches('input[type="checkbox"],input[type="radio"],select,input[type="date"]')) return;
      schedule(550);
    });

    // Selects, switches y fechas se guardan rápido, pero siempre en secuencia.
    form.addEventListener('change', () => schedule(120));
    form.addEventListener('focusout', e => {
      if (form.contains(e.relatedTarget)) return;
      schedule(50);
    });

    form.addEventListener('submit', e => {
      e.preventDefault();
      schedule(0);
    });
  });

  // Mantiene únicos los favoritos dentro de cada grupo. Si se elige un concepto
  // que ya estaba en otra posición, se libera la posición anterior.
  root.querySelectorAll('[data-quick-access-group]').forEach(group => {
    group.addEventListener('change', event => {
      const select = event.target.closest('select');
      if (!select || !select.value) return;
      group.querySelectorAll('select').forEach(other => {
        if (other !== select && other.value === select.value) other.value = '';
      });
    });
  });

  const goalType=document.querySelector('#settingsGoalModal select[name="type"]');
  const goalSavingsAccount=document.querySelector('#settingsGoalModal [data-goal-savings-account]');
  function syncGoalSavingsFields(){if(goalSavingsAccount&&goalType)goalSavingsAccount.hidden=goalType.value!=='savings';}
  goalType?.addEventListener('change',syncGoalSavingsFields);syncGoalSavingsFields();

  return {
    refresh: () => window.MiDinero.softRefresh({preserveScroll:true,noFallback:true}),
    destroy: () => pageAbort.abort()
  };
});
