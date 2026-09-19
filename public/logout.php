<?php
require __DIR__.'/../app/bootstrap.php';

// Esta respuesta nunca debe quedarse en caché ni volver por bfcache como una
// pantalla autenticada después de cerrar sesión.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// Revoca primero el token que permite reconstruir la sesión automáticamente.
try {
    AuthPersistence::revokeCurrent(true);
} catch (Throwable $e) {
    error_log('Finanzapp persistent auth logout: ' . $e->getMessage());
    // Aun si la BD falla, intentamos borrar explícitamente la cookie persistente.
    try { AuthPersistence::clearCookie(); } catch (Throwable $ignored) {}
}

// Vacía completamente la sesión PHP.
$_SESSION = [];

if (session_status() === PHP_SESSION_ACTIVE) {
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        $cookieOptions = [
            'expires' => time() - 86400,
            'path' => $params['path'] ?: '/',
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        $domain = trim((string)($params['domain'] ?? ''));
        if ($domain !== '') $cookieOptions['domain'] = $domain;
        setcookie(session_name(), '', $cookieOptions);

        // Refuerzo para instalaciones antiguas que pudieron crear la cookie en /.
        if (($params['path'] ?? '/') !== '/') {
            $rootOptions = $cookieOptions;
            $rootOptions['path'] = '/';
            setcookie(session_name(), '', $rootOptions);
        }
    }

    session_destroy();
}

// Evita que el código posterior del request pueda reutilizar valores de cookie.
unset($_COOKIE[session_name()]);
unset($_COOKIE[AuthPersistence::cookieName()]);

// 303 fuerza una navegación limpia al login incluso cuando el cierre vino por POST.
header('Location: '.app_url('login').'?logged_out=1', true, 303);
exit;
