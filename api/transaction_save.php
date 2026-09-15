<?php
require __DIR__.'/../app/bootstrap.php';
$uid = require_auth();
verify_csrf();
FinanceSchema::ensure($uid);
try { SavingsSchema::ensure($uid); } catch (Throwable $e) { error_log('[MiDinero savings availability] '.$e->getMessage()); }
$d = request_json();

$type = in_array($d['type'] ?? '', ['income','expense'], true) ? $d['type'] : 'expense';
$amount = round((float)($d['amount'] ?? 0), 2);
$catId = (int)($d['category_id'] ?? 0);
$conceptId = !empty($d['concept_id']) ? (int)$d['concept_id'] : null;
$accountId = (int)($d['account_id'] ?? 0);
$fundId = !empty($d['fund_id']) ? (int)$d['fund_id'] : null;
$occurred = parse_local_datetime((string)($d['occurred_at'] ?? date('Y-m-d H:i:s')));

if ($amount <= 0 || !$catId || !$conceptId) json_response(['ok'=>false,'message'=>'Monto, categoría y concepto son obligatorios. Selecciona o crea un concepto antes de registrar.'],422);
if (!$occurred) json_response(['ok'=>false,'message'=>'La fecha y hora no son válidas.'],422);

$pdo = db();
$pdo->beginTransaction();
try {
    finance_lock_user($uid);

    if (!$accountId) {
        $s = $pdo->prepare('SELECT id FROM financial_accounts WHERE user_id=? AND active=1 ORDER BY id LIMIT 1');
        $s->execute([$uid]);
        $accountId = (int)$s->fetchColumn();
    }

    $ac = $pdo->prepare('SELECT id FROM financial_accounts WHERE id=? AND user_id=? AND active=1 FOR UPDATE');
    $ac->execute([$accountId,$uid]);
    if (!$ac->fetch()) throw new DomainException('Cuenta inválida.');

    $st = $pdo->prepare('SELECT name,type,is_ant_expense FROM categories WHERE id=? AND user_id=? AND active=1');
    $st->execute([$catId,$uid]);
    $cat = $st->fetch();
    if (!$cat) throw new DomainException('Categoría inválida.');
    if ($cat['type'] !== $type) {
        throw new DomainException($type === 'income' ? 'Selecciona una categoría de ingreso.' : 'Selecciona una categoría de egreso.');
    }

    $con = null;
    if ($conceptId) {
        $st = $pdo->prepare('SELECT name,is_ant_expense,category_id FROM concepts WHERE id=? AND user_id=? AND active=1');
        $st->execute([$conceptId,$uid]);
        $con = $st->fetch();
        if (!$con) throw new DomainException('Concepto inválido.');
        if ((int)$con['category_id'] !== $catId) throw new DomainException('El concepto no pertenece a la categoría seleccionada.');
    }

    if ($type === 'income') {
        $fundId = null;
    } else {
        $accountBalance = FinanceService::accountBalance($uid,$accountId);
        $protectedSavings = FinanceService::accountSavingsReserved($uid,$accountId);
        $spendable = max(0,$accountBalance-$protectedSavings);
        if ($amount > $spendable + 0.005) {
            $msg='Puedes gastar S/ '.number_format($spendable,2).' desde esta cuenta.';
            if($protectedSavings>0.005)$msg.=' Hay S/ '.number_format($protectedSavings,2).' protegidos en Ahorro; retíralos primero si realmente quieres usarlos.';
            throw new DomainException($msg);
        }

        if ($fundId) {
            $fs = $pdo->prepare("SELECT f.id FROM funds f WHERE f.id=? AND f.user_id=? AND f.active=1 FOR UPDATE");
            $fs->execute([$fundId,$uid]);$fundRow=$fs->fetch();
            if (!$fundRow) throw new DomainException('Fondo inválido.');
            if(SavingsSchema::isSavingsFund($uid,$fundId)) throw new DomainException('Ese dinero pertenece a una meta de ahorro. Retíralo primero desde el módulo Ahorro para conservar la trazabilidad.');
            $available = FinanceService::fundAvailable($uid,$fundId);
            if ($amount > $available + 0.005) {
                throw new DomainException('Ese fondo solo tiene S/ '.number_format(max(0,$available),2).' disponibles. Usa dinero libre o separa más dinero antes de pagar.');
            }
        }
    }

    $hour = (int)date('G',strtotime($occurred));
    $nightFood = (stripos($cat['name'],'comida') !== false && ($hour >= 19 || $hour <= 2));
    $isAnt = $type === 'expense' && (!empty($cat['is_ant_expense']) || !empty($con['is_ant_expense']) || $nightFood);

    $st = $pdo->prepare('INSERT INTO transactions(user_id,created_by_user_id,type,category_id,concept_id,account_id,fund_id,amount,occurred_at,description,payment_method,is_ant_expense) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([$uid,actual_user_id(),$type,$catId,$conceptId,$accountId,$fundId,$amount,$occurred,trim((string)($d['description']??'')),trim((string)($d['payment_method']??'')),$isAnt ? 1 : 0]);
    $id = (int)$pdo->lastInsertId();
    FinanceAudit::record(
        $uid,'transaction_created','transaction',$id,
        $type==='income'?'Ingreso registrado':'Gasto registrado',
        ($con['name']??$cat['name']).' · S/ '.number_format($amount,2),
        null,
        ['type'=>$type,'amount'=>$amount,'category'=>$cat['name'],'concept'=>$con['name']??null,'account_id'=>$accountId,'fund_id'=>$fundId,'occurred_at'=>$occurred],
        ['period'=>substr($occurred,0,7)],
        true
    );
    $pdo->commit();

    emit_event($uid,'transaction_created',['id'=>$id]);
    $user = current_user();
    Mailer::transaction($user,['type'=>$type,'amount'=>$amount,'occurred_at'=>$occurred,'description'=>trim((string)($d['description']??'')),'concept_name'=>$con['name']??'','category_name'=>$cat['name']]);
    json_response(['ok'=>true,'id'=>$id,'ant_expense'=>$isAnt]);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok'=>false,'message'=>$e->getMessage()],422);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_response(['ok'=>false,'message'=>'No se pudo registrar el movimiento.'],500);
}
