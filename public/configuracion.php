<?php
require __DIR__ . '/../app/bootstrap.php';
$uid = require_auth();
FinanceSchema::ensure($uid);
require __DIR__ . '/../app/layout.php';

const SETTINGS_TABS = ['conceptos','pagos','ingresos-fijos','categorias','metas','hogar','notificaciones'];

function cfg_tab(string $tab): string {
    return in_array($tab, SETTINGS_TABS, true) ? $tab : 'conceptos';
}

function cfg_url(string $tab = 'conceptos'): string {
    return app_url('configuracion/' . cfg_tab($tab));
}

function cfg_is_ajax(): bool {
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function cfg_redirect(string $message, string $tab = 'conceptos', array $data = []): void {
    if (cfg_is_ajax()) {
        json_response(array_merge(['ok'=>true,'message'=>$message,'tab'=>cfg_tab($tab)], $data));
    }
    header('Location: ' . cfg_url($tab) . '?ok=' . rawurlencode($message));
    exit;
}

function cfg_fail(string $message, string $tab = 'conceptos', int $status = 422): void {
    if (cfg_is_ajax()) {
        json_response(['ok'=>false,'message'=>$message,'tab'=>cfg_tab($tab)], $status);
    }
    header('Location: ' . cfg_url($tab) . '?error=' . rawurlencode($message));
    exit;
}

function cfg_category_belongs(int $uid, int $categoryId): bool {
    $st = db()->prepare('SELECT 1 FROM categories WHERE id=? AND user_id=? AND active=1 LIMIT 1');
    $st->execute([$categoryId, $uid]);
    return (bool)$st->fetchColumn();
}

function cfg_category_is_type(int $uid, int $categoryId, string $type): bool {
    $st = db()->prepare('SELECT type FROM categories WHERE id=? AND user_id=? AND active=1 LIMIT 1');
    $st->execute([$categoryId, $uid]);
    $rowType = (string)($st->fetchColumn() ?: '');
    return $rowType === $type;
}

function cfg_fund_belongs(int $uid, ?int $fundId): bool {
    if (!$fundId) return true;
    $st = db()->prepare('SELECT 1 FROM funds WHERE id=? AND user_id=? AND active=1 LIMIT 1');
    $st->execute([$fundId,$uid]);
    return (bool)$st->fetchColumn();
}
function cfg_account_belongs(int $uid, ?int $accountId): bool {
    if (!$accountId) return true;
    $st = db()->prepare('SELECT 1 FROM financial_accounts WHERE id=? AND user_id=? AND active=1 LIMIT 1');
    $st->execute([$accountId,$uid]);
    return (bool)$st->fetchColumn();
}


// La interfaz trabaja con fechas reales; internamente conservamos el día del mes
// para mantener la recurrencia mensual sin cambiar el esquema existente.
function cfg_valid_date(string $value): ?DateTimeImmutable {
    $value = trim($value);
    if ($value === '') return null;
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$dt || $dt->format('Y-m-d') !== $value) return null;
    return $dt;
}

function cfg_day_from_date(string $value): int {
    $dt = cfg_valid_date($value);
    return $dt ? (int)$dt->format('j') : 0;
}

function cfg_date_for_period(string $period, int $day): string {
    if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = date('Y-m');
    [$year,$month] = array_map('intval', explode('-', $period));
    $maxDay = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $safeDay = max(1, min($day, $maxDay));
    return sprintf('%04d-%02d-%02d', $year, $month, $safeDay);
}

function cfg_next_occurrence_date(int $day): string {
    $day = max(1, min(31, $day));
    $today = new DateTimeImmutable('today');
    $cursor = $today->modify('first day of this month');
    for ($i = 0; $i < 18; $i++) {
        $year = (int)$cursor->format('Y');
        $month = (int)$cursor->format('m');
        $maxDay = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        if ($day <= $maxDay) {
            $candidate = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day));
            if ($candidate && $candidate >= $today) return $candidate->format('Y-m-d');
        }
        $cursor = $cursor->modify('first day of next month');
    }
    return cfg_date_for_period(date('Y-m'), $day);
}

function cfg_sync_pending_payment_dates(PDO $pdo, int $uid, int $recurringId, float $amount, int $dueDay): void {
    $fromPeriod = date('Y-m');
    $rows = $pdo->prepare("SELECT id,period,paid_amount,amount_overridden,due_date_overridden
        FROM monthly_payments
        WHERE user_id=? AND recurring_id=? AND status IN ('pending','partial') AND period>=? ORDER BY period");
    $rows->execute([$uid,$recurringId,$fromPeriod]);
    $update = $pdo->prepare("UPDATE monthly_payments
        SET amount=?,due_date=?,status=CASE WHEN paid_amount>0 AND paid_amount+0.005>=? THEN 'paid' WHEN paid_amount>0 THEN 'partial' ELSE 'pending' END,
            paid_at=CASE WHEN paid_amount>0 AND paid_amount+0.005>=? THEN COALESCE(paid_at,NOW()) ELSE NULL END
        WHERE id=? AND user_id=?");
    foreach ($rows->fetchAll() as $row) {
        $monthAmount = !empty($row['amount_overridden']) ? null : $amount;
        $monthDue = !empty($row['due_date_overridden']) ? null : cfg_date_for_period((string)$row['period'],$dueDay);
        if ($monthAmount === null && $monthDue === null) continue;
        // Conserva cualquier excepción hecha solo para ese mes.
        $current=$pdo->prepare('SELECT amount,due_date FROM monthly_payments WHERE id=? AND user_id=? LIMIT 1');
        $current->execute([(int)$row['id'],$uid]);
        $cur=$current->fetch();
        if(!$cur) continue;
        $effectiveAmount=$monthAmount===null?(float)$cur['amount']:$monthAmount;
        $effectiveDue=$monthDue===null?(string)$cur['due_date']:$monthDue;
        $update->execute([$effectiveAmount,$effectiveDue,$effectiveAmount,$effectiveAmount,(int)$row['id'],$uid]);
    }
}

function cfg_sync_income_expectation_dates(PDO $pdo, int $uid, int $recurringId, float $amount, int $incomeDay): void {
    try {
        $fromPeriod = date('Y-m');
        $rows = $pdo->prepare("SELECT id,period FROM monthly_income_expectations WHERE user_id=? AND recurring_id=? AND period>=? ORDER BY period");
        $rows->execute([$uid,$recurringId,$fromPeriod]);
        $update = $pdo->prepare('UPDATE monthly_income_expectations SET amount=?,due_date=? WHERE id=? AND user_id=?');
        foreach ($rows->fetchAll() as $row) {
            $update->execute([$amount,cfg_date_for_period((string)$row['period'],$incomeDay),(int)$row['id'],$uid]);
        }
    } catch (Throwable $e) {
        // La tabla es auxiliar; nunca debe impedir guardar la configuración base.
    }
}

function cfg_ensure_recurring_incomes_table(): void {
    static $ready = false;
    if ($ready) return;
    db()->exec("CREATE TABLE IF NOT EXISTS recurring_incomes (
        id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT(10) UNSIGNED NOT NULL,
        category_id INT(10) UNSIGNED NOT NULL,
        concept_id INT(10) UNSIGNED DEFAULT NULL,
        account_id INT(10) UNSIGNED DEFAULT NULL,
        name VARCHAR(140) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        income_day TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
        icon VARCHAR(20) NOT NULL DEFAULT '💰',
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_ri_user_active (user_id,active),
        KEY fk_ri_category (category_id),
        KEY fk_ri_concept (concept_id),
        KEY idx_ri_account (account_id),
        CONSTRAINT fk_ri_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_ri_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE RESTRICT,
        CONSTRAINT fk_ri_concept FOREIGN KEY (concept_id) REFERENCES concepts(id) ON DELETE SET NULL,
        CONSTRAINT fk_ri_account FOREIGN KEY (account_id) REFERENCES financial_accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
}

function cfg_find_or_create_concept(PDO $pdo, int $uid, int $categoryId, string $name, float $amount): int {
    $find = $pdo->prepare('SELECT id FROM concepts WHERE user_id=? AND category_id=? AND active=1 AND LOWER(name)=LOWER(?) ORDER BY id LIMIT 1');
    $find->execute([$uid, $categoryId, $name]);
    $id = (int)($find->fetchColumn() ?: 0);
    if ($id) {
        $sync = $pdo->prepare('UPDATE concepts SET default_amount=? WHERE id=? AND user_id=?');
        $sync->execute([$amount, $id, $uid]);
        return $id;
    }
    $ins = $pdo->prepare('INSERT INTO concepts(user_id,category_id,name,default_amount,is_ant_expense) VALUES(?,?,?,?,0)');
    $ins->execute([$uid, $categoryId, $name, $amount]);
    return (int)$pdo->lastInsertId();
}

try {
    cfg_ensure_recurring_incomes_table();
} catch (Throwable $e) {
    cfg_fail('No se pudo preparar el módulo de ingresos fijos. Verifica que el usuario MySQL tenga permisos CREATE TABLE.', 'ingresos-fijos', 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        cfg_fail('La sesión del formulario venció. Inténtalo nuevamente.', cfg_tab($_POST['return_tab'] ?? 'conceptos'), 419);
    }

    try {
        if ($action === 'category') {
            $name = trim($_POST['name'] ?? '');
            $type = $_POST['type'] ?? 'expense';
            if ($name === '' || !in_array($type, ['expense','income'], true)) {
                cfg_fail('Completa correctamente los datos de la categoría.', 'categorias');
            }
            $icon=trim($_POST['icon'] ?? '') ?: '💳';$color=$_POST['color'] ?? '#2563eb';$isAnt=!empty($_POST['is_ant_expense']) ? 1 : 0;
            $st = db()->prepare('INSERT INTO categories(user_id,name,type,icon,color,is_ant_expense) VALUES(?,?,?,?,?,?)');
            $st->execute([$uid,$name,$type,$icon,$color,$isAnt]);
            $categoryNewId=(int)db()->lastInsertId();
            FinanceAudit::record($uid,'category_created','category',$categoryNewId,'Categoría creada',$name,null,['name'=>$name,'type'=>$type,'icon'=>$icon,'color'=>$color,'is_ant_expense'=>$isAnt],null,false);
            emit_event($uid, 'config_changed');
            cfg_redirect('Categoría creada correctamente.', 'categorias');
        }

        if ($action === 'quick_access') {
            $normalizeQuickIds = static function($raw): array {
                if (!is_array($raw)) $raw = [$raw];
                $ids = [];
                foreach ($raw as $value) {
                    $id = (int)$value;
                    if ($id > 0 && !in_array($id, $ids, true)) $ids[] = $id;
                    if (count($ids) >= 5) break;
                }
                return $ids;
            };

            $expenseIds = $normalizeQuickIds($_POST['expense_quick_ids'] ?? []);
            $incomeIds = $normalizeQuickIds($_POST['income_quick_ids'] ?? []);
            $requested = array_values(array_unique(array_merge($expenseIds, $incomeIds)));

            $validMap = [];
            if ($requested) {
                $marks = implode(',', array_fill(0, count($requested), '?'));
                $params = array_merge([$uid], $requested);
                $check = db()->prepare("SELECT co.id,c.type
                    FROM concepts co
                    JOIN categories c ON c.id=co.category_id
                    WHERE co.user_id=? AND co.active=1 AND co.id IN ($marks)");
                $check->execute($params);
                foreach ($check->fetchAll() as $row) $validMap[(int)$row['id']] = (string)$row['type'];
            }

            $expenseIds = array_values(array_filter($expenseIds, fn($id) => ($validMap[$id] ?? '') === 'expense'));
            $incomeIds = array_values(array_filter($incomeIds, fn($id) => ($validMap[$id] ?? '') === 'income'));

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $before = $pdo->prepare("SELECT co.id,co.name,c.type,co.quick_access_order
                    FROM concepts co
                    JOIN categories c ON c.id=co.category_id
                    WHERE co.user_id=? AND co.active=1 AND co.is_quick_access=1
                    ORDER BY c.type,co.quick_access_order,co.name");
                $before->execute([$uid]);
                $beforeQuick = $before->fetchAll();

                $reset = $pdo->prepare('UPDATE concepts SET is_quick_access=0,quick_access_order=NULL WHERE user_id=?');
                $reset->execute([$uid]);
                $mark = $pdo->prepare('UPDATE concepts SET is_quick_access=1,quick_access_order=? WHERE id=? AND user_id=? AND active=1');

                foreach ($expenseIds as $index => $conceptId) $mark->execute([$index + 1, $conceptId, $uid]);
                foreach ($incomeIds as $index => $conceptId) $mark->execute([$index + 1, $conceptId, $uid]);

                $pdo->commit();

                FinanceAudit::record(
                    $uid,'quick_access_updated','settings',null,'Accesos rápidos actualizados',
                    'Se actualizaron los conceptos favoritos del registro rápido.',
                    $beforeQuick,
                    ['expense_ids'=>$expenseIds,'income_ids'=>$incomeIds],
                    null,false
                );
                emit_event($uid, 'config_changed', ['quick_access'=>true]);
                cfg_redirect('Accesos rápidos guardados.', 'conceptos');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        }

        if ($action === 'concept') {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            if (!$categoryId || !cfg_category_belongs($uid, $categoryId) || $name === '') {
                cfg_fail('Completa correctamente el concepto.', 'conceptos');
            }
            $defaultAmount = trim((string)($_POST['default_amount'] ?? ''));
            $conceptAmount=$defaultAmount !== '' ? max(0, (float)$defaultAmount) : null;$conceptAnt=!empty($_POST['is_ant_expense']) ? 1 : 0;
            $st = db()->prepare('INSERT INTO concepts(user_id,category_id,name,default_amount,is_ant_expense) VALUES(?,?,?,?,?)');
            $st->execute([$uid,$categoryId,$name,$conceptAmount,$conceptAnt]);
            $conceptNewId=(int)db()->lastInsertId();
            FinanceAudit::record($uid,'concept_created','concept',$conceptNewId,'Concepto creado',$name,null,['category_id'=>$categoryId,'name'=>$name,'default_amount'=>$conceptAmount,'is_ant_expense'=>$conceptAnt],null,false);
            emit_event($uid, 'config_changed');
            cfg_redirect('Concepto creado correctamente.', 'conceptos');
        }

        if ($action === 'concept_update') {
            $conceptId = (int)($_POST['concept_id'] ?? 0);
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $defaultAmount = trim((string)($_POST['default_amount'] ?? ''));
            if (!$conceptId || !$categoryId || !cfg_category_belongs($uid, $categoryId) || $name === '') {
                cfg_fail('No se pudo actualizar el concepto.', 'conceptos');
            }

            $linkedPayment = db()->prepare('SELECT 1 FROM recurring_payments WHERE user_id=? AND concept_id=? AND active=1 LIMIT 1');
            $linkedPayment->execute([$uid, $conceptId]);
            if ($linkedPayment->fetchColumn()) cfg_fail('Ese concepto pertenece a un pago fijo. Edítalo desde “Pagos fijos”.', 'pagos');

            $linkedIncome = db()->prepare('SELECT 1 FROM recurring_incomes WHERE user_id=? AND concept_id=? AND active=1 LIMIT 1');
            $linkedIncome->execute([$uid, $conceptId]);
            if ($linkedIncome->fetchColumn()) cfg_fail('Ese concepto pertenece a un ingreso fijo. Edítalo desde “Ingresos fijos”.', 'ingresos-fijos');

            $beforeSt=db()->prepare('SELECT id,category_id,name,default_amount,is_ant_expense FROM concepts WHERE id=? AND user_id=? AND active=1 LIMIT 1');
            $beforeSt->execute([$conceptId,$uid]);$beforeConcept=$beforeSt->fetch();
            $amount = $defaultAmount !== '' ? max(0, (float)$defaultAmount) : null;$conceptAnt=!empty($_POST['is_ant_expense']) ? 1 : 0;
            $st = db()->prepare('UPDATE concepts SET category_id=?,name=?,default_amount=?,is_ant_expense=? WHERE id=? AND user_id=? AND active=1');
            $st->execute([$categoryId,$name,$amount,$conceptAnt,$conceptId,$uid]);
            FinanceAudit::record($uid,'concept_updated','concept',$conceptId,'Concepto actualizado',$name,$beforeConcept,['id'=>$conceptId,'category_id'=>$categoryId,'name'=>$name,'default_amount'=>$amount,'is_ant_expense'=>$conceptAnt],null,false);
            emit_event($uid, 'config_changed', ['concept_id' => $conceptId]);
            cfg_redirect('Guardado automáticamente.', 'conceptos', ['concept_id'=>$conceptId]);
        }

        if ($action === 'recurring') {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $amount = max(0, (float)($_POST['amount'] ?? 0));
            $dueDay = cfg_day_from_date((string)($_POST['due_date'] ?? ''));
            $icon = trim($_POST['icon'] ?? '') ?: '📌';
            $fundId = !empty($_POST['fund_id']) ? (int)$_POST['fund_id'] : null;
            if (!$categoryId || !$dueDay || !cfg_category_is_type($uid, $categoryId, 'expense') || !cfg_fund_belongs($uid,$fundId) || $name === '') {
                cfg_fail('Completa correctamente el pago fijo y utiliza una categoría de egreso.', 'pagos');
            }

            $pdo = db();
            $pdo->beginTransaction();
            $conceptId = cfg_find_or_create_concept($pdo, $uid, $categoryId, $name, $amount);
            $linked = $pdo->prepare('SELECT 1 FROM recurring_payments WHERE user_id=? AND concept_id=? AND active=1 LIMIT 1');
            $linked->execute([$uid,$conceptId]);
            if ($linked->fetchColumn()) {
                $pdo->rollBack();
                cfg_fail('Ese concepto ya está configurado como pago fijo.', 'pagos');
            }
            $st = $pdo->prepare('INSERT INTO recurring_payments(user_id,category_id,concept_id,fund_id,name,amount,due_day,icon) VALUES(?,?,?,?,?,?,?,?)');
            $st->execute([$uid,$categoryId,$conceptId,$fundId,$name,$amount,$dueDay,$icon]);
            $recurringNewId=(int)$pdo->lastInsertId();
            FinanceAudit::record($uid,'recurring_payment_created','recurring_payment',$recurringNewId,'Pago fijo creado',$name.' · S/ '.number_format($amount,2),null,['category_id'=>$categoryId,'concept_id'=>$conceptId,'fund_id'=>$fundId,'name'=>$name,'amount'=>$amount,'due_day'=>$dueDay,'icon'=>$icon],null,false);
            $pdo->commit();
            emit_event($uid, 'config_changed');
            cfg_redirect('Pago fijo creado. El concepto quedó vinculado automáticamente.', 'pagos');
        }

        if ($action === 'recurring_update') {
            $recurringId = (int)($_POST['recurring_id'] ?? 0);
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $amount = max(0, (float)($_POST['amount'] ?? 0));
            $dueDay = cfg_day_from_date((string)($_POST['due_date'] ?? ''));
            $icon = trim($_POST['icon'] ?? '') ?: '📌';
            $fundId = !empty($_POST['fund_id']) ? (int)$_POST['fund_id'] : null;
            if (!$recurringId || !$categoryId || !$dueDay || !cfg_category_is_type($uid, $categoryId, 'expense') || !cfg_fund_belongs($uid,$fundId) || $name === '') {
                cfg_fail('No se pudo actualizar el pago fijo.', 'pagos');
            }

            $pdo = db();
            $pdo->beginTransaction();
            $get = $pdo->prepare('SELECT id,category_id,concept_id,fund_id,name,amount,due_day,icon FROM recurring_payments WHERE id=? AND user_id=? AND active=1 FOR UPDATE');
            $get->execute([$recurringId, $uid]);
            $row = $get->fetch();
            if (!$row) {
                $pdo->rollBack();
                cfg_fail('El pago fijo no existe o no está disponible.', 'pagos');
            }

            $conceptId = (int)($row['concept_id'] ?? 0);
            if ($conceptId) {
                $sync = $pdo->prepare('UPDATE concepts SET category_id=?,name=?,default_amount=? WHERE id=? AND user_id=? AND active=1');
                $sync->execute([$categoryId,$name,$amount,$conceptId,$uid]);
            } else {
                $conceptId = cfg_find_or_create_concept($pdo, $uid, $categoryId, $name, $amount);
            }

            $st = $pdo->prepare('UPDATE recurring_payments SET category_id=?,concept_id=?,fund_id=?,name=?,amount=?,due_day=?,icon=? WHERE id=? AND user_id=? AND active=1');
            $st->execute([$categoryId,$conceptId,$fundId,$name,$amount,$dueDay,$icon,$recurringId,$uid]);
            FinanceAudit::record($uid,'recurring_payment_updated','recurring_payment',$recurringId,'Pago fijo actualizado',$name.' · S/ '.number_format($amount,2),$row,['id'=>$recurringId,'category_id'=>$categoryId,'concept_id'=>$conceptId,'fund_id'=>$fundId,'name'=>$name,'amount'=>$amount,'due_day'=>$dueDay,'icon'=>$icon],null,false);

            // El registro maestro es la fuente de verdad. Primero confirmamos el cambio.
            $pdo->commit();

            // Luego sincronizamos de inmediato el pago pendiente del mes actual y los
            // meses futuros. Así el Dashboard refleja el nuevo monto sin esperar otra
            // generación de obligaciones. Si esta tabla auxiliar falla, el cambio base
            // ya quedó guardado y no se pierde la edición del usuario.
            try {
                cfg_sync_pending_payment_dates($pdo, $uid, $recurringId, $amount, $dueDay);
            } catch (Throwable $syncError) {
                error_log('[MiDinero sync pago fijo] ' . $syncError->getMessage());
            }

            // Realtime se emite después de sincronizar para que otras pestañas lean
            // directamente el importe actualizado.
            try {
                emit_event($uid, 'config_changed', ['recurring_id' => $recurringId, 'amount' => $amount]);
            } catch (Throwable $eventError) {
                error_log('[MiDinero realtime config] ' . $eventError->getMessage());
            }
            cfg_redirect('Guardado automáticamente.', 'pagos', ['recurring_id'=>$recurringId,'amount'=>$amount,'due_day'=>$dueDay]);
        }

        if ($action === 'recurring_income') {
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $amount = max(0, (float)($_POST['amount'] ?? 0));
            $incomeDay = cfg_day_from_date((string)($_POST['income_date'] ?? ''));
            $icon = trim($_POST['icon'] ?? '') ?: '💰';
            $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
            if (!$categoryId || !$incomeDay || !cfg_category_is_type($uid, $categoryId, 'income') || !cfg_account_belongs($uid,$accountId) || $name === '') {
                cfg_fail('Completa correctamente el ingreso fijo y utiliza una categoría de ingreso.', 'ingresos-fijos');
            }

            $pdo = db();
            $pdo->beginTransaction();
            $conceptId = cfg_find_or_create_concept($pdo, $uid, $categoryId, $name, $amount);
            $linked = $pdo->prepare('SELECT 1 FROM recurring_incomes WHERE user_id=? AND concept_id=? AND active=1 LIMIT 1');
            $linked->execute([$uid,$conceptId]);
            if ($linked->fetchColumn()) {
                $pdo->rollBack();
                cfg_fail('Ese concepto ya está configurado como ingreso fijo.', 'ingresos-fijos');
            }
            $st = $pdo->prepare('INSERT INTO recurring_incomes(user_id,category_id,concept_id,account_id,name,amount,income_day,icon) VALUES(?,?,?,?,?,?,?,?)');
            $st->execute([$uid,$categoryId,$conceptId,$accountId,$name,$amount,$incomeDay,$icon]);
            $incomeNewId=(int)$pdo->lastInsertId();
            FinanceAudit::record($uid,'recurring_income_created','recurring_income',$incomeNewId,'Ingreso fijo creado',$name.' · S/ '.number_format($amount,2),null,['category_id'=>$categoryId,'concept_id'=>$conceptId,'account_id'=>$accountId,'name'=>$name,'amount'=>$amount,'income_day'=>$incomeDay,'icon'=>$icon],null,false);
            $pdo->commit();
            emit_event($uid, 'config_changed');
            cfg_redirect('Ingreso fijo creado. El concepto quedó vinculado automáticamente.', 'ingresos-fijos');
        }

        if ($action === 'recurring_income_update') {
            $recurringId = (int)($_POST['recurring_id'] ?? 0);
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $amount = max(0, (float)($_POST['amount'] ?? 0));
            $incomeDay = cfg_day_from_date((string)($_POST['income_date'] ?? ''));
            $icon = trim($_POST['icon'] ?? '') ?: '💰';
            $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;
            if (!$recurringId || !$categoryId || !$incomeDay || !cfg_category_is_type($uid, $categoryId, 'income') || !cfg_account_belongs($uid,$accountId) || $name === '') {
                cfg_fail('No se pudo actualizar el ingreso fijo.', 'ingresos-fijos');
            }

            $pdo = db();
            $pdo->beginTransaction();
            $get = $pdo->prepare('SELECT id,category_id,concept_id,account_id,name,amount,income_day,icon FROM recurring_incomes WHERE id=? AND user_id=? AND active=1 FOR UPDATE');
            $get->execute([$recurringId, $uid]);
            $row = $get->fetch();
            if (!$row) {
                $pdo->rollBack();
                cfg_fail('El ingreso fijo no existe o no está disponible.', 'ingresos-fijos');
            }

            $conceptId = (int)($row['concept_id'] ?? 0);
            if ($conceptId) {
                $sync = $pdo->prepare('UPDATE concepts SET category_id=?,name=?,default_amount=?,is_ant_expense=0 WHERE id=? AND user_id=? AND active=1');
                $sync->execute([$categoryId,$name,$amount,$conceptId,$uid]);
            } else {
                $conceptId = cfg_find_or_create_concept($pdo, $uid, $categoryId, $name, $amount);
            }

            $st = $pdo->prepare('UPDATE recurring_incomes SET category_id=?,concept_id=?,account_id=?,name=?,amount=?,income_day=?,icon=? WHERE id=? AND user_id=? AND active=1');
            $st->execute([$categoryId,$conceptId,$accountId,$name,$amount,$incomeDay,$icon,$recurringId,$uid]);
            FinanceAudit::record($uid,'recurring_income_updated','recurring_income',$recurringId,'Ingreso fijo actualizado',$name.' · S/ '.number_format($amount,2),$row,['id'=>$recurringId,'category_id'=>$categoryId,'concept_id'=>$conceptId,'account_id'=>$accountId,'name'=>$name,'amount'=>$amount,'income_day'=>$incomeDay,'icon'=>$icon],null,false);

            // Igual que con pagos fijos, la expectativa mensual es derivada y no debe
            // bloquear la edición del ingreso recurrente.
            $pdo->commit();

            try {
                emit_event($uid, 'config_changed', ['recurring_income_id' => $recurringId, 'amount' => $amount]);
            } catch (Throwable $eventError) {
                error_log('[MiDinero realtime config] ' . $eventError->getMessage());
            }
            cfg_redirect('Guardado automáticamente.', 'ingresos-fijos', ['recurring_income_id'=>$recurringId,'amount'=>$amount,'income_day'=>$incomeDay]);
        }

        if ($action === 'goal') {
            $period = $_POST['period'] ?? date('Y-m');
            $type = $_POST['type'] ?? 'savings';
            $name = trim($_POST['name'] ?? '');
            $target = max(0, (float)($_POST['target_amount'] ?? 0));
            if (!preg_match('/^\d{4}-\d{2}$/', $period) || !in_array($type, ['savings','income','expense_limit'], true) || $name === '') {
                cfg_fail('Completa correctamente los datos de la meta.', 'metas');
            }
            $st = db()->prepare('INSERT INTO goals(user_id,period,name,type,target_amount) VALUES(?,?,?,?,?)');
            $st->execute([$uid,$period,$name,$type,$target]);
            $goalNewId=(int)db()->lastInsertId();
            FinanceAudit::record($uid,'goal_created','goal',$goalNewId,'Meta financiera creada',$name.' · S/ '.number_format($target,2),null,['period'=>$period,'name'=>$name,'type'=>$type,'target_amount'=>$target],null,false);
            if($type==='savings') {
                // La cuenta física se elige al realizar cada aporte. Solo creamos/sincronizamos
                // el fondo virtual asociado a la meta.
                SavingsSchema::syncGoals($uid);
            }
            emit_event($uid, 'config_changed');
            cfg_redirect('Meta financiera creada.', 'metas');
        }

        if ($action === 'household_invite') {
            $email = trim($_POST['member_email'] ?? '');
            $member = HouseholdSchema::linkExistingUser(actual_user_id(), $email);
            FinanceAudit::record($uid,'household_member_added','household_member',(int)$member['id'],'Integrante agregado al hogar',$member['name'].' · '.$member['email'],null,['member_user_id'=>(int)$member['id'],'name'=>$member['name'],'email'=>$member['email']],null,false);
            emit_event($uid, 'household_changed', ['member_user_id'=>(int)$member['id']]);
            cfg_redirect('Integrante agregado. Ya puede ver y registrar en este mismo hogar.', 'hogar');
        }

        if ($action === 'household_remove') {
            $removedId=(int)($_POST['member_user_id'] ?? 0);
            $membersBefore=HouseholdSchema::membersForUser(actual_user_id());$removedName='Integrante';foreach($membersBefore as $mb)if((int)$mb['user_id']===$removedId){$removedName=$mb['name'];break;}
            HouseholdSchema::removeMember(actual_user_id(), $removedId);
            FinanceAudit::record($uid,'household_member_removed','household_member',$removedId,'Integrante retirado del hogar',$removedName,null,['member_user_id'=>$removedId,'name'=>$removedName],null,false);
            emit_event($uid, 'household_changed');
            cfg_redirect('Integrante retirado del hogar.', 'hogar');
        }

        if ($action === 'household_rename') {
            $ctxBefore=HouseholdSchema::contextForUser(actual_user_id());$newHouseholdName=trim($_POST['household_name'] ?? '');
            HouseholdSchema::rename(actual_user_id(), $newHouseholdName);
            FinanceAudit::record($uid,'household_renamed','household',(int)$ctxBefore['household_id'],'Hogar renombrado',$newHouseholdName,['name'=>$ctxBefore['household_name']],['name'=>$newHouseholdName],null,false);
            cfg_redirect('Nombre del hogar actualizado.', 'hogar');
        }

        if ($action === 'user') {
            $email = trim($_POST['notify_email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) cfg_fail('Ingresa un correo válido.', 'notificaciones');
            $notifyIncome=!empty($_POST['notify_on_income']) ? 1 : 0;$notifyExpense=!empty($_POST['notify_on_expense']) ? 1 : 0;
            $beforeUser=current_user();
            $st = db()->prepare('UPDATE users SET notify_email=?,notify_on_income=?,notify_on_expense=? WHERE id=?');
            $st->execute([$email,$notifyIncome,$notifyExpense,actual_user_id()]);
            FinanceAudit::record($uid,'notification_settings_updated','user',actual_user_id(),'Notificaciones actualizadas',$email,['notify_email'=>$beforeUser['notify_email']??null,'notify_on_income'=>$beforeUser['notify_on_income']??null,'notify_on_expense'=>$beforeUser['notify_on_expense']??null],['notify_email'=>$email,'notify_on_income'=>$notifyIncome,'notify_on_expense'=>$notifyExpense],null,false);
            cfg_redirect('Preferencias guardadas automáticamente.', 'notificaciones');
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[MiDinero configuracion] ' . $e->getMessage());
        cfg_fail('No se pudo guardar el cambio. Revisa los datos e inténtalo nuevamente.', cfg_tab($_POST['return_tab'] ?? 'conceptos'));
    }
}

$catsSt = db()->prepare('SELECT * FROM categories WHERE user_id=? AND active=1 ORDER BY FIELD(type,\'expense\',\'both\',\'income\'),name');
$catsSt->execute([$uid]);
$cats = $catsSt->fetchAll();

$consSt = db()->prepare('SELECT co.*,c.name category,c.icon category_icon,c.type category_type FROM concepts co JOIN categories c ON c.id=co.category_id WHERE co.user_id=? AND co.active=1 ORDER BY c.name,co.name');
$consSt->execute([$uid]);
$cons = $consSt->fetchAll();

$accountsSt = db()->prepare('SELECT id,name,icon FROM financial_accounts WHERE user_id=? AND active=1 ORDER BY id');
$accountsSt->execute([$uid]);
$financeAccounts = $accountsSt->fetchAll();
$savingsReady=false; try { SavingsSchema::ensure($uid); $savingsReady=true; } catch (Throwable $e) { error_log('[MiDinero config savings] '.$e->getMessage()); }
$financeFunds = array_values(array_filter(FinanceService::funds($uid),fn($f)=>empty($f['savings_goal_id'])));

$recSt = db()->prepare('SELECT r.*,c.name category,c.icon category_icon,co.name concept,f.name fund_name FROM recurring_payments r JOIN categories c ON c.id=r.category_id LEFT JOIN concepts co ON co.id=r.concept_id LEFT JOIN funds f ON f.id=r.fund_id WHERE r.user_id=? AND r.active=1 ORDER BY due_day,r.name');
$recSt->execute([$uid]);
$rec = $recSt->fetchAll();

$incomeRecSt = db()->prepare('SELECT r.*,c.name category,c.icon category_icon,co.name concept,a.name account_name FROM recurring_incomes r JOIN categories c ON c.id=r.category_id LEFT JOIN concepts co ON co.id=r.concept_id LEFT JOIN financial_accounts a ON a.id=r.account_id WHERE r.user_id=? AND r.active=1 ORDER BY income_day,r.name');
$incomeRecSt->execute([$uid]);
$incomeRec = $incomeRecSt->fetchAll();

$recConceptIds = [];
foreach ($rec as $r) if (!empty($r['concept_id'])) $recConceptIds[(int)$r['concept_id']] = true;
foreach ($incomeRec as $r) if (!empty($r['concept_id'])) $recConceptIds[(int)$r['concept_id']] = true;
$variableCons = array_values(array_filter($cons, fn($c) => empty($recConceptIds[(int)$c['id']])));
$expenseCons = array_values(array_filter($cons, fn($c) => ($c['category_type'] ?? '') === 'expense'));
$incomeCons = array_values(array_filter($cons, fn($c) => ($c['category_type'] ?? '') === 'income'));
$quickSort = static function(array &$rows): void {
    usort($rows, static fn($a,$b) =>
        ((int)($a['quick_access_order'] ?? 99) <=> (int)($b['quick_access_order'] ?? 99))
        ?: strcasecmp((string)$a['name'], (string)$b['name'])
    );
};
$expenseQuickCons = array_values(array_filter($expenseCons, fn($c) => !empty($c['is_quick_access'])));
$incomeQuickCons = array_values(array_filter($incomeCons, fn($c) => !empty($c['is_quick_access'])));
$quickSort($expenseQuickCons);
$quickSort($incomeQuickCons);

$goalsSt = db()->prepare("SELECT g.*,
    f.id fund_id,NULL account_id,NULL account_name,f.name fund_name
    FROM goals g
    LEFT JOIN funds f ON f.user_id=g.user_id AND f.active=1
      AND f.name LIKE CONCAT('__SAV7__',g.id,'__%')
    WHERE g.user_id=? AND g.period>=?
    ORDER BY g.period,g.id");
$goalsSt->execute([$uid, date('Y-m')]);
$goals = $goalsSt->fetchAll();
$u = current_user();
$household = HouseholdSchema::contextForUser(actual_user_id());
$householdMembers = HouseholdSchema::membersForUser(actual_user_id());
$canManageHousehold = HouseholdSchema::canManage(actual_user_id());
$expenseCats = array_values(array_filter($cats, fn($c) => $c['type'] === 'expense'));
$incomeCats = array_values(array_filter($cats, fn($c) => $c['type'] === 'income'));
$totalRecurring = array_sum(array_map(fn($r) => (float)$r['amount'], $rec));
$totalRecurringIncome = array_sum(array_map(fn($r) => (float)$r['amount'], $incomeRec));
$ok = trim($_GET['ok'] ?? '');
$error = trim($_GET['error'] ?? '');
$activeTab = cfg_tab($_GET['tab'] ?? 'conceptos');

$tabs = [
    'conceptos' => ['label'=>'Conceptos','hint'=>'Movimientos variables','count'=>count($variableCons),'icon'=>'⚡'],
    'pagos' => ['label'=>'Pagos fijos','hint'=>'Obligaciones mensuales','count'=>count($rec),'icon'=>'📌'],
    'ingresos-fijos' => ['label'=>'Ingresos fijos','hint'=>'Sueldos y rentas','count'=>count($incomeRec),'icon'=>'💰'],
    'categorias' => ['label'=>'Categorías','hint'=>'Orden del dinero','count'=>count($cats),'icon'=>'▦'],
    'metas' => ['label'=>'Metas','hint'=>'Planificación','count'=>count($goals),'icon'=>'◎'],
    'hogar' => ['label'=>'Hogar','hint'=>'Finanzas compartidas','count'=>count($householdMembers),'icon'=>'⌂'],
    'notificaciones' => ['label'=>'Notificaciones','hint'=>'Correo y alertas','count'=>null,'icon'=>'✉'],
];

page_top('Configuración', 'configuracion');
?>
<div class="settings-page settings-page-v2 settings-page-modals" data-settings-root data-active-tab="<?=e($activeTab)?>" data-autosave-url="<?=e(app_url('api/settings.php'))?>">
    <header class="settings-hero settings-hero-v2">
        <div>
            <span class="eyebrow">PREFERENCIAS DE FINANZAPP</span>
            <h1>Configuración</h1>
            <p>Administra cada parte del sistema desde una sección independiente.</p>
        </div>
    </header>

    <?php if ($ok): ?><div class="alert success settings-alert">✓ <?=e($ok)?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error settings-alert">! <?=e($error)?></div><?php endif; ?>

    <div class="settings-workspace">
        <nav class="settings-tabs-v2" aria-label="Secciones de configuración">
            <?php foreach ($tabs as $key => $tab): ?>
                <a href="<?=e(cfg_url($key))?>" data-settings-tab="<?=e($key)?>" class="<?=$activeTab===$key?'active':''?>">
                    <span class="settings-tab-icon" aria-hidden="true"><?=e($tab['icon'])?></span>
                    <span class="settings-tab-copy"><strong><?=e($tab['label'])?></strong><small><?=e($tab['hint'])?></small></span>
                    <?php if ($tab['count'] !== null): ?><b><?=e((string)$tab['count'])?></b><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <div class="settings-panel-wrap">
            <section class="settings-panel-v2" data-settings-panel="conceptos" <?=$activeTab==='conceptos'?'':'hidden'?>>
                <div class="settings-panel-head settings-panel-head-actions">
                    <div><span class="settings-kicker">MOVIMIENTOS VARIABLES</span><h2>Conceptos rápidos</h2><p>Úsalos para gastos e ingresos que cambian de monto o que no ocurren necesariamente todos los meses.</p></div>
                    <div class="settings-head-actions"><div class="settings-head-chip"><strong><?=count($variableCons)?></strong><span>conceptos</span></div><button type="button" class="settings-new-btn" data-settings-new="concept">Nuevo <b>+</b></button></div>
                </div>
                <div class="settings-info-strip"><span>i</span><p>Los <strong>pagos mensuales</strong> se administran en <a href="<?=e(cfg_url('pagos'))?>" data-settings-jump="pagos">Pagos fijos</a> y los <strong>sueldos o rentas recurrentes</strong> en <a href="<?=e(cfg_url('ingresos-fijos'))?>" data-settings-jump="ingresos-fijos">Ingresos fijos</a>. Así no se duplica información.</p></div>

                <div class="settings-card settings-quick-access-card">
                    <div class="settings-quick-access-head">
                        <div><span class="settings-kicker">FAVORITOS</span><strong>Accesos rápidos del registro</strong><small>Elige hasta 5 conceptos y ordénalos como quieres verlos encima del selector al registrar un gasto o un ingreso.</small></div>
                    </div>
                    <form method="post" class="settings-form settings-quick-access-form" data-autosave>
                        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                        <input type="hidden" name="action" value="quick_access">
                        <input type="hidden" name="return_tab" value="conceptos">
                        <div class="settings-quick-access-grid">
                            <div class="settings-quick-access-group" data-quick-access-group>
                                <div class="settings-quick-access-title"><span>↓</span><div><strong>Gastos favoritos</strong><small>Se muestran en “¿En qué gastaste?”</small></div></div>
                                <?php for ($i=0; $i<5; $i++): $selected=(int)($expenseQuickCons[$i]['id'] ?? 0); ?>
                                <label class="settings-quick-slot"><b><?=$i+1?></b><select name="expense_quick_ids[]"><option value="">Sin acceso rápido</option><?php foreach($expenseCons as $c): ?><option value="<?=$c['id']?>" <?=$selected===(int)$c['id']?'selected':''?>><?=e(($c['category_icon'] ?: '•').' '.$c['name'])?></option><?php endforeach; ?></select></label>
                                <?php endfor; ?>
                            </div>
                            <div class="settings-quick-access-group" data-quick-access-group>
                                <div class="settings-quick-access-title"><span>↑</span><div><strong>Ingresos favoritos</strong><small>Se muestran en “¿Qué dinero recibiste?”</small></div></div>
                                <?php for ($i=0; $i<5; $i++): $selected=(int)($incomeQuickCons[$i]['id'] ?? 0); ?>
                                <label class="settings-quick-slot"><b><?=$i+1?></b><select name="income_quick_ids[]"><option value="">Sin acceso rápido</option><?php foreach($incomeCons as $c): ?><option value="<?=$c['id']?>" <?=$selected===(int)$c['id']?'selected':''?>><?=e(($c['category_icon'] ?: '•').' '.$c['name'])?></option><?php endforeach; ?></select></label>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <span class="autosave-status saved settings-quick-access-save" data-autosave-status><i></i><span>Guardado</span></span>
                    </form>
                </div>

                <div class="settings-card settings-list-card settings-full-list">
                    <div class="settings-card-title"><div><strong>Mis conceptos</strong><small>Los cambios se guardan automáticamente mientras editas.</small></div><span>Autoguardado</span></div>
                    <?php if (!$variableCons): ?>
                        <div class="settings-empty settings-empty-large"><strong>No hay conceptos variables.</strong><span>Pulsa “Nuevo +” para crear compras, delivery o ingresos ocasionales.</span></div>
                    <?php else: ?>
                        <div class="concept-editor-list">
                            <?php foreach ($variableCons as $c): ?>
                                <form method="post" class="concept-editor-row" data-autosave>
                                    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="concept_update"><input type="hidden" name="return_tab" value="conceptos"><input type="hidden" name="concept_id" value="<?=$c['id']?>">
                                    <span class="concept-editor-icon"><?=e($c['category_icon'] ?: '•')?></span>
                                    <div class="concept-editor-main"><label>Concepto</label><input name="name" value="<?=e($c['name'])?>" required></div>
                                    <div class="concept-editor-category"><label>Categoría</label><select name="category_id" required><?php foreach ($cats as $cat): ?><option value="<?=$cat['id']?>" <?=$cat['id']==$c['category_id']?'selected':''?>><?=e(($cat['icon'] ?: '•').' '.$cat['name'])?></option><?php endforeach; ?></select></div>
                                    <div class="concept-editor-amount"><label>Monto sugerido</label><div class="settings-money-input"><span>S/</span><input type="number" min="0" step="0.01" name="default_amount" value="<?=$c['default_amount'] !== null ? e(number_format((float)$c['default_amount'], 2, '.', '')) : ''?>" placeholder="0.00"></div></div>
                                    <label class="settings-switch" title="Marcar como gasto hormiga"><input type="checkbox" name="is_ant_expense" <?=$c['is_ant_expense']?'checked':''?>><span></span><em>Hormiga</em></label>
                                    <span class="autosave-status saved" data-autosave-status><i></i><span>Guardado</span></span>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="settings-panel-v2" data-settings-panel="pagos" <?=$activeTab==='pagos'?'':'hidden'?>>
                <div class="settings-panel-head settings-panel-head-actions">
                    <div><span class="settings-kicker">EGRESOS RECURRENTES</span><h2>Pagos fijos</h2><p>Alquiler, mantenimiento, servicios, celulares, colegio y demás compromisos mensuales.</p></div>
                    <div class="settings-head-actions"><div class="settings-head-chip money"><strong>S/ <?=number_format($totalRecurring,2)?></strong><span>referencial / mes</span></div><button type="button" class="settings-new-btn" data-settings-new="payment">Nuevo <b>+</b></button></div>
                </div>
                <div class="settings-info-strip success"><span>✓</span><p><strong>Autoguardado activo:</strong> al cambiar nombre, categoría, monto o fecha de vencimiento, el sistema guarda sin botón y actualiza los pagos pendientes todavía no realizados, salvo los meses que hayas ajustado de forma individual.</p></div>

                <div class="settings-card settings-list-card settings-full-list">
                    <div class="settings-card-title"><div><strong>Pagos activos</strong><small>Un solo registro controla concepto, monto referencial, vencimiento y fondo.</small></div><span><?=count($rec)?> activos</span></div>
                    <?php if (!$rec): ?>
                        <div class="settings-empty settings-empty-large"><strong>No tienes pagos fijos.</strong><span>Pulsa “Nuevo +” para registrar tu primer compromiso mensual.</span></div>
                    <?php else: ?>
                        <div class="obligation-editor-list">
                            <?php foreach ($rec as $r): ?>
                                <form method="post" class="obligation-editor-row obligation-editor-row-v2" data-autosave>
                                    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="recurring_update"><input type="hidden" name="return_tab" value="pagos"><input type="hidden" name="recurring_id" value="<?=$r['id']?>">
                                    <div class="obligation-editor-service"><input class="obligation-icon-input" name="icon" value="<?=e($r['icon'] ?: '📌')?>" maxlength="20" aria-label="Icono"><div><label>Pago / servicio</label><input name="name" value="<?=e($r['name'])?>" required><small><?=e($r['category'])?> · concepto vinculado</small></div></div>
                                    <div class="obligation-editor-category"><label>Categoría</label><select name="category_id" required><?php foreach ($expenseCats as $c): ?><option value="<?=$c['id']?>" <?=$c['id']==$r['category_id']?'selected':''?>><?=e(($c['icon'] ?: '•').' '.$c['name'])?></option><?php endforeach; ?></select></div>
                                    <div class="obligation-editor-amount"><label>Monto mensual</label><div class="settings-money-input"><span>S/</span><input type="number" min="0" step="0.01" name="amount" value="<?=e(number_format((float)$r['amount'], 2, '.', ''))?>" required></div></div>
                                    <div class="obligation-editor-day obligation-editor-date"><label>Próximo vencimiento</label><input type="date" name="due_date" value="<?=e(cfg_next_occurrence_date((int)$r['due_day']))?>" required></div>
                                    <div class="obligation-editor-fund"><label>Fondo</label><select name="fund_id"><option value="">Sin fondo</option><?php foreach ($financeFunds as $f): ?><option value="<?=$f['id']?>" <?=$f['id']==$r['fund_id']?'selected':''?>><?=e(($f['icon'] ?: '•').' '.$f['name'])?></option><?php endforeach; ?></select></div>
                                    <span class="autosave-status saved" data-autosave-status><i></i><span>Guardado</span></span>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="settings-panel-v2" data-settings-panel="ingresos-fijos" <?=$activeTab==='ingresos-fijos'?'':'hidden'?>>
                <div class="settings-panel-head settings-panel-head-actions">
                    <div><span class="settings-kicker">INGRESOS RECURRENTES</span><h2>Ingresos fijos</h2><p>Registra sueldos, rentas, pensiones u otros ingresos que esperas recibir todos los meses.</p></div>
                    <div class="settings-head-actions"><div class="settings-head-chip money income"><strong>S/ <?=number_format($totalRecurringIncome,2)?></strong><span>esperado / mes</span></div><button type="button" class="settings-new-btn" data-settings-new="income">Nuevo <b>+</b></button></div>
                </div>
                <div class="settings-info-strip income"><span>↗</span><p>Al crear un ingreso fijo, el sistema <strong>reutiliza el concepto si ya existe</strong>. Por ejemplo, “Sueldo Lissette” se administra aquí y deja de duplicarse como concepto variable.</p></div>

                <div class="settings-card settings-list-card settings-full-list">
                    <div class="settings-card-title"><div><strong>Ingresos mensuales</strong><small>Los cambios se guardan automáticamente mientras editas.</small></div><span><?=count($incomeRec)?> activos</span></div>
                    <?php if (!$incomeRec): ?>
                        <div class="settings-empty settings-empty-large"><strong>Aún no tienes ingresos fijos.</strong><span>Pulsa “Nuevo +” para agregar sueldo, renta u otro ingreso mensual.</span></div>
                    <?php else: ?>
                        <div class="obligation-editor-list">
                            <?php foreach ($incomeRec as $r): ?>
                                <form method="post" class="obligation-editor-row obligation-editor-row-v2 income-fixed-row" data-autosave>
                                    <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="recurring_income_update"><input type="hidden" name="return_tab" value="ingresos-fijos"><input type="hidden" name="recurring_id" value="<?=$r['id']?>">
                                    <div class="obligation-editor-service"><input class="obligation-icon-input" name="icon" value="<?=e($r['icon'] ?: '💰')?>" maxlength="20" aria-label="Icono"><div><label>Ingreso</label><input name="name" value="<?=e($r['name'])?>" required><small><?=e($r['category'])?> · concepto vinculado</small></div></div>
                                    <div class="obligation-editor-category"><label>Categoría</label><select name="category_id" required><?php foreach ($incomeCats as $c): ?><option value="<?=$c['id']?>" <?=$c['id']==$r['category_id']?'selected':''?>><?=e(($c['icon'] ?: '•').' '.$c['name'])?></option><?php endforeach; ?></select></div>
                                    <div class="obligation-editor-amount"><label>Monto mensual</label><div class="settings-money-input"><span>S/</span><input type="number" min="0" step="0.01" name="amount" value="<?=e(number_format((float)$r['amount'], 2, '.', ''))?>" required></div></div>
                                    <div class="obligation-editor-day obligation-editor-date"><label>Próximo cobro</label><input type="date" name="income_date" value="<?=e(cfg_next_occurrence_date((int)$r['income_day']))?>" required></div>
                                    <div class="obligation-editor-fund"><label>Cuenta destino</label><select name="account_id"><?php foreach ($financeAccounts as $a): ?><option value="<?=$a['id']?>" <?=$a['id']==$r['account_id']?'selected':''?>><?=e(($a['icon'] ?: '•').' '.$a['name'])?></option><?php endforeach; ?></select></div>
                                    <span class="autosave-status saved" data-autosave-status><i></i><span>Guardado</span></span>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="settings-panel-v2" data-settings-panel="categorias" <?=$activeTab==='categorias'?'':'hidden'?>>
                <div class="settings-panel-head settings-panel-head-actions">
                    <div><span class="settings-kicker">ORGANIZACIÓN</span><h2>Categorías</h2><p>Define cómo se agrupan tus ingresos y egresos en el dashboard.</p></div>
                    <div class="settings-head-actions"><div class="settings-head-chip"><strong><?=count($cats)?></strong><span>categorías</span></div><button type="button" class="settings-new-btn" data-settings-new="category">Nuevo <b>+</b></button></div>
                </div>
                <div class="settings-card settings-full-list"><div class="category-management-grid"><?php if (!$cats): ?><div class="settings-empty settings-empty-large"><strong>No tienes categorías.</strong><span>Pulsa “Nuevo +” para crear una.</span></div><?php endif; ?><?php foreach ($cats as $c): ?><div class="category-management-item"><span class="category-management-icon" style="--cat-color:<?=e($c['color'] ?: '#e7e7e3')?>"><?=e($c['icon'] ?: '•')?></span><div><strong><?=e($c['name'])?></strong><small><?=$c['type']==='income'?'Ingreso':'Egreso'?></small></div><?php if ($c['is_ant_expense']): ?><span class="category-ant-badge">Hormiga</span><?php endif; ?></div><?php endforeach; ?></div></div>
            </section>

            <section class="settings-panel-v2" data-settings-panel="metas" <?=$activeTab==='metas'?'':'hidden'?>>
                <div class="settings-panel-head settings-panel-head-actions">
                    <div><span class="settings-kicker">PLANIFICACIÓN</span><h2>Metas financieras</h2><p>Define objetivos de ahorro, ingreso o límites de gasto por mes.</p></div>
                    <div class="settings-head-actions"><div class="settings-head-chip"><strong><?=count($goals)?></strong><span>próximas</span></div><button type="button" class="settings-new-btn" data-settings-new="goal">Nuevo <b>+</b></button></div>
                </div>
                <div class="settings-card settings-full-list"><div class="goal-settings-list"><?php if (!$goals): ?><div class="settings-empty settings-empty-large"><strong>Aún no tienes metas.</strong><span>Pulsa “Nuevo +” para crear tu primera meta.</span></div><?php endif; ?><?php foreach ($goals as $g): ?><div class="goal-settings-row"><span class="goal-settings-icon">◎</span><div><strong><?=e($g['name'])?></strong><small><?=e($g['period'])?> · <?=$g['type']==='savings'?'Ahorro'.(!empty($g['account_name'])?' · '.e($g['account_name']):' · cuenta por definir'):($g['type']==='income'?'Ingreso':'Tope de gasto')?></small></div><b>S/ <?=number_format((float)$g['target_amount'],2)?></b></div><?php endforeach; ?></div></div>
            </section>

            <section class="settings-panel-v2" data-settings-panel="hogar" <?=$activeTab==='hogar'?'':'hidden'?>>
                <div class="settings-panel-head settings-panel-head-actions">
                    <div><span class="settings-kicker">FINANZAS COMPARTIDAS</span><h2><?=e($household['household_name'])?></h2><p>Todos los integrantes de este hogar ven las mismas cuentas, movimientos, fondos, ahorro y pagos. Cada operación conserva quién la registró.</p></div>
                    <div class="settings-head-chip"><strong><?=count($householdMembers)?></strong><span>integrantes</span></div>
                </div>
                <div class="household-grid">
                    <div class="settings-card household-members-card">
                        <div class="settings-card-head"><div><strong>Integrantes</strong><small>Usuarios con acceso al mismo dinero familiar.</small></div></div>
                        <div class="household-members-list">
                            <?php foreach($householdMembers as $member): ?>
                                <div class="household-member-row">
                                    <div class="household-avatar"><?=e(user_initials($member['name']))?></div>
                                    <div class="household-member-copy"><strong><?=e($member['name'])?></strong><small><?=e($member['email'])?></small></div>
                                    <span class="household-role"><?=e($member['role']==='owner'?'Propietario':($member['role']==='admin'?'Administrador':'Miembro'))?></span>
                                    <?php if($household['role']==='owner' && (int)$member['user_id']!==(int)$household['owner_user_id']): ?>
                                    <form method="post" onsubmit="return confirm('¿Retirar a este integrante del hogar?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="household_remove"><input type="hidden" name="return_tab" value="hogar"><input type="hidden" name="member_user_id" value="<?=$member['user_id']?>"><button class="btn ghost household-remove" type="submit">Retirar</button></form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="settings-card household-share-card">
                        <span class="settings-kicker">COMPARTIR</span><h3>Agregar a Lissette u otro integrante</h3><p>Primero debe existir su usuario. Escribe el correo exacto con el que inicia sesión.</p>
                        <?php if($canManageHousehold): ?>
                        <form method="post" class="settings-form household-invite-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="household_invite"><input type="hidden" name="return_tab" value="hogar"><label>Correo del integrante</label><input type="email" name="member_email" placeholder="lissette@correo.com" required><button class="btn primary" type="submit">Compartir este hogar</button></form>
                        <form method="post" class="household-name-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="household_rename"><input type="hidden" name="return_tab" value="hogar"><label>Nombre del hogar</label><div class="household-name-line"><input name="household_name" value="<?=e($household['household_name'])?>" required><button class="btn secondary">Guardar</button></div></form>
                        <?php else: ?><div class="settings-inline-warning">Solo un administrador puede agregar integrantes.</div><?php endif; ?>
                        <div class="household-rule"><b>¿Qué comparte?</b><span>Cuentas · Movimientos · Pagos · Fondos · Ahorro · Metas · Categorías</span></div>
                    </div>
                </div>
            </section>

            <section class="settings-panel-v2" data-settings-panel="notificaciones" <?=$activeTab==='notificaciones'?'':'hidden'?>>
                <div class="settings-panel-head"><div><span class="settings-kicker">ALERTAS</span><h2>Notificaciones</h2><p>Configura dónde recibirás las confirmaciones de movimientos.</p></div></div>
                <div class="settings-card notification-settings-card notification-settings-card-v2">
                    <form method="post" class="notification-settings-form notification-settings-form-v2" data-autosave><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="user"><input type="hidden" name="return_tab" value="notificaciones"><div class="notification-email"><label>Correo de notificaciones</label><input type="email" name="notify_email" value="<?=e($u['notify_email'] ?: $u['email'])?>" required><small>Las notificaciones se enviarán a esta dirección.</small></div><label class="notification-option"><input type="checkbox" name="notify_on_income" <?=$u['notify_on_income']?'checked':''?>><span><strong>Ingresos registrados</strong><small>Enviar correo al registrar un ingreso.</small></span></label><label class="notification-option"><input type="checkbox" name="notify_on_expense" <?=$u['notify_on_expense']?'checked':''?>><span><strong>Egresos registrados</strong><small>Enviar correo al registrar un gasto o pago.</small></span></label><span class="autosave-status saved notification-autosave" data-autosave-status><i></i><span>Guardado</span></span></form>
                </div>
            </section>
        </div>
    </div>
</div>

<!-- Modales independientes de creación -->
<div class="modal settings-create-modal" id="settingsConceptModal" aria-hidden="true">
    <div class="modal-card settings-create-modal-card">
        <div class="modal-head settings-create-modal-head"><div><span>NUEVO CONCEPTO</span><h3>Crear concepto rápido</h3><p>Para movimientos variables como supermercado, delivery o ingresos ocasionales.</p></div><button class="modal-x" type="button" data-settings-modal-close>×</button></div>
        <form method="post" class="settings-form settings-modal-form">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="concept"><input type="hidden" name="return_tab" value="conceptos">
            <div><label>Categoría</label><select name="category_id" required><?php foreach ($cats as $c): ?><option value="<?=$c['id']?>"><?=e(($c['icon'] ?: '•').' '.$c['name'])?></option><?php endforeach; ?></select></div>
            <div><label>Nombre del concepto</label><input name="name" placeholder="Ej. Supermercado" required></div>
            <div><label>Monto sugerido <small>Opcional</small></label><div class="settings-money-input"><span>S/</span><input name="default_amount" type="number" min="0" step="0.01" placeholder="150.00"></div><small class="field-help">Déjalo vacío si el monto cambia siempre.</small></div>
            <label class="settings-check"><input type="checkbox" name="is_ant_expense"> <span>Identificar como gasto hormiga</span></label>
            <div class="settings-modal-actions"><button type="button" class="btn settings-cancel-btn" data-settings-modal-close>Cancelar</button><button class="btn primary">Crear concepto</button></div>
        </form>
    </div>
</div>

<div class="modal settings-create-modal" id="settingsPaymentModal" aria-hidden="true">
    <div class="modal-card settings-create-modal-card settings-create-modal-wide">
        <div class="modal-head settings-create-modal-head"><div><span>NUEVO PAGO FIJO</span><h3>Agregar compromiso mensual</h3><p>El sistema vinculará automáticamente el concepto y lo mostrará entre tus pagos pendientes.</p></div><button class="modal-x" type="button" data-settings-modal-close>×</button></div>
        <form method="post" class="settings-form settings-modal-form">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="recurring"><input type="hidden" name="return_tab" value="pagos">
            <div class="settings-modal-grid"><div><label>Categoría de egreso</label><select name="category_id" required><?php foreach ($expenseCats as $c): ?><option value="<?=$c['id']?>"><?=e(($c['icon'] ?: '•').' '.$c['name'])?></option><?php endforeach; ?></select></div><div><label>Nombre del pago</label><input name="name" placeholder="Ej. Internet WIN" required></div></div>
            <div class="settings-modal-grid three"><div><label>Monto mensual</label><div class="settings-money-input"><span>S/</span><input name="amount" type="number" min="0" step="0.01" placeholder="99.90" required></div></div><div><label>Próximo vencimiento</label><input name="due_date" type="date" value="<?=e(cfg_next_occurrence_date(5))?>" required><small class="field-help">Se repetirá el mismo día cada mes. Si un mes no tiene ese día, vence el último día.</small></div><div><label>Icono</label><input name="icon" value="📌"></div></div>
            <div><label>Fondo que lo cubrirá</label><select name="fund_id"><option value="">Sin fondo asignado</option><?php foreach ($financeFunds as $f): ?><option value="<?=$f['id']?>"><?=e(($f['icon'] ?: '•').' '.$f['name'])?></option><?php endforeach; ?></select><small class="field-help">Opcional. Por ejemplo: Alquiler → Casa.</small></div>
            <div class="settings-modal-actions"><button type="button" class="btn settings-cancel-btn" data-settings-modal-close>Cancelar</button><button class="btn primary">Agregar pago fijo</button></div>
        </form>
    </div>
</div>

<div class="modal settings-create-modal" id="settingsIncomeModal" aria-hidden="true">
    <div class="modal-card settings-create-modal-card settings-create-modal-wide">
        <div class="modal-head settings-create-modal-head"><div><span>NUEVO INGRESO FIJO</span><h3>Agregar ingreso mensual</h3><p>Ideal para sueldos, rentas o ingresos que esperas recibir todos los meses.</p></div><button class="modal-x" type="button" data-settings-modal-close>×</button></div>
        <form method="post" class="settings-form settings-modal-form">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="recurring_income"><input type="hidden" name="return_tab" value="ingresos-fijos">
            <?php if (!$incomeCats): ?>
                <div class="settings-inline-warning">Primero crea una categoría de tipo <strong>Ingreso</strong>.</div>
            <?php else: ?>
                <div class="settings-modal-grid"><div><label>Categoría de ingreso</label><select name="category_id" required><?php foreach ($incomeCats as $c): ?><option value="<?=$c['id']?>"><?=e(($c['icon'] ?: '•').' '.$c['name'])?></option><?php endforeach; ?></select></div><div><label>Nombre del ingreso</label><input name="name" placeholder="Ej. Sueldo Andrés" required></div></div>
                <div class="settings-modal-grid three"><div><label>Monto mensual</label><div class="settings-money-input"><span>S/</span><input name="amount" type="number" min="0" step="0.01" placeholder="5000.00" required></div></div><div><label>Próximo cobro</label><input name="income_date" type="date" value="<?=e(cfg_next_occurrence_date(1))?>" required><small class="field-help">El sistema usará ese día como referencia mensual.</small></div><div><label>Icono</label><input name="icon" value="💰"></div></div>
                <div><label>Cuenta donde se recibe</label><select name="account_id"><?php foreach ($financeAccounts as $a): ?><option value="<?=$a['id']?>"><?=e(($a['icon'] ?: '•').' '.$a['name'])?></option><?php endforeach; ?></select></div>
                <div class="settings-modal-actions"><button type="button" class="btn settings-cancel-btn" data-settings-modal-close>Cancelar</button><button class="btn primary">Agregar ingreso fijo</button></div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="modal settings-create-modal" id="settingsCategoryModal" aria-hidden="true">
    <div class="modal-card settings-create-modal-card">
        <div class="modal-head settings-create-modal-head"><div><span>NUEVA CATEGORÍA</span><h3>Crear categoría</h3><p>Úsala para organizar cómo se agrupa el dinero en reportes y movimientos.</p></div><button class="modal-x" type="button" data-settings-modal-close>×</button></div>
        <form method="post" class="settings-form settings-modal-form">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="category"><input type="hidden" name="return_tab" value="categorias">
            <div><label>Nombre</label><input name="name" required placeholder="Ej. Hogar"></div>
            <div class="settings-modal-grid"><div><label>Tipo</label><select name="type"><option value="expense">Egreso</option><option value="income">Ingreso</option></select></div><div><label>Icono</label><input name="icon" value="💳"></div></div>
            <div class="settings-color-field"><div><label>Color</label><small>Solo se usa como referencia visual.</small></div><input name="color" type="color" value="#2563eb" class="color-picker"></div>
            <label class="settings-check"><input type="checkbox" name="is_ant_expense"> <span>Gasto hormiga por defecto</span></label>
            <div class="settings-modal-actions"><button type="button" class="btn settings-cancel-btn" data-settings-modal-close>Cancelar</button><button class="btn primary">Crear categoría</button></div>
        </form>
    </div>
</div>

<div class="modal settings-create-modal" id="settingsGoalModal" aria-hidden="true">
    <div class="modal-card settings-create-modal-card">
        <div class="modal-head settings-create-modal-head"><div><span>NUEVA META</span><h3>Crear meta financiera</h3><p>Define un objetivo claro para un mes y úsalo como referencia de progreso.</p></div><button class="modal-x" type="button" data-settings-modal-close>×</button></div>
        <form method="post" class="settings-form settings-modal-form">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="goal"><input type="hidden" name="return_tab" value="metas">
            <div class="settings-modal-grid"><div><label>Mes</label><input type="month" name="period" value="<?=date('Y-m')?>" required></div><div><label>Tipo</label><select name="type"><option value="savings">Ahorro</option><option value="income">Ingreso</option><option value="expense_limit">Tope de gasto</option></select></div></div>
            <div><label>Nombre</label><input name="name" placeholder="Ej. Fondo de emergencia" required></div>
            <div><label>Monto objetivo</label><div class="settings-money-input"><span>S/</span><input name="target_amount" type="number" min="0" step="0.01" required placeholder="0.00"></div></div>
            <div data-goal-savings-account class="settings-info-inline"><b>La cuenta se elige al ahorrar</b><small>Cuando hagas cada aporte decidirás de qué cuenta sale y si se queda allí o se mueve a otra cuenta.</small></div>
            <div class="settings-modal-actions"><button type="button" class="btn settings-cancel-btn" data-settings-modal-close>Cancelar</button><button class="btn primary">Crear meta</button></div>
        </form>
    </div>
</div>

<script src="<?=e(app_url('assets/js/settings.js'))?>?v=<?=e((string)@filemtime(__DIR__.'/../assets/js/settings.js'))?>"></script>
<?php page_bottom(); ?>
