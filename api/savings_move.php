<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();
verify_csrf();
FinanceSchema::ensure($uid);
$d=request_json();

$goalId=max(0,(int)($d['goal_id']??0));
$amount=round((float)($d['amount']??0),2);
$from=(int)($d['from_account_id']??0);
$to=(int)($d['to_account_id']??$from);
$occurred=parse_local_datetime((string)($d['occurred_at']??date('Y-m-d H:i:s')));
$note=trim((string)($d['note']??''));
if($amount<=0||!$from||!$to)json_response(['ok'=>false,'message'=>'Ingresa un monto y selecciona la cuenta.'],422);
if(!$occurred)json_response(['ok'=>false,'message'=>'La fecha no es válida.'],422);

// Regenerar compromisos antes de abrir la transacción. El ahorro nunca debe
// fallar porque dashboard() intente hacer trabajo adicional mientras hay locks.
try{ FinanceService::ensureMonthlyPayments($uid,date('Y-m')); }catch(Throwable $e){ error_log('[MiDinero savings ensure payments] '.$e->getMessage()); }

$pdo=db();
$pdo->beginTransaction();
try{
    finance_lock_user($uid);
    SavingsSchema::ensure($uid);
    $fund=SavingsSchema::savingsFund($uid,true,true);
    if(!$fund) throw new RuntimeException('No se pudo obtener el Fondo Ahorro.');
    $fundId=(int)$fund['id'];

    $goalName='Ahorro general';
    if($goalId>0){
        $gs=$pdo->prepare("SELECT id,name FROM goals WHERE user_id=? AND id=? AND type='savings' LIMIT 1 FOR UPDATE");
        $gs->execute([$uid,$goalId]);
        $goal=$gs->fetch();
        if(!$goal) throw new DomainException('La meta seleccionada no es válida.');
        $goalName=(string)$goal['name'];
    }

    $ids=array_values(array_unique([$from,$to]));sort($ids,SORT_NUMERIC);
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $st=$pdo->prepare("SELECT id FROM financial_accounts WHERE user_id=? AND active=1 AND id IN ($ph) ORDER BY id FOR UPDATE");
    $st->execute(array_merge([$uid],$ids));
    if(count($st->fetchAll())!==count($ids)) throw new DomainException('Una de las cuentas no es válida.');

    // Lo protegido en Ahorro no puede volver a usarse para crear más ahorro.
    $sourceTotal=FinanceService::accountBalance($uid,$from);
    $protected=FinanceService::accountSavingsReserved($uid,$from);
    $sourceAvailable=max(0,$sourceTotal-$protected);
    if($amount>$sourceAvailable+0.005){
        throw new DomainException('En esta cuenta puedes usar S/ '.number_format($sourceAvailable,2).'. Tienes S/ '.number_format($protected,2).' protegidos en Ahorro.');
    }

    // Además respetamos pagos y otros fondos ya comprometidos.
    $free=FinanceService::freeToSpendNow($uid);
    if($amount>$free+0.005){
        throw new DomainException('Para no tocar dinero ya comprometido, hoy puedes enviar como máximo S/ '.number_format(max(0,$free),2).' a Ahorro.');
    }

    // Si el usuario eligió otra cuenta de custodia, se hace una transferencia
    // real. Si es la misma cuenta, no se mueve el saldo bancario: solo se protege.
    if($from!==$to){
        $tr=$pdo->prepare('INSERT INTO account_transfers(user_id,created_by_user_id,from_account_id,to_account_id,amount,occurred_at,description) VALUES(?,?,?,?,?,?,?)');
        $tr->execute([$uid,actual_user_id(),$from,$to,$amount,$occurred,'Ahorro protegido'.($goalId>0?' · '.$goalName:'')]);
    }

    $marker=SavingsSchema::marker('deposit',$goalId,$from,$to,$note);
    $fa=$pdo->prepare('INSERT INTO fund_allocations(user_id,created_by_user_id,fund_id,amount,occurred_at,note) VALUES(?,?,?,?,?,?)');
    $fa->execute([$uid,actual_user_id(),$fundId,$amount,$occurred,$marker]);

    $pdo->commit();
    try{emit_event($uid,'savings_deposit',['goal_id'=>$goalId,'amount'=>$amount,'account_id'=>$to]);}catch(Throwable $e){}
    json_response([
        'ok'=>true,
        'message'=>'Dinero protegido en Ahorro',
        'amount'=>$amount,
        'goal_id'=>$goalId,
        'fund_id'=>$fundId,
        'protected_total'=>FinanceService::fundAvailable($uid,$fundId)
    ]);
}catch(DomainException $e){
    if($pdo->inTransaction())$pdo->rollBack();
    json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[MiDinero savings move v9] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    json_response(['ok'=>false,'message'=>'No se pudo guardar el dinero en Ahorro. Intenta nuevamente.'],500);
}
