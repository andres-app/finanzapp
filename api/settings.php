<?php
// Endpoint estable para el autoguardado de Configuración.
// Evita depender de reescrituras de rutas amigables en peticiones POST.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => 'Método no permitido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require __DIR__ . '/../public/configuracion.php';
