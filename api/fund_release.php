<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();
verify_csrf();
FinanceSchema::ensure($uid);
try { SavingsSchema::ensure($uid); } catch (Throwable $e) { error_log('[MiDinero savings availability] '.$e->getMessage()); }
$d=request_json();
$fundId=(int)($d['fund_id']??0);
$amount=round((float)($d['amount']??0),2);
if(!$fundId||$amount<=0)json_response(['ok'=>false,'message'=>'Monto inválido.'],422);

$pdo=db();
$pdo->beginTransaction();
try{
    finance_lock_user($uid);
    $st=$pdo->prepare("SELECT f.id FROM funds f WHERE f.id=? AND f.user_id=? AND f.active=1 FOR UPDATE");
    $st->execute([$fundId,$uid]);$fundRow=$st->fetch();
    if(!$fundRow)throw new DomainException('Fondo inválido.');
    if(SavingsSchema::isSavingsFund($uid,$fundId))throw new DomainException('Retira este dinero desde el módulo Ahorro para conservar la trazabilidad.');
    $avail=FinanceService::fundAvailable($uid,$fundId);
    if($amount>$avail+0.005)throw new DomainException('El fondo solo tiene S/ '.number_format(max(0,$avail),2).' disponibles.');
    $st=$pdo->prepare('INSERT INTO fund_allocations(user_id,created_by_user_id,fund_id,amount,occurred_at,note) VALUES(?,?,?, ?,?,?)');
    $st->execute([$uid,actual_user_id(),$fundId,-$amount,date('Y-m-d H:i:s'),'Liberación de fondo']);
    $pdo->commit();
    emit_event($uid,'fund_released',['fund_id'=>$fundId,'amount'=>$amount]);
    json_response(['ok'=>true]);
}catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'message'=>'No se pudo liberar el dinero.'],500);}
