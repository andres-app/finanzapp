<?php
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'u274409976_finanzas',
        'user' => 'u274409976_finanzas',
        'pass' => 'Dev2804751$$$',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'Mi Dinero',
        'timezone' => 'America/Lima',
        'base_url' => '',
        'session_name' => 'finanzas_rt',
    ],
    'mail' => [
        // El hosting debe tener mail() habilitado. Si usas SMTP, reemplaza app/Mailer.php por PHPMailer.
        'from_email' => 'no-reply@tudominio.com',
        'from_name' => 'Mi Dinero',
    ],
];
