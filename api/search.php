<?php
require __DIR__.'/../app/bootstrap.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$uid = require_auth(); // ID propietario del hogar compartido.
$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') json_response(['ok'=>true,'query'=>'','results'=>[]]);
$q = function_exists('mb_substr') ? mb_substr($q, 0, 80) : substr($q, 0, 80);
$like = '%'.$q.'%';
$results = [];

$push = static function(array $row) use (&$results): void {
    if (count($results) >= 40) return;
    $results[] = $row;
};
$tableExists = static function(string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $st->execute([$table]);
        return $cache[$table] = ((int)$st->fetchColumn() > 0);
    } catch (Throwable $e) { return $cache[$table] = false; }
};
$columnExists = static function(string $table, string $column): bool {
    static $cache = [];
    $key = $table.'.'.$column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $st->execute([$table,$column]);
        return $cache[$key] = ((int)$st->fetchColumn() > 0);
    } catch (Throwable $e) { return $cache[$key] = false; }
};

// También permite buscar montos escritos como "S/ 300", "300,50" o "300.50".
$numeric = preg_replace('/[^0-9,.-]/u', '', $q) ?? '';
$numeric = str_replace(',', '.', $numeric);
$amountLike = $numeric !== '' ? '%'.$numeric.'%' : $like;

// Movimientos. La consulta se adapta a instalaciones que todavía no tengan
// alguna columna de auditoría, evitando que todo el buscador deje de funcionar.
if ($tableExists('transactions') && $tableExists('categories')) {
    try {
        $hasConcepts = $tableExists('concepts');
        $hasAccounts = $tableExists('financial_accounts') && $columnExists('transactions','account_id');
        $hasActor = $columnExists('transactions','created_by_user_id');
        $hasVoided = $columnExists('transactions','voided_at');
        $conceptJoin = $hasConcepts ? ' LEFT JOIN concepts co ON co.id=t.concept_id ' : '';
        $accountJoin = $hasAccounts ? ' LEFT JOIN financial_accounts a ON a.id=t.account_id ' : '';
        $actorExpr = $hasActor ? 'COALESCE(t.created_by_user_id,t.user_id)' : 't.user_id';
        $actorJoin = ' LEFT JOIN users u ON u.id='.$actorExpr.' ';
        $voidFilter = $hasVoided ? ' AND t.voided_at IS NULL ' : '';
        $conceptName = $hasConcepts ? 'co.name' : 'NULL';
        $accountName = $hasAccounts ? 'a.name' : 'NULL';
        $whereConcept = $hasConcepts ? ' OR co.name LIKE ? ' : '';
        $whereAccount = $hasAccounts ? ' OR a.name LIKE ? ' : '';

        $sql = "SELECT t.id,t.type,t.amount,t.occurred_at,t.description,c.name category,{$conceptName} concept,{$accountName} account,u.name actor
                FROM transactions t
                JOIN categories c ON c.id=t.category_id
                {$conceptJoin}{$accountJoin}{$actorJoin}
                WHERE t.user_id=? {$voidFilter}
                  AND (c.name LIKE ? {$whereConcept} OR t.description LIKE ? {$whereAccount} OR u.name LIKE ? OR CAST(t.amount AS CHAR) LIKE ?)
                ORDER BY t.occurred_at DESC,t.id DESC LIMIT 14";
        $params = [$uid,$like];
        if ($hasConcepts) $params[] = $like;
        $params[] = $like; // description
        if ($hasAccounts) $params[] = $like;
        $params[] = $like; // actor
        $params[] = $amountLike;
        $st = db()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $r) {
            $period = substr((string)$r['occurred_at'],0,7);
            $parts = [];
            if (!empty($r['account'])) $parts[] = $r['account'];
            if (!empty($r['actor'])) $parts[] = $r['actor'];
            if (!empty($r['occurred_at'])) $parts[] = date('d/m/Y',strtotime($r['occurred_at']));
            $push([
                'kind'=>'Movimiento','icon'=>$r['type']==='income'?'+':'−',
                'title'=>$r['concept'] ?: $r['category'],
                'subtitle'=>implode(' · ',$parts),
                'amount'=>(float)$r['amount'],'tone'=>$r['type'],
                'url'=>app_url('movimientos?period='.$period)
            ]);
        }
    } catch (Throwable $e) {
        error_log('[MiDinero search transactions] '.$e->getMessage());
    }
}

if ($tableExists('recurring_payments')) {
    try {
        $st = db()->prepare("SELECT id,name,amount,due_day FROM recurring_payments
            WHERE user_id=? AND active=1 AND (name LIKE ? OR CAST(amount AS CHAR) LIKE ?)
            ORDER BY name LIMIT 10");
        $st->execute([$uid,$like,$amountLike]);
        foreach ($st->fetchAll() as $r) {
            $push(['kind'=>'Pago fijo','icon'=>'P','title'=>$r['name'],
                'subtitle'=>'Compromiso mensual · vence día '.(int)$r['due_day'],
                'amount'=>(float)$r['amount'],'tone'=>'payment','url'=>app_url('configuracion/pagos')]);
        }
    } catch (Throwable $e) { error_log('[MiDinero search payments] '.$e->getMessage()); }
}

// Cuentas y fondos usan los servicios del sistema para respetar exactamente los saldos vigentes.
try {
    foreach (FinanceService::accountBalances($uid) as $r) {
        $name = (string)($r['name'] ?? '');
        $balance = (float)($r['balance'] ?? 0);
        $balancePlain = number_format($balance,2,'.','');
        if (stripos($name,$q)!==false || ($numeric!=='' && stripos($balancePlain,$numeric)!==false)) {
            $push(['kind'=>'Cuenta','icon'=>'C','title'=>$name,'subtitle'=>'Saldo disponible',
                'amount'=>$balance,'tone'=>'account','url'=>app_url('cuentas')]);
        }
    }
} catch (Throwable $e) { error_log('[MiDinero search accounts] '.$e->getMessage()); }

try {
    foreach (FinanceService::funds($uid) as $r) {
        $title = !empty($r['savings_goal_name']) ? (string)$r['savings_goal_name'] : (string)($r['name'] ?? 'Fondo');
        $available = (float)($r['available'] ?? 0);
        $plain = number_format($available,2,'.','');
        if (stripos($title,$q)!==false || ($numeric!=='' && stripos($plain,$numeric)!==false)) {
            $isSaving = !empty($r['savings_goal_id']);
            $push(['kind'=>$isSaving?'Ahorro':'Fondo','icon'=>'F','title'=>$title,
                'subtitle'=>$isSaving?'Meta de ahorro':'Dinero separado',
                'amount'=>$available,'tone'=>'fund','url'=>$isSaving?app_url('ahorro'):app_url('fondos')]);
        }
    }
} catch (Throwable $e) { error_log('[MiDinero search funds] '.$e->getMessage()); }

if ($tableExists('finance_audit_log')) {
    try {
        $st = db()->prepare("SELECT al.id,al.title,al.summary,al.created_at,u.name actor
            FROM finance_audit_log al LEFT JOIN users u ON u.id=al.actor_user_id
            WHERE al.user_id=? AND (al.title LIKE ? OR al.summary LIKE ? OR u.name LIKE ?)
            ORDER BY al.created_at DESC,al.id DESC LIMIT 10");
        $st->execute([$uid,$like,$like,$like]);
        foreach ($st->fetchAll() as $r) {
            $push(['kind'=>'Actividad','icon'=>'A','title'=>$r['title'],
                'subtitle'=>($r['actor']?:'Usuario').' · '.date('d/m/Y H:i',strtotime($r['created_at'])),
                'amount'=>null,'tone'=>'audit','url'=>app_url('actividad')]);
        }
    } catch (Throwable $e) { error_log('[MiDinero search audit] '.$e->getMessage()); }
}

if ($tableExists('concepts') && $tableExists('categories')) {
    try {
        $st = db()->prepare("SELECT co.id,co.name,c.name category
            FROM concepts co JOIN categories c ON c.id=co.category_id
            WHERE co.user_id=? AND co.active=1 AND (co.name LIKE ? OR c.name LIKE ?)
            ORDER BY co.name LIMIT 8");
        $st->execute([$uid,$like,$like]);
        foreach ($st->fetchAll() as $r) {
            $push(['kind'=>'Concepto','icon'=>'•','title'=>$r['name'],'subtitle'=>'Categoría: '.$r['category'],
                'amount'=>null,'tone'=>'concept','url'=>app_url('configuracion/conceptos')]);
        }
    } catch (Throwable $e) { error_log('[MiDinero search concepts] '.$e->getMessage()); }
}

json_response(['ok'=>true,'query'=>$q,'results'=>$results]);
