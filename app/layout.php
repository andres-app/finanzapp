<?php
function nav_svg(string $name): string {
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h6V4H4v9Zm0 7h6v-4H4v4Zm10 0h6v-9h-6v9Zm0-13h6V4h-6v3Z"/></svg>',
        'movimientos' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h13M17 4l3 3-3 3M17 17H4m3-3-3 3 3 3"/></svg>',
        'actividad' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9h-2a7 7 0 1 1-2.05-4.95L14 10h7V3l-2.63 2.63A8.96 8.96 0 0 0 12 3Zm-1 4h2v5.2l3.3 2-1 1.7L11 13.3V7Z"/></svg>',
        'calendario' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2v3m10-3v3M4 9h16M5 4h14a2 2 0 0 1 2 2v14H3V6a2 2 0 0 1 2-2Zm2 9h3v3H7v-3Zm5 0h3v3h-3v-3Z"/></svg>',
        'planificador' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h14v18H5V3Zm3 3h8v3H8V6Zm0 6h2v2H8v-2Zm4 0h4v2h-4v-2Zm-4 4h2v2H8v-2Zm4 0h4v2h-4v-2Z"/></svg>',
        'cuentas' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7.5 12 3l9 4.5V10H3V7.5ZM5 12h2v6H5v-6Zm6 0h2v6h-2v-6Zm6 0h2v6h-2v-6ZM3 20h18"/></svg>',
        'fondos' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 9h14a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Zm2 0V7a5 5 0 0 1 10 0v2m-5 4v3"/></svg>',
        'ahorro' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3c4.4 0 8 2.7 8 6.1 0 2.1-1.2 4-3.2 5.1V19h-3v-2.2c-.6.1-1.2.2-1.8.2s-1.2-.1-1.8-.2V19h-3v-4.8C5.2 13.1 4 11.2 4 9.1 4 5.7 7.6 3 12 3Zm-2.5 5.2h5M17.8 7H20v3"/></svg>',
        'configuracion' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15.5A3.5 3.5 0 1 0 12 8a3.5 3.5 0 0 0 0 7.5ZM19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.37a1.7 1.7 0 0 0-1 .63 1.7 1.7 0 0 0-.4 1v.1H9.6V21a1.7 1.7 0 0 0-.4-1 1.7 1.7 0 0 0-1-.63 1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 3.83 15a1.7 1.7 0 0 0-.63-1 1.7 1.7 0 0 0-1-.4h-.1V9.6h.1a1.7 1.7 0 0 0 1-.4 1.7 1.7 0 0 0 .63-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 8.2 3.83a1.7 1.7 0 0 0 1-.63 1.7 1.7 0 0 0 .4-1v-.1h4v.1a1.7 1.7 0 0 0 .4 1 1.7 1.7 0 0 0 1 .63 1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.37 8.2c.12.38.34.72.63 1 .28.23.63.37 1 .4h.1v4h-.1a1.7 1.7 0 0 0-1 .4c-.29.28-.51.62-.63 1Z"/></svg>',
    ];
    return $icons[$name] ?? '';
}

function page_top(string $title, string $active = 'dashboard'): void {
    global $config;
    $u = current_user();
    $initials = user_initials($u['name'] ?? '');
    $apiBase = rtrim(app_url('api'), '/');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="theme-color" content="#f5f5f3">
    <title><?=e($title)?> · <?=e($config['app']['name'])?></title>
    <link rel="stylesheet" href="<?=e(app_url('assets/css/app.css'))?>?v=<?=e((string)@filemtime(__DIR__.'/../assets/css/app.css'))?>">
</head>
<body>
<div class="app-frame">
    <aside class="sidebar" id="sidebar">
        <a class="side-brand" href="<?=e(app_url('dashboard'))?>" aria-label="Mi Dinero">
            <span class="logo-symbol"><i></i><i></i><i></i><i></i></span>
            <b>mi dinero</b>
        </a>

        <div class="side-global-actions">
            <button class="global-search-trigger" id="globalSearchTrigger" data-global-search-trigger type="button" aria-label="Buscar en Mi Dinero"><span class="global-search-trigger-icon">⌕</span><span>Buscar</span><kbd>Ctrl K</kbd></button>
            <button class="global-alerts-trigger" data-global-alerts-trigger type="button" aria-label="Abrir centro de alertas"><span class="global-alerts-bell">🔔</span><span>Alertas</span><em data-alerts-badge hidden>0</em></button>
        </div>

        <nav class="side-nav">
            <a class="<?=$active==='dashboard'?'active':''?>" href="<?=e(app_url('dashboard'))?>"><?=nav_svg('dashboard')?><span>Dashboard</span></a>
            <a class="<?=$active==='movimientos'?'active':''?>" href="<?=e(app_url('movimientos'))?>"><?=nav_svg('movimientos')?><span>Movimientos</span></a>
            <a class="<?=$active==='actividad'?'active':''?>" href="<?=e(app_url('actividad'))?>"><?=nav_svg('actividad')?><span>Actividad</span></a>
            <a class="<?=$active==='calendario'?'active':''?>" href="<?=e(app_url('calendario'))?>"><?=nav_svg('calendario')?><span>Calendario</span></a>
            <a class="<?=$active==='planificador'?'active':''?>" href="<?=e(app_url('planificador'))?>"><?=nav_svg('planificador')?><span>Planificador</span></a>
            <a class="<?=$active==='cuentas'?'active':''?>" href="<?=e(app_url('cuentas'))?>"><?=nav_svg('cuentas')?><span>Cuentas</span></a>
            <a class="<?=$active==='fondos'?'active':''?>" href="<?=e(app_url('fondos'))?>"><?=nav_svg('fondos')?><span>Fondos</span></a>
            <a class="<?=$active==='ahorro'?'active':''?>" href="<?=e(app_url('ahorro'))?>"><?=nav_svg('ahorro')?><span>Ahorro</span></a>
            <a class="<?=$active==='configuracion'?'active':''?>" href="<?=e(app_url('configuracion'))?>"><?=nav_svg('configuracion')?><span>Configuración</span></a>
        </nav>

        <div class="side-simple-actions">
            <a class="side-register" href="<?=e(app_url('dashboard?action=choose'))?>"><span>＋</span><b>Registrar</b></a>
            <p>Gasto, ingreso, mover o separar dinero.</p>
        </div>

        <div class="side-help">
            <a href="<?=e(app_url('configuracion'))?>"><span class="circle-icon">?</span> Ayuda y configuración</a>
            <a href="<?=e(app_url('logout'))?>"><span class="circle-icon">−</span> Cerrar sesión</a>
        </div>
    </aside>

    <main class="main">
        <header class="mobile-topbar">
            <button class="menu-btn" type="button" aria-label="Abrir menú" onclick="document.body.classList.toggle('menu-open')">☰</button>
            <a class="mobile-brand" href="<?=e(app_url('dashboard'))?>">mi dinero</a>
            <button class="mobile-search-trigger" type="button" data-global-search-trigger aria-label="Buscar">⌕</button>
            <button class="mobile-alerts-trigger" type="button" data-global-alerts-trigger aria-label="Alertas">🔔<em data-alerts-badge hidden>0</em></button>
            <a class="mobile-quick-add" href="<?=e(app_url('dashboard?action=choose'))?>" aria-label="Registrar">＋</a>
            <span class="live-dot"><i></i> vivo</span>
        </header>
<?php
    echo '<script>window.APP={csrf:' . json_encode(csrf_token()) . ',apiBase:' . json_encode($apiBase) . ',routes:{dashboard:' . json_encode(app_url('dashboard')) . ',movimientos:' . json_encode(app_url('movimientos')) . ',actividad:' . json_encode(app_url('actividad')) . ',calendario:' . json_encode(app_url('calendario')) . ',planificador:' . json_encode(app_url('planificador')) . ',cierre:' . json_encode(app_url('cierre')) . ',cuentas:' . json_encode(app_url('cuentas')) . ',fondos:' . json_encode(app_url('fondos')) . ',ahorro:' . json_encode(app_url('ahorro')) . ',presupuesto:' . json_encode(app_url('ahorro')) . ',configuracion:' . json_encode(app_url('configuracion')) . '}};</script>';
    echo '<script src="' . e(app_url('assets/js/app-shell.js')) . '?v=' . e((string)@filemtime(__DIR__.'/../assets/js/app-shell.js')) . '"></script>';
?>
        <div class="global-search-modal" id="globalSearchModal" aria-hidden="true">
            <button class="global-search-backdrop" type="button" data-global-search-close aria-label="Cerrar búsqueda"></button>
            <div class="global-search-card" role="dialog" aria-modal="true" aria-label="Buscador global">
                <div class="global-search-box"><span>⌕</span><input id="globalSearchInput" type="search" placeholder="Busca WIN, alquiler, 300, Lissette…" autocomplete="off"><button type="button" data-global-search-close>ESC</button></div>
                <div class="global-search-hint"><span>Busca movimientos, pagos, cuentas, fondos y actividad.</span><kbd>Ctrl K</kbd></div>
                <div class="global-search-results" id="globalSearchResults"><div class="global-search-empty"><b>Busca cualquier cosa de tus finanzas</b><span>Prueba: WIN, alquiler, 300, Lissette…</span></div></div>
            </div>
        </div>
<?php echo '<script src="' . e(app_url('assets/js/global-search.js')) . '?v=' . e((string)@filemtime(__DIR__.'/../assets/js/global-search.js')) . '"></script>'; ?>
        <div class="global-alerts-modal" id="globalAlertsModal" aria-hidden="true">
            <button class="global-alerts-backdrop" type="button" data-global-alerts-close aria-label="Cerrar alertas"></button>
            <aside class="global-alerts-panel" role="dialog" aria-modal="true" aria-label="Centro de alertas">
                <div class="global-alerts-head"><div><span>ALERTAS</span><h2>Lo que requiere atención</h2></div><button type="button" data-global-alerts-close aria-label="Cerrar">×</button></div>
                <div class="global-alerts-list" id="globalAlertsList"><div class="global-alerts-loading">Cargando alertas…</div></div>
                <div class="global-alerts-foot"><a href="<?=e(app_url('calendario'))?>">Abrir calendario financiero</a><a href="<?=e(app_url('planificador'))?>">Simular una compra</a></div>
            </aside>
        </div>
<?php echo '<script src="' . e(app_url('assets/js/global-alerts.js')) . '?v=' . e((string)@filemtime(__DIR__.'/../assets/js/global-alerts.js')) . '"></script>'; ?>
        <section class="content" data-page="<?=e($active)?>">
<?php
}

function profile_card(): void {
    $u = current_user();
    $initials = user_initials($u['name'] ?? '');
?>
<div class="profile-card">
    <div class="avatar-ring"><div class="avatar"><?=e($initials)?></div><span></span></div>
    <strong><?=e($u['name'] ?? '')?></strong>
    <small><?=e($u['email'] ?? '')?></small>
    <div class="profile-actions">
        <a href="<?=e(app_url('movimientos'))?>" title="Movimientos">↕</a>
        <a href="<?=e(app_url('configuracion'))?>" title="Configuración">⚙</a>
        <button type="button" title="Más opciones">⋮</button>
    </div>
</div>
<?php }

function page_bottom(): void { ?>
        </section>
    </main>
</div>
<div class="mobile-overlay" onclick="document.body.classList.remove('menu-open')"></div>
</body>
</html>
<?php }
