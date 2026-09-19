<?php
function nav_svg(string $name): string {
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.75 7.5A2.25 2.25 0 0 1 6 5.25h3A2.25 2.25 0 0 1 11.25 7.5v3A2.25 2.25 0 0 1 9 12.75H6A2.25 2.25 0 0 1 3.75 10.5v-3Zm9 0A2.25 2.25 0 0 1 15 5.25h3A2.25 2.25 0 0 1 20.25 7.5v7A2.25 2.25 0 0 1 18 16.75h-3a2.25 2.25 0 0 1-2.25-2.25v-7Zm-9 9A2.25 2.25 0 0 1 6 14.25h3a2.25 2.25 0 0 1 2.25 2.25v1.5A2.25 2.25 0 0 1 9 20.25H6A2.25 2.25 0 0 1 3.75 18v-1.5Zm9 1.5A2.25 2.25 0 0 1 15 15.75h3A2.25 2.25 0 0 1 20.25 18v.75A1.5 1.5 0 0 1 18.75 20.25h-4.5a1.5 1.5 0 0 1-1.5-1.5V18Z"/></svg>',
        'movimientos' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 7.5h12m0 0-3-3m3 3-3 3M19.5 16.5h-12m0 0 3-3m-3 3 3 3"/></svg>',
        'actividad' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 6v6l3.75 2.25M21 12a9 9 0 1 1-2.64-6.36"/></svg>',
        'calendario' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8.25 3.75v3m7.5-3v3m-11.25 3h15m-15.75 7.5V6.75A2.25 2.25 0 0 1 6 4.5h12a2.25 2.25 0 0 1 2.25 2.25v11.25A2.25 2.25 0 0 1 18 20.25H6A2.25 2.25 0 0 1 3.75 18Zm4.5-3.75h3v3h-3v-3Z"/></svg>',
        'planificador' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.75 4.5h10.5A2.25 2.25 0 0 1 19.5 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25H6.75A2.25 2.25 0 0 1 4.5 17.25V6.75A2.25 2.25 0 0 1 6.75 4.5Zm2.25 3.75h6m-6 4.5h6m-6 4.5h3"/></svg>',
        'cuentas' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.75 9 12 4.5 20.25 9M5.25 9.75v8.25m4.5-8.25v8.25m4.5-8.25v8.25m4.5-8.25v8.25M3.75 19.5h16.5"/></svg>',
        'fondos' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 7.5V6.75a6 6 0 1 1 12 0v.75m-12 0h12A2.25 2.25 0 0 1 20.25 9.75v8.25A2.25 2.25 0 0 1 18 20.25H6A2.25 2.25 0 0 1 3.75 18V9.75A2.25 2.25 0 0 1 6 7.5Zm6 4.5v3"/></svg>',
        'ahorro' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7.5 9.75h4.125m1.875 0h.75m-6.375 6h6.375m-3-12c4.556 0 8.25 2.798 8.25 6.25 0 1.814-1.02 3.448-2.644 4.59l.394 2.16h-3.112l-.175-.7A10.62 10.62 0 0 1 12 16.25c-.68 0-1.345-.064-1.986-.185l-.176.685H6.726l.395-2.16C5.498 13.448 4.5 11.814 4.5 10c0-3.452 3.694-6.25 8.25-6.25Z"/></svg>',
        'configuracion' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 0 0-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 0 0-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 0 0-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 0 0-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 0 0 1.066-2.573c-.94-1.543.826-3.31 2.37-2.37 1 .608 2.296.07 2.572-1.065ZM12 15.75A3.75 3.75 0 1 0 12 8.25a3.75 3.75 0 0 0 0 7.5Z"/></svg>',
        'help' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 18h.008v.008H12V18Zm0-2.25c0-2.25 3-2.625 3-5.25a3 3 0 0 0-6 0"/><path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z"/></svg>',
        'logout' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.75 8.25 19.5 12l-3.75 3.75M19.5 12H9.75m4.5-7.5h-6A2.25 2.25 0 0 0 6 6.75v10.5a2.25 2.25 0 0 0 2.25 2.25h6"/></svg>',
        'collapse' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6 9 12l6 6"/></svg>',
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
    <style>
      .side-logout-form{margin:0;padding:0}
      .side-logout-button{width:100%;min-height:42px;padding:0 8px;border:0;background:transparent;color:#67707e;font:inherit;font-size:12px;font-weight:600;display:flex;align-items:center;gap:11px;border-radius:12px;cursor:pointer;text-align:left}
      .side-logout-button:hover{background:#f7f8fa;color:#111827}
      body.sidebar-collapsed .side-logout-button{justify-content:center;padding-left:0;padding-right:0}
      @media(max-width:760px){body.sidebar-collapsed .side-logout-button{justify-content:flex-start;padding-left:8px;padding-right:8px}}
    </style>
</head>
<body>
<div class="app-frame">
    <aside class="sidebar" id="sidebar">
        <div class="side-brand-row">
            <a class="side-brand" href="<?=e(app_url('dashboard'))?>" aria-label="Finanzapp" title="Finanzapp">
                <span class="logo-symbol"><i></i><i></i><i></i><i></i></span>
                <b>Finanzapp</b>
            </a>
            <button class="side-collapse-btn" type="button" data-sidebar-toggle aria-label="Colapsar menú" aria-expanded="true" title="Colapsar menú">
                <?=nav_svg('collapse')?>
            </button>
        </div>

        <nav class="side-nav" aria-label="Navegación principal">
            <a class="<?=$active==='dashboard'?'active':''?>" href="<?=e(app_url('dashboard'))?>" title="Dashboard"><span class="nav-ico"><?=nav_svg('dashboard')?></span><span class="nav-label">Dashboard</span></a>
            <a class="<?=$active==='movimientos'?'active':''?>" href="<?=e(app_url('movimientos'))?>" title="Movimientos"><span class="nav-ico"><?=nav_svg('movimientos')?></span><span class="nav-label">Movimientos</span></a>
            <a class="<?=$active==='actividad'?'active':''?>" href="<?=e(app_url('actividad'))?>" title="Actividad"><span class="nav-ico"><?=nav_svg('actividad')?></span><span class="nav-label">Actividad</span></a>
            <a class="<?=$active==='calendario'?'active':''?>" href="<?=e(app_url('calendario'))?>" title="Calendario"><span class="nav-ico"><?=nav_svg('calendario')?></span><span class="nav-label">Calendario</span></a>
            <a class="<?=$active==='planificador'?'active':''?>" href="<?=e(app_url('planificador'))?>" title="Planificador"><span class="nav-ico"><?=nav_svg('planificador')?></span><span class="nav-label">Planificador</span></a>
            <a class="<?=$active==='cuentas'?'active':''?>" href="<?=e(app_url('cuentas'))?>" title="Cuentas"><span class="nav-ico"><?=nav_svg('cuentas')?></span><span class="nav-label">Cuentas</span></a>
            <a class="<?=$active==='fondos'?'active':''?>" href="<?=e(app_url('fondos'))?>" title="Fondos"><span class="nav-ico"><?=nav_svg('fondos')?></span><span class="nav-label">Fondos</span></a>
            <a class="<?=$active==='ahorro'?'active':''?>" href="<?=e(app_url('ahorro'))?>" title="Ahorro"><span class="nav-ico"><?=nav_svg('ahorro')?></span><span class="nav-label">Ahorro</span></a>
            <a class="<?=$active==='configuracion'?'active':''?>" href="<?=e(app_url('configuracion'))?>" title="Configuración"><span class="nav-ico"><?=nav_svg('configuracion')?></span><span class="nav-label">Configuración</span></a>
        </nav>

        <div class="side-help">
            <a href="<?=e(app_url('configuracion'))?>" title="Ayuda y configuración"><span class="circle-icon"><?=nav_svg('help')?></span><span class="side-help-label">Ayuda y configuración</span></a>
            <form method="post" action="<?=e(app_url('logout'))?>" class="side-logout-form" data-hard-logout>
                <button type="submit" class="side-logout-button" title="Cerrar sesión" aria-label="Cerrar sesión"><span class="circle-icon"><?=nav_svg('logout')?></span><span class="side-help-label">Cerrar sesión</span></button>
            </form>
        </div>
    </aside>

    <main class="main">
        <header class="mobile-topbar">
            <button class="menu-btn" type="button" aria-label="Abrir menú" onclick="document.body.classList.toggle('menu-open')">☰</button>
            <a class="mobile-brand" href="<?=e(app_url('dashboard'))?>">Finanzapp</a>
            <button class="mobile-search-trigger" type="button" data-global-search-trigger aria-label="Buscar">⌕</button>
            <button class="mobile-alerts-trigger" type="button" data-global-alerts-trigger aria-label="Alertas">🔔<em data-alerts-badge hidden>0</em></button>
            <span class="live-dot"><i></i> vivo</span>
        </header>
        <header class="app-topbar" id="appTopbar">
            <div class="app-topbar-context">
                <span>FINANZAPP</span>
                <strong id="appHeaderPageTitle"><?=e($title)?></strong>
            </div>
            <div class="app-topbar-actions">
                <button class="app-header-search" type="button" data-global-search-trigger aria-label="Buscar en Finanzapp">
                    <span class="app-header-search-icon">⌕</span><span>Buscar</span><kbd>Ctrl K</kbd>
                </button>
                <button class="app-header-alerts" type="button" data-global-alerts-trigger aria-label="Abrir centro de alertas">
                    <span>🔔</span><em data-alerts-badge hidden>0</em>
                </button>
                <div class="global-register-wrap">
                    <button class="app-header-register" type="button" data-global-register-trigger aria-haspopup="menu" aria-expanded="false"><span>＋</span><b>Registrar</b></button>
                    <div class="register-menu global-register-menu" role="menu" aria-hidden="true">
                        <button type="button" role="menuitem" data-global-register-action="expense"><span class="register-menu-icon expense">↓</span><span><b>Gasté</b><small>Registrar un gasto</small></span></button>
                        <button type="button" role="menuitem" data-global-register-action="income"><span class="register-menu-icon income">+</span><span><b>Recibí dinero</b><small>Registrar un ingreso</small></span></button>
                        <button type="button" role="menuitem" data-global-register-action="transfer"><span class="register-menu-icon transfer">↔</span><span><b>Moví dinero</b><small>Entre tus cuentas</small></span></button>
                        <button type="button" role="menuitem" data-global-register-action="allocate"><span class="register-menu-icon allocate">◎</span><span><b>Separé dinero</b><small>Reservar en un fondo</small></span></button>
                        <a class="register-menu-saving" role="menuitem" href="<?=e(app_url('ahorro?action=deposit'))?>"><span class="register-menu-icon saving">◆</span><span><b>Guardar en Ahorro</b><small>Proteger dinero en tu chanchito</small></span></a>
                    </div>
                </div>
                <span class="app-header-live"><i></i> En línea</span>
                <span class="app-header-avatar" title="<?=e($u['name'] ?? '')?>"><?=e($initials)?></span>
            </div>
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
<div class="global-register-wrap mobile-register-fab-wrap" aria-label="Acciones rápidas">
    <button class="mobile-register-fab" type="button" data-global-register-trigger aria-haspopup="menu" aria-expanded="false" aria-label="Registrar movimiento">
        <span aria-hidden="true">＋</span><b>Registrar</b>
    </button>
    <div class="register-menu global-register-menu mobile-register-menu" role="menu" aria-hidden="true">
        <button type="button" role="menuitem" data-global-register-action="expense"><span class="register-menu-icon expense">↓</span><span><b>Gasté</b><small>Registrar un gasto</small></span></button>
        <button type="button" role="menuitem" data-global-register-action="income"><span class="register-menu-icon income">+</span><span><b>Recibí dinero</b><small>Registrar un ingreso</small></span></button>
        <button type="button" role="menuitem" data-global-register-action="transfer"><span class="register-menu-icon transfer">↔</span><span><b>Moví dinero</b><small>Entre tus cuentas</small></span></button>
        <button type="button" role="menuitem" data-global-register-action="allocate"><span class="register-menu-icon allocate">◎</span><span><b>Separé dinero</b><small>Reservar en un fondo</small></span></button>
        <a class="register-menu-saving" role="menuitem" href="<?=e(app_url('ahorro?action=deposit'))?>"><span class="register-menu-icon saving">◆</span><span><b>Guardar en Ahorro</b><small>Proteger dinero en tu chanchito</small></span></a>
    </div>
</div>
<div class="mobile-overlay" onclick="document.body.classList.remove('menu-open')"></div>
</body>
</html>
<?php }
