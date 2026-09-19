<?php
$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['app']['timezone'] ?? 'America/Lima');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['app']['session_name'] ?? 'finanzas_rt');

    // La sesión local también usa una cookie persistente. Si el hosting elimina
    // el archivo de sesión, AuthPersistence la reconstruye con un token seguro.
    $sessionTtl = 315360000; // 10 años; se elimina expresamente al cerrar sesión.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', (string)$sessionTtl);

    session_set_cookie_params([
        'lifetime' => $sessionTtl,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

spl_autoload_register(function ($class) {
    $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) require_once $file;
});

function db(): PDO {
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $d = $config['db'];
    $dsn = "mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}";
    $pdo = new PDO($dsn, $d['user'], $d['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // PHP trabaja en America/Lima, pero muchos hostings mantienen MySQL en UTC.
    // Sin alinear la sesión SQL, NOW() puede guardar pagos y asignaciones con +5 horas.
    $tzName = (string)($config['app']['timezone'] ?? 'America/Lima');
    try {
        $tz = new DateTimeZone($tzName);
        $offset = (new DateTimeImmutable('now', $tz))->format('P'); // p.ej. -05:00
        $pdo->exec("SET time_zone=" . $pdo->quote($offset));
    } catch (Throwable $e) {
        // No bloquear la aplicación si el servidor no acepta cambios de zona horaria.
    }
    return $pdo;
}

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

/**
 * Genera URLs limpias. En un subdirectorio coloca, por ejemplo:
 * 'base_url' => '/finanzas'
 * o una URL completa: 'https://finanzas.midominio.com'
 */
function app_url(string $path = ''): string {
    global $config;
    $base = trim((string)($config['app']['base_url'] ?? ''));
    $path = ltrim($path, '/');
    if ($base !== '') return rtrim($base, '/') . ($path !== '' ? '/' . $path : '');
    return '/' . $path;
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function actual_user_id(): int { return (int)($_SESSION['user_id'] ?? 0); }
function require_auth(): int {
    if (empty($_SESSION['user_id'])) {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) json_response(['ok'=>false,'message'=>'Sesión expirada'], 401);
        header('Location: ' . app_url('login')); exit;
    }
    $actor = (int)$_SESSION['user_id'];
    $scope = HouseholdSchema::scopeOwnerUserId($actor);
    try { db()->exec('SET @app_actor_user_id='.(int)$actor); } catch (Throwable $e) {}
    return $scope;
}
function current_household(): ?array {
    $id=actual_user_id();
    return $id ? HouseholdSchema::contextForUser($id) : null;
}
function current_household_role(): string {
    $ctx=current_household(); return (string)($ctx['role'] ?? 'member');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}
function verify_csrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) json_response(['ok'=>false,'message'=>'Token CSRF inválido'], 419);
}

function finance_lock_user(int $userId): void {
    if (!db()->inTransaction()) throw new RuntimeException('La operación financiera debe ejecutarse dentro de una transacción.');
    $st = db()->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
    $st->execute([$userId]);
    if (!$st->fetchColumn()) throw new RuntimeException('Usuario no válido.');
}

function parse_local_datetime(string $value): ?string {
    $value = trim(str_replace('T', ' ', $value));
    if (strlen($value) === 16) $value .= ':00';
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$dt || (is_array($errors) && ($errors['warning_count'] || $errors['error_count']))) return null;
    return $dt->format('Y-m-d H:i:s');
}

function request_json(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $_POST;
}
function month_range(string $period): array {
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');
    $start = new DateTimeImmutable($period . '-01 00:00:00');
    $end = $start->modify('+1 month');
    return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
}
function emit_event(int $userId, string $type, array $payload = []): void {
    // Identifica la pestaña que originó el cambio. Así el realtime actualiza
    // otros dispositivos/pestañas sin recargar el formulario que acaba de guardar.
    $sourceClient = trim((string)($_SERVER['HTTP_X_CLIENT_ID'] ?? ''));
    if ($sourceClient !== '') {
        $sourceClient = preg_replace('/[^A-Za-z0-9._:-]/', '', $sourceClient) ?: '';
        if ($sourceClient !== '') $payload['source_client_id'] = substr($sourceClient, 0, 80);
    }
    $st = db()->prepare('INSERT INTO realtime_events(user_id,event_type,payload_json,created_at) VALUES(?,?,?,NOW())');
    $st->execute([$userId,$type,json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}
function current_user(): ?array {
    static $cachedUserId = null;
    static $cachedUser = null;
    if (empty($_SESSION['user_id'])) return null;
    $userId = (int)$_SESSION['user_id'];
    if ($cachedUserId === $userId && is_array($cachedUser)) return $cachedUser;
    $st = db()->prepare('SELECT id,name,email,notify_email,notify_on_income,notify_on_expense FROM users WHERE id=? LIMIT 1');
    $st->execute([$userId]);
    $cachedUserId = $userId;
    $cachedUser = $st->fetch() ?: null;
    return $cachedUser;
}
function user_initials(?string $name): string {
    $parts = preg_split('/\s+/', trim((string)$name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return 'MD';
    $first = mb_substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? mb_substr($parts[count($parts)-1], 0, 1) : '';
    return mb_strtoupper($first . $last);
}

// En la ruta de cierre de sesión no se debe restaurar ni renovar el acceso
// persistente. Así evitamos que el mismo request de /logout vuelva a autenticar
// al usuario antes de que logout.php elimine las cookies y el token persistente.
$requestPath = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '');
$isLogoutRequest = (bool)preg_match('~/(?:public/)?logout(?:\.php)?/?$~i', $requestPath);

if (!$isLogoutRequest) {
    // Si PHP perdió la sesión del servidor pero el navegador conserva el acceso
    // persistente, se reconstruye automáticamente sin pedir usuario/contraseña.
    if (empty($_SESSION['user_id'])) {
        try {
            AuthPersistence::restore();
        } catch (Throwable $e) {
            error_log('Finanzapp persistent auth bootstrap: ' . $e->getMessage());
        }
    } else {
        // Mantiene renovada la cookie de sesión mientras el usuario use la app.
        try {
            AuthPersistence::refreshSessionCookie();
        } catch (Throwable $e) {
            // No interrumpir la aplicación por un problema al renovar la cookie.
        }
    }
}

