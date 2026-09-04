<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();
verify_csrf();
FinanceSchema::ensure($uid);
$d=request_json();
$id=(int)($d['id']??0);
$name=trim((string)($d['name']??''));
$type=$d['account_type']??'bank';
$icon=trim((string)($d['icon']??''))?:'🏦';
$color=trim((string)($d['color']??''))?:'#111827';
$hasCurrent=array_key_exists('current_balance',$d)&&$d['current_balance']!=='';
$desiredCurrent=$hasCurrent?round((float)$d['current_balance'],2):0.0;
if($name==='')json_response(['ok'=>false,'message'=>'Escribe el nombre de la cuenta.'],422);
if(!in_array($type,['bank','wallet','cash','other'],true))$type='other';
if(!preg_match('/^#[0-9a-fA-F]{6}$/',$color))$color='#111827';

$pdo=db();
$pdo->beginTransaction();
try{
    finance_lock_user($uid);
    if($id){
        $own=$pdo->prepare('SELECT id FROM financial_accounts WHERE id=? AND user_id=? AND active=1 FOR UPDATE');
        $own->execute([$id,$uid]);
        if(!$own->fetch())throw new DomainException('Cuenta inválida.');

        $current=FinanceService::accountBalance($uid,$id);
        $st=$pdo->prepare('UPDATE financial_accounts SET name=?,account_type=?,icon=?,color=? WHERE id=? AND user_id=? AND active=1');
        $st->execute([$name,$type,$icon,$color,$id,$uid]);

        if($hasCurrent){
            $protected=FinanceService::accountSavingsReserved($uid,$id);
            if($desiredCurrent<$protected-0.005){
                throw new DomainException('Esta cuenta tiene S/ '.number_format($protected,2).' protegidos en Ahorro. Libera o concilia ese ahorro antes de bajar el saldo real por debajo de ese monto.');
            }
            $delta=round($desiredCurrent-$current,2);
            if(abs($delta)>=0.005){
                // No alteramos opening_balance: hacerlo reescribiría todos los meses
                // históricos. El ajuste queda auditado en la fecha real de conciliación.
                $adj=$pdo->prepare('INSERT INTO account_adjustments(user_id,created_by_user_id,account_id,amount,occurred_at,note) VALUES(?,?,?,?,?,?)');
                $adj->execute([$uid,actual_user_id(),$id,$delta,date('Y-m-d H:i:s'),'Ajuste de saldo real']);
            }
        }else{
            $desiredCurrent=$current;
        }
    }else{
        // Una cuenta nueva no debe aparecer retroactivamente en meses anteriores.
        // Su saldo inicial se registra como ajuste fechado hoy.
        $st=$pdo->prepare('INSERT INTO financial_accounts(user_id,name,account_type,icon,opening_balance,color) VALUES(?,?,?,?,0,?)');
        $st->execute([$uid,$name,$type,$icon,$color]);
        $id=(int)$pdo->lastInsertId();
        if(abs($desiredCurrent)>=0.005){
            $adj=$pdo->prepare('INSERT INTO account_adjustments(user_id,created_by_user_id,account_id,amount,occurred_at,note) VALUES(?,?,?,?,?,?)');
            $adj->execute([$uid,actual_user_id(),$id,$desiredCurrent,date('Y-m-d H:i:s'),'Saldo al agregar cuenta']);
        }
    }
    $pdo->commit();
    emit_event($uid,'account_changed',['id'=>$id]);
    json_response(['ok'=>true,'id'=>$id,'balance'=>$desiredCurrent]);
}catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'message'=>'No se pudo guardar la cuenta.'],500);}
