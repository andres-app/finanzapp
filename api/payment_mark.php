<?php
require __DIR__.'/../app/bootstrap.php';
$uid = require_auth();
verify_csrf();
FinanceSchema::ensure($uid);
try { SavingsSchema::ensure($uid); } catch (Throwable $e) { error_log('[MiDinero savings availability] '.$e->getMessage()); }
$d = request_json();

$id = (int)($d['id'] ?? 0);
$amount = round((float)($d['amount'] ?? 0), 2);
$paymentMethod = trim((string)($d['payment_method'] ?? 'Transferencia'));
$accountId = (int)($d['account_id'] ?? 0);
$fundFieldPresent = array_key_exists('fund_id',$d);
$fundId = !empty($d['fund_id']) ? (int)$d['fund_id'] : null;

if ($id <= 0) json_response(['ok'=>false,'message'=>'Pago no válido.'],422);
if ($amount <= 0) json_response(['ok'=>false,'message'=>'Ingresa un monto pagado mayor a S/ 0.00.'],422);
$allowedMethods = ['Efectivo','Yape','Plin','Tarjeta','Transferencia'];
if (!in_array($paymentMethod,$allowedMethods,true)) $paymentMethod='Transferencia';

$pdo = db();
$pdo->beginTransaction();
try {
    finance_lock_user($uid);

    $st = $pdo->prepare("SELECT mp.*,r.category_id,r.concept_id,r.name,r.icon,r.fund_id recurring_fund_id
        FROM monthly_payments mp JOIN recurring_payments r ON r.id=mp.recurring_id
        WHERE mp.id=? AND mp.user_id=? AND mp.status IN ('pending','partial') FOR UPDATE");
    $st->execute([$id,$uid]);
    $p = $st->fetch();
    if (!$p) throw new DomainException('Pago no encontrado, omitido o ya completado.');

    $referenceAmount=round((float)$p['amount'],2);
    $paidBefore=round((float)($p['paid_amount'] ?? 0),2);
    $remainingBefore=max(0,round($referenceAmount-$paidBefore,2));
    if ($remainingBefore <= 0.005) throw new DomainException('Este compromiso ya está cubierto.');
    if ($amount > $remainingBefore + 0.005) {
        throw new DomainException('El saldo pendiente es S/ '.number_format($remainingBefore,2).'. Registra como máximo ese importe.');
    }
    // Evita residuos de un céntimo por redondeo.
    if (abs($amount-$remainingBefore) <= 0.005) $amount=$remainingBefore;

    if (!$accountId) {
        $s=$pdo->prepare('SELECT id FROM financial_accounts WHERE user_id=? AND active=1 ORDER BY id LIMIT 1');
        $s->execute([$uid]);
        $accountId=(int)$s->fetchColumn();
    }
    $ac=$pdo->prepare('SELECT id FROM financial_accounts WHERE id=? AND user_id=? AND active=1 FOR UPDATE');
    $ac->execute([$accountId,$uid]);
    if(!$ac->fetch()) throw new DomainException('Cuenta inválida.');

    // Si el cliente envía fund_id vacío significa explícitamente "usar dinero libre".
    // Solo heredamos el fondo configurado para clientes antiguos que no envían el campo.
    if (!$fundFieldPresent && !$fundId && !empty($p['recurring_fund_id'])) $fundId=(int)$p['recurring_fund_id'];

    $accountBalance = FinanceService::accountBalance($uid,$accountId);
    $protectedSavings = FinanceService::accountSavingsReserved($uid,$accountId);
    $spendable=max(0,$accountBalance-$protectedSavings);
    if ($amount > $spendable + 0.005) {
        $msg='En esta cuenta tienes S/ '.number_format($spendable,2).' disponibles para pagar.';
        if($protectedSavings>0.005)$msg.=' S/ '.number_format($protectedSavings,2).' están protegidos en Ahorro.';
        throw new DomainException($msg);
    }

    if ($fundId) {
        $fs=$pdo->prepare("SELECT f.id FROM funds f WHERE f.id=? AND f.user_id=? AND f.active=1 FOR UPDATE");
        $fs->execute([$fundId,$uid]);$fundRow=$fs->fetch();
        if(!$fundRow) throw new DomainException('Fondo inválido.');
        if(SavingsSchema::isSavingsFund($uid,$fundId)) throw new DomainException('No uses una meta de ahorro para pagar directamente. Retira primero el monto desde Ahorro para mantener la trazabilidad.');
        $available=FinanceService::fundAvailable($uid,$fundId);
        if($amount>$available+0.005){
            throw new DomainException('Ese fondo solo tiene S/ '.number_format(max(0,$available),2).' disponibles. Elige “dinero libre” o separa más dinero al fondo.');
        }
    }

    $newPaid=round($paidBefore+$amount,2);
    $remainingAfter=max(0,round($referenceAmount-$newPaid,2));
    $isComplete=$remainingAfter<=0.005;
    $newStatus=$isComplete?'paid':'partial';
    $occurredAt=date('Y-m-d H:i:s');
    $description=($newStatus==='partial'?'Pago mensual parcial: ':'Pago mensual: ').$p['name'];

    $tx=$pdo->prepare("INSERT INTO transactions(user_id,created_by_user_id,type,category_id,concept_id,account_id,fund_id,amount,occurred_at,description,payment_method,is_ant_expense) VALUES(?,?,?,?,?,?,?,?,?,?,?,0)");
    $tx->execute([$uid,actual_user_id(),'expense',$p['category_id'],$p['concept_id'],$accountId,$fundId,$amount,$occurredAt,$description,$paymentMethod]);
    $txId=(int)$pdo->lastInsertId();

    $part=$pdo->prepare("INSERT INTO monthly_payment_parts(user_id,monthly_payment_id,transaction_id,amount,paid_at,created_by_user_id,created_at)
        VALUES(?,?,?,?,?,?,NOW())");
    $part->execute([$uid,$id,$txId,$amount,$occurredAt,actual_user_id()]);

    // Mantener un único estado financiero por pago fijo + período. En instalaciones
    // antiguas que pudieran tener duplicados, todos los abiertos quedan sincronizados.
    $up=$pdo->prepare("UPDATE monthly_payments
        SET paid_amount=?,status=?,paid_at=?,transaction_id=?,updated_by_user_id=?
        WHERE user_id=? AND recurring_id=? AND period=? AND status IN ('pending','partial')");
    $up->execute([
        $newPaid,$newStatus,$isComplete?$occurredAt:null,$txId,actual_user_id(),
        $uid,(int)$p['recurring_id'],(string)$p['period']
    ]);
    if ($up->rowCount() < 1) throw new RuntimeException('El pago cambió mientras se procesaba.');

    $pdo->commit();
    emit_event($uid,$isComplete?'payment_paid':'payment_partial',[
        'id'=>$id,'transaction_id'=>$txId,'amount'=>$amount,'reference_amount'=>$referenceAmount,
        'paid_amount'=>$newPaid,'remaining_amount'=>$remainingAfter,'status'=>$newStatus,'period'=>$p['period']
    ]);
    $user=current_user();
    Mailer::transaction($user,['type'=>'expense','amount'=>$amount,'occurred_at'=>$occurredAt,'description'=>$description,'concept_name'=>$p['name'],'category_name'=>'Obligación']);
    json_response([
        'ok'=>true,'amount'=>$amount,'reference_amount'=>$referenceAmount,'paid_amount'=>$newPaid,
        'remaining_amount'=>$remainingAfter,'status'=>$newStatus,'transaction_id'=>$txId
    ]);
} catch (DomainException $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    json_response(['ok'=>false,'message'=>$e->getMessage()],422);
} catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[MiDinero payment_mark] '.$e->getMessage());
    json_response(['ok'=>false,'message'=>'No se pudo registrar el pago.'],500);
}
