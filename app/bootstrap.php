<?php
$config = require __DIR__ . '/../config.php';
date_default_timezone_set($config['app']['timezone'] ?? 'America/Lima');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['app']['session_name'] ?? 'finanzas_rt');
    ini_set('session.cookie_httponly','1');
    ini_set('session.cookie_samesite','Lax');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if ($https) ini_set('session.cookie_secure','1');
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
    $st = db()->prepare('INSERT INTO realtime_events(user_id,event_type,payload_json,created_at) VALUES(?,?,?,NOW())');
    $st->execute([$userId,$type,json_encode($payload, JSON_UNESCAPED_UNICODE)]);
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
