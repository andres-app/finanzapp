<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();
verify_csrf();
FinanceSchema::ensure($uid);
try { SavingsSchema::ensure($uid); } catch (Throwable $e) { error_log('[MiDinero savings availability] '.$e->getMessage()); }
$d=request_json();
$allocs=$d['allocations']??[];
$note=trim((string)($d['note']??'Distribución de dinero'));
$sourceTx=!empty($d['source_transaction_id'])?(int)$d['source_transaction_id']:null;
if(!is_array($allocs)||!$allocs)json_response(['ok'=>false,'message'=>'No hay montos para distribuir.'],422);
$clean=[];$total=0.0;
foreach($allocs as $fid=>$amount){$fid=(int)$fid;$amount=round((float)$amount,2);if($fid>0&&$amount>0){$clean[$fid]=$amount;$total+=$amount;}}
$total=round($total,2);
if($total<=0)json_response(['ok'=>false,'message'=>'Ingresa al menos un monto.'],422);

$pdo=db();
$pdo->beginTransaction();
try{
    finance_lock_user($uid);
    $ids=array_keys($clean); sort($ids,SORT_NUMERIC);
    $place=implode(',',array_fill(0,count($ids),'?'));
    $params=array_merge([$uid],$ids);
    $st=$pdo->prepare("SELECT f.id,f.name FROM funds f WHERE f.user_id=? AND f.active=1 AND f.id IN ($place) ORDER BY f.id FOR UPDATE");
    $st->execute($params);$validFunds=$st->fetchAll();
    if(count($validFunds)!==count($ids))throw new DomainException('Uno de los fondos no es válido.');
    foreach($validFunds as $vf)if(SavingsSchema::isSavingsFund($uid,(int)$vf['id']))throw new DomainException('Las metas de ahorro se alimentan desde el módulo Ahorro para conservar su trazabilidad.');

    $cash=FinanceService::totalCash($uid);
    $reserved=FinanceService::totalReserved($uid);
    $unallocated=$cash-$reserved;
    if($total>$unallocated+0.005)throw new DomainException('Estás intentando separar más dinero del que tienes libre. Disponible: S/ '.number_format(max(0,$unallocated),2));

    if($sourceTx){
        $src=$pdo->prepare("SELECT t.amount-COALESCE((SELECT SUM(fa.amount) FROM fund_allocations fa WHERE fa.user_id=t.user_id AND fa.source_transaction_id=t.id AND fa.voided_at IS NULL),0) remaining
            FROM transactions t WHERE t.id=? AND t.user_id=? AND t.voided_at IS NULL AND t.type='income' FOR UPDATE");
        $src->execute([$sourceTx,$uid]);
        $remaining=$src->fetchColumn();
        if($remaining===false)throw new DomainException('El ingreso seleccionado no es válido.');
        if($total>(float)$remaining+0.005)throw new DomainException('Ese ingreso solo tiene S/ '.number_format(max(0,(float)$remaining),2).' disponibles para distribuir.');
    }

    $occurred=date('Y-m-d H:i:s');
    $ins=$pdo->prepare('INSERT INTO fund_allocations(user_id,created_by_user_id,fund_id,amount,occurred_at,source_transaction_id,note) VALUES(?,?,?,?,?,?,?)');
    $allocationIds=[];$fundNames=[];foreach($validFunds as $vf)$fundNames[(int)$vf['id']]=$vf['name'];
    foreach($clean as $fid=>$amount){
        $ins->execute([$uid,actual_user_id(),$fid,$amount,$occurred,$sourceTx,$note]);
        $allocationIds[]=(int)$pdo->lastInsertId();
    }
    FinanceAudit::record(
        $uid,'fund_allocation_created','fund_allocation_group',null,'Dinero separado en fondos',
        'Total S/ '.number_format($total,2),
        null,['allocations'=>$clean,'fund_names'=>$fundNames,'source_transaction_id'=>$sourceTx,'occurred_at'=>$occurred],
        ['allocation_ids'=>$allocationIds,'period'=>substr($occurred,0,7)],true
    );
    $pdo->commit();
    emit_event($uid,'fund_allocated',['total'=>$total]);
    json_response(['ok'=>true,'total'=>$total]);
}catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'message'=>'No se pudo separar el dinero.'],500);}
