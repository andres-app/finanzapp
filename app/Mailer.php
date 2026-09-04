<?php
class Mailer {
    public static function transaction(array $user, array $tx): bool {
        global $config;
        $to = trim($user['notify_email'] ?: $user['email']);
        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
        if ($tx['type'] === 'income' && empty($user['notify_on_income'])) return false;
        if ($tx['type'] === 'expense' && empty($user['notify_on_expense'])) return false;

        $kind = $tx['type'] === 'income' ? 'Ingreso' : 'Egreso';
        $subject = "{$kind} registrado: S/ " . number_format((float)$tx['amount'], 2);
        $body = "Se registró un {$kind} en Mi Dinero.\n\n"
              . "Monto: S/ " . number_format((float)$tx['amount'],2) . "\n"
              . "Concepto: " . ($tx['concept_name'] ?: $tx['category_name']) . "\n"
              . "Fecha: " . $tx['occurred_at'] . "\n"
              . "Descripción: " . ($tx['description'] ?: '-') . "\n";
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . ($config['mail']['from_name'] ?? 'Mi Dinero') . ' <' . ($config['mail']['from_email'] ?? 'no-reply@localhost') . '>'
        ];
        $ok = @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $body, implode("\r\n", $headers));
        try {
            $st = db()->prepare('INSERT INTO email_log(user_id,recipient,subject,status,created_at) VALUES(?,?,?,?,NOW())');
            $st->execute([$user['id'],$to,$subject,$ok ? 'sent' : 'failed']);
        } catch (Throwable $e) {}
        return $ok;
    }
}
