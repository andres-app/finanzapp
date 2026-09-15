<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();verify_csrf();FinanceSchema::ensure($uid);SavingsSchema::ensure($uid);
$d=request_json();$auditId=(int)($d['audit_id']??0);$reason=trim((string)($d['reason']??''));
if($auditId<=0)json_response(['ok'=>false,'message'=>'Operación no válida.'],422);
if($reason==='')$reason='Corrección de registro';
$pdo=db();$pdo->beginTransaction();
try{
    finance_lock_user($uid);
    $audit=FinanceAudit::getForUpdate($uid,$auditId);
    if(!$audit)throw new DomainException('No se encontró la operación.');
    if(empty($audit['reversible']))throw new DomainException('Esta acción no se puede anular automáticamente.');
    if(!empty($audit['reversed_at']))throw new DomainException('Esta operación ya fue anulada.');

    $type=(string)$audit['entity_type'];$entityId=(int)($audit['entity_id']??0);$meta=$audit['metadata']??[];
    $affected=[];

    if($type==='transaction'){
        $st=$pdo->prepare('SELECT * FROM transactions WHERE id=? AND user_id=? FOR UPDATE');$st->execute([$entityId,$uid]);$tx=$st->fetch();
        if(!$tx || !empty($tx['voided_at']))throw new DomainException('El movimiento ya no está activo.');
        $dep=$pdo->prepare('SELECT COUNT(*) FROM fund_allocations WHERE user_id=? AND source_transaction_id=? AND voided_at IS NULL');$dep->execute([$uid,$entityId]);
        if((int)$dep->fetchColumn()>0)throw new DomainException('Este ingreso ya fue distribuido en fondos. Libera o anula primero esas distribuciones.');
        $pdo->prepare('UPDATE transactions SET voided_at=NOW(),voided_by_user_id=?,void_reason=? WHERE id=? AND user_id=? AND voided_at IS NULL')
            ->execute([actual_user_id(),substr($reason,0,255),$entityId,$uid]);
        $affected[]=['type'=>'transaction','id'=>$entityId];

        // Si el movimiento era parte de un pago fijo, recalculamos el estado mensual
        // usando únicamente abonos que sigan activos.
        $parts=$pdo->prepare('SELECT DISTINCT monthly_payment_id FROM monthly_payment_parts WHERE user_id=? AND transaction_id=?');
        $parts->execute([$uid,$entityId]);
        foreach($parts->fetchAll() as $pr){
            $mpId=(int)$pr['monthly_payment_id'];
            $mp=$pdo->prepare('SELECT id,amount,status FROM monthly_payments WHERE id=? AND user_id=? FOR UPDATE');$mp->execute([$mpId,$uid]);$mpRow=$mp->fetch();if(!$mpRow)continue;
            $sum=$pdo->prepare("SELECT COALESCE(SUM(part.amount),0) paid_amount,MAX(part.paid_at) last_paid,
                SUBSTRING_INDEX(GROUP_CONCAT(part.transaction_id ORDER BY part.paid_at DESC,part.id DESC),',',1) latest_tx
                FROM monthly_payment_parts part JOIN transactions t ON t.id=part.transaction_id AND t.user_id=part.user_id AND t.voided_at IS NULL
                WHERE part.user_id=? AND part.monthly_payment_id=?");
            $sum->execute([$uid,$mpId]);$r=$sum->fetch()?:[];$paid=round((float)($r['paid_amount']??0),2);$amount=round((float)$mpRow['amount'],2);
            $status=$paid<=0.005?'pending':($paid+0.005>=$amount?'paid':'partial');
            $pdo->prepare('UPDATE monthly_payments SET paid_amount=?,status=?,paid_at=?,transaction_id=?,updated_by_user_id=? WHERE id=? AND user_id=?')
                ->execute([$paid,$status,$status==='paid'?($r['last_paid']??date('Y-m-d H:i:s')):null,!empty($r['latest_tx'])?(int)$r['latest_tx']:null,actual_user_id(),$mpId,$uid]);
            $affected[]=['type'=>'monthly_payment','id'=>$mpId];
        }
    }elseif($type==='account_transfer'){
        $st=$pdo->prepare('SELECT * FROM account_transfers WHERE id=? AND user_id=? FOR UPDATE');$st->execute([$entityId,$uid]);$tr=$st->fetch();
        if(!$tr || !empty($tr['voided_at']))throw new DomainException('La transferencia ya no está activa.');
        $desc=(string)($tr['description']??'');
        if(str_starts_with($desc,'Ahorro protegido')||str_starts_with($desc,'Retiro de ahorro'))throw new DomainException('Los movimientos internos de Ahorro se corrigen desde el módulo Ahorro.');
        $available=FinanceService::accountSpendableBalance($uid,(int)$tr['to_account_id']);
        if($available+0.005<(float)$tr['amount'])throw new DomainException('No se puede anular porque parte del dinero transferido ya fue usado en la cuenta destino.');
        $pdo->prepare('UPDATE account_transfers SET voided_at=NOW(),voided_by_user_id=?,void_reason=? WHERE id=? AND user_id=? AND voided_at IS NULL')
            ->execute([actual_user_id(),substr($reason,0,255),$entityId,$uid]);
        $affected[]=['type'=>'account_transfer','id'=>$entityId];
    }elseif($type==='account_adjustment'){
        $st=$pdo->prepare('SELECT * FROM account_adjustments WHERE id=? AND user_id=? FOR UPDATE');$st->execute([$entityId,$uid]);$ad=$st->fetch();
        if(!$ad || !empty($ad['voided_at']))throw new DomainException('El ajuste ya no está activo.');
        if((float)$ad['amount']>0){
            $balance=FinanceService::accountBalance($uid,(int)$ad['account_id']);$protected=FinanceService::accountSavingsReserved($uid,(int)$ad['account_id']);
            if($balance-(float)$ad['amount']<$protected-0.005)throw new DomainException('No se puede anular: ese saldo sostiene dinero protegido en Ahorro.');
            $free=FinanceService::freeToSpendNow($uid);
            if($free+0.005<(float)$ad['amount'])throw new DomainException('No se puede anular: parte de ese saldo ya respalda dinero separado en fondos.');
        }
        $pdo->prepare('UPDATE account_adjustments SET voided_at=NOW(),voided_by_user_id=?,void_reason=? WHERE id=? AND user_id=? AND voided_at IS NULL')
            ->execute([actual_user_id(),substr($reason,0,255),$entityId,$uid]);
        $affected[]=['type'=>'account_adjustment','id'=>$entityId];
    }elseif($type==='fund_allocation' || $type==='fund_allocation_group'){
        $ids=$type==='fund_allocation_group'?(array)($meta['allocation_ids']??[]):[$entityId];
        $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($v)=>$v>0)));
        if(!$ids)throw new DomainException('No hay distribuciones asociadas para anular.');
        $ph=implode(',',array_fill(0,count($ids),'?'));
        $q=$pdo->prepare("SELECT fa.*,f.name fund_name FROM fund_allocations fa JOIN funds f ON f.id=fa.fund_id AND f.user_id=fa.user_id WHERE fa.user_id=? AND fa.id IN ($ph) FOR UPDATE");
        $q->execute(array_merge([$uid],$ids));$rows=$q->fetchAll();
        if(count($rows)!==count($ids))throw new DomainException('Una distribución asociada ya no existe.');
        $positiveByFund=[];
        foreach($rows as $r){
            if(!empty($r['voided_at']))throw new DomainException('Una de las distribuciones ya fue anulada.');
            if(SavingsSchema::isSavingsFund($uid,(int)$r['fund_id']))throw new DomainException('Los movimientos de Ahorro no se anulan desde esta pantalla.');
            if((float)$r['amount']>0)$positiveByFund[(int)$r['fund_id']]=($positiveByFund[(int)$r['fund_id']]??0)+(float)$r['amount'];
        }
        foreach($positiveByFund as $fid=>$amt){if(FinanceService::fundAvailable($uid,$fid)+0.005<$amt)throw new DomainException('No se puede anular porque parte del dinero de ese fondo ya fue gastado.');}
        $up=$pdo->prepare("UPDATE fund_allocations SET voided_at=NOW(),voided_by_user_id=?,void_reason=? WHERE user_id=? AND id IN ($ph) AND voided_at IS NULL");
        $up->execute(array_merge([actual_user_id(),substr($reason,0,255),$uid],$ids));
        foreach($ids as $id)$affected[]=['type'=>'fund_allocation','id'=>$id];
    }else{
        throw new DomainException('Esta acción todavía no tiene anulación automática.');
    }

    FinanceAudit::markReversed($uid,$auditId);
    FinanceAudit::record($uid,'operation_reversed','audit',$auditId,'Operación anulada',$audit['title'].' · '.$reason,$audit['after']??null,null,['original_audit_id'=>$auditId,'affected'=>$affected],false);
    $pdo->commit();
    emit_event($uid,'operation_reversed',['audit_id'=>$auditId,'affected'=>$affected]);
    json_response(['ok'=>true,'message'=>'Operación anulada correctamente.']);
}catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[MiDinero audit_revert] '.$e->getMessage());json_response(['ok'=>false,'message'=>'No se pudo anular la operación.'],500);}
