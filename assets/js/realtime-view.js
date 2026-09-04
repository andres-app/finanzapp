(() => {
  if (!window.APP?.apiBase || !window.EventSource) return;
  const es = new EventSource(`${APP.apiBase}/stream.php`);
  let timer = null;
  es.addEventListener('change', () => {
    clearTimeout(timer);
    timer = setTimeout(() => location.reload(), 350);
  });
})();
