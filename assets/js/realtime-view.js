// Compatibilidad del módulo Movimientos con el shell realtime global.
// La conexión EventSource única vive en app-shell.js.
window.MiDineroRegister?.('movimientos', () => ({
  refresh: () => window.MiDinero.softRefresh({preserveScroll:true,noFallback:true})
}));
