<?php
require __DIR__.'/../app/bootstrap.php';
$uid = require_auth();

/*
 * CRÍTICO PARA RENDIMIENTO:
 * PHP bloquea por defecto la sesión mientras una petición está abierta.
 * Un SSE es una petición larga; si no liberamos la sesión, cualquier cambio
 * de /dashboard a /movimientos o /configuracion puede quedarse esperando.
 */
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
@set_time_limit(0);
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');

$incomingLast = (int)($_SERVER['HTTP_LAST_EVENT_ID'] ?? ($_GET['last_id'] ?? 0));

// Primera conexión: empezamos desde el último evento existente. La página ya
// carga su estado actual por API, así evitamos reproducir 20 eventos antiguos
// y disparar 20 recargas del dashboard.
if ($incomingLast > 0) {
    $last = $incomingLast;
} else {
    $st = db()->prepare('SELECT COALESCE(MAX(id),0) FROM realtime_events WHERE user_id=?');
    $st->execute([$uid]);
    $last = (int)$st->fetchColumn();
}

echo "retry: 1500\n\n";
@ob_flush();
@flush();

$start = time();
while (!connection_aborted() && time() - $start < 20) {
    $st = db()->prepare('SELECT id,event_type FROM realtime_events WHERE user_id=? AND id>? ORDER BY id ASC LIMIT 50');
    $st->execute([$uid, $last]);
    $rows = $st->fetchAll();

    if ($rows) {
        $last = (int)$rows[count($rows)-1]['id'];
        $types = array_values(array_unique(array_column($rows, 'event_type')));
        echo "id: {$last}\n";
        echo "event: change\n";
        echo 'data: ' . json_encode(['types'=>$types], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    } else {
        echo ": ping\n\n";
    }

    @ob_flush();
    @flush();
    usleep(850000);
}
