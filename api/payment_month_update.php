<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();
verify_csrf();
FinanceSchema::ensure($uid);
$d=request_json();

$id=(int)($d['id']??0);
$action=trim((string)($d['action']??'save'));
if($id<=0) json_response(['ok'=>false,'message'=>'Pago mensual no válido.'],422);
if(!in_array($action,['save','skip','restore','reset'],true)) json_response(['ok'=>false,'message'=>'Acción no válida.'],422);

$pdo=db();
$pdo->beginTransaction();
try{
    finance_lock_user($uid);
    $st=$pdo->prepare("SELECT mp.*,r.amount recurring_amount,r.due_day,r.name
        FROM monthly_payments mp JOIN recurring_payments r ON r.id=mp.recurring_id
        WHERE mp.id=? AND mp.user_id=? FOR UPDATE");
    $st->execute([$id,$uid]);
    $p=$st->fetch();
    if(!$p) throw new DomainException('No se encontró el pago mensual.');
    if((string)$p['status']==='paid') throw new DomainException('Este pago ya fue completado y no puede modificarse.');

    $period=(string)$p['period'];
    [$year,$month]=array_map('intval',explode('-',$period));
    $days=cal_days_in_month(CAL_GREGORIAN,$month,$year);
    $baseDue=sprintf('%04d-%02d-%02d',$year,$month,max(1,min((int)$p['due_day'],$days)));
    $actor=actual_user_id();

    if($action==='skip'){
        if((float)($p['paid_amount']??0)>0.005) throw new DomainException('No puedes omitir un pago que ya tiene un abono parcial. Completa el saldo o mantén el compromiso pendiente.');
        $up=$pdo->prepare("UPDATE monthly_payments SET status='skipped',skipped_at=NOW(),paid_at=NULL,updated_by_user_id=? WHERE id=? AND user_id=?");
        $up->execute([$actor,$id,$uid]);
    } elseif($action==='restore'){
        $paid=(float)($p['paid_amount']??0);
        $status=$paid>0.005?'partial':'pending';
        $up=$pdo->prepare("UPDATE monthly_payments SET status=?,skipped_at=NULL,updated_by_user_id=? WHERE id=? AND user_id=?");
        $up->execute([$status,$actor,$id,$uid]);
    } elseif($action==='reset'){
        $baseAmount=round((float)$p['recurring_amount'],2);
        $paid=round((float)($p['paid_amount']??0),2);
        $status=$paid>0.005?($paid+0.005>=$baseAmount?'paid':'partial'):'pending';
        $paidAt=$status==='paid'?($p['paid_at']?:date('Y-m-d H:i:s')):null;
        $up=$pdo->prepare("UPDATE monthly_payments
            SET amount=?,due_date=?,amount_overridden=0,due_date_overridden=0,status=?,paid_at=?,skipped_at=NULL,updated_by_user_id=?
            WHERE id=? AND user_id=?");
        $up->execute([$baseAmount,$baseDue,$status,$paidAt,$actor,$id,$uid]);
    } else {
        $amount=round((float)($d['amount']??0),2);
        $dueDate=trim((string)($d['due_date']??''));
        if($amount<=0) throw new DomainException('El monto del mes debe ser mayor a S/ 0.00.');
        $paid=round((float)($p['paid_amount']??0),2);
        if($amount+0.005<$paid) throw new DomainException('El monto del mes no puede ser menor a lo que ya pagaste (S/ '.number_format($paid,2).').');
        $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$dueDate);
        if(!$dt || $dt->format('Y-m-d')!==$dueDate || $dt->format('Y-m')!==$period){
            throw new DomainException('El vencimiento debe pertenecer al mes '.$period.'.');
        }
        $status=(string)$p['status'];
        if($status!=='skipped') $status=$paid>0.005?($paid+0.005>=$amount?'paid':'partial'):'pending';
        $paidAt=$status==='paid'?($p['paid_at']?:date('Y-m-d H:i:s')):null;
        $up=$pdo->prepare("UPDATE monthly_payments
            SET amount=?,due_date=?,amount_overridden=1,due_date_overridden=1,status=?,paid_at=?,updated_by_user_id=?
            WHERE id=? AND user_id=?");
        $up->execute([$amount,$dueDate,$status,$paidAt,$actor,$id,$uid]);
    }

    $fresh=$pdo->prepare("SELECT mp.id,mp.period,mp.due_date,mp.amount,mp.paid_amount,
            GREATEST(mp.amount-mp.paid_amount,0) remaining_amount,mp.status,
            mp.amount_overridden,mp.due_date_overridden,mp.skipped_at,
            r.amount recurring_amount,r.due_day recurring_due_day,r.name,r.icon,r.fund_id,
            DATEDIFF(mp.due_date,CURDATE()) days_left
        FROM monthly_payments mp JOIN recurring_payments r ON r.id=mp.recurring_id
        WHERE mp.id=? AND mp.user_id=? LIMIT 1");
    $fresh->execute([$id,$uid]);
    $row=$fresh->fetch();
    $pdo->commit();

    emit_event($uid,'payment_month_updated',[
        'id'=>$id,'period'=>$period,'action'=>$action,'status'=>$row['status']??null,
        'amount'=>(float)($row['amount']??0),'remaining_amount'=>(float)($row['remaining_amount']??0)
    ]);
    json_response(['ok'=>true,'payment'=>$row]);
}catch(DomainException $e){
    if($pdo->inTransaction())$pdo->rollBack();
    json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[MiDinero payment_month_update] '.$e->getMessage());
    json_response(['ok'=>false,'message'=>'No se pudo actualizar el pago de este mes.'],500);
}
