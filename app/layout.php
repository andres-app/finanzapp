<?php
function nav_svg(string $name): string {
    $icons = [
        'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 13h6V4H4v9Zm0 7h6v-4H4v4Zm10 0h6v-9h-6v9Zm0-13h6V4h-6v3Z"/></svg>',
        'movimientos' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h13M17 4l3 3-3 3M17 17H4m3-3-3 3 3 3"/></svg>',
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

        <nav class="side-nav">
            <a class="<?=$active==='dashboard'?'active':''?>" href="<?=e(app_url('dashboard'))?>"><?=nav_svg('dashboard')?><span>Dashboard</span></a>
            <a class="<?=$active==='movimientos'?'active':''?>" href="<?=e(app_url('movimientos'))?>"><?=nav_svg('movimientos')?><span>Movimientos</span></a>
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
            <a class="mobile-quick-add" href="<?=e(app_url('dashboard?action=choose'))?>" aria-label="Registrar">＋</a>
            <span class="live-dot"><i></i> vivo</span>
        </header>
<?php
    echo '<script>window.APP={csrf:' . json_encode(csrf_token()) . ',apiBase:' . json_encode($apiBase) . ',routes:{dashboard:' . json_encode(app_url('dashboard')) . ',movimientos:' . json_encode(app_url('movimientos')) . ',cuentas:' . json_encode(app_url('cuentas')) . ',fondos:' . json_encode(app_url('fondos')) . ',ahorro:' . json_encode(app_url('ahorro')) . ',presupuesto:' . json_encode(app_url('ahorro')) . ',configuracion:' . json_encode(app_url('configuracion')) . '}};</script>';
    echo '<script src="' . e(app_url('assets/js/app-shell.js')) . '?v=' . e((string)@filemtime(__DIR__.'/../assets/js/app-shell.js')) . '"></script>';
?>
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
