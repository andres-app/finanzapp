<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();
verify_csrf();
FinanceSchema::ensure($uid);
$d=request_json();

$from=(int)($d['from_account_id']??0);
$to=(int)($d['to_account_id']??0);
$amount=round((float)($d['amount']??0),2);
$occurred=parse_local_datetime((string)($d['occurred_at']??date('Y-m-d H:i:s')));
if(!$from||!$to||$from===$to||$amount<=0)json_response(['ok'=>false,'message'=>'Selecciona dos cuentas distintas y un monto válido.'],422);
if(!$occurred)json_response(['ok'=>false,'message'=>'La fecha y hora no son válidas.'],422);

$pdo=db();
$pdo->beginTransaction();
try{
    finance_lock_user($uid);
    $ids=[$from,$to]; sort($ids,SORT_NUMERIC);
    $st=$pdo->prepare('SELECT id FROM financial_accounts WHERE user_id=? AND active=1 AND id IN (?,?) ORDER BY id FOR UPDATE');
    $st->execute([$uid,$ids[0],$ids[1]]);
    if(count($st->fetchAll())!==2)throw new DomainException('Cuenta inválida.');

    $balance=FinanceService::accountBalance($uid,$from);
    $protected=FinanceService::accountSavingsReserved($uid,$from);
    $spendable=max(0,$balance-$protected);
    if($spendable<$amount-0.005){
        $msg='Puedes mover S/ '.number_format($spendable,2).' desde esta cuenta.';
        if($protected>0.005)$msg.=' S/ '.number_format($protected,2).' están protegidos en Ahorro y solo se mueven desde el módulo Ahorro.';
        throw new DomainException($msg);
    }

    $st=$pdo->prepare('INSERT INTO account_transfers(user_id,created_by_user_id,from_account_id,to_account_id,amount,occurred_at,description) VALUES(?,?,?,?,?,?,?)');
    $st->execute([$uid,actual_user_id(),$from,$to,$amount,$occurred,trim((string)($d['description']??''))]);
    $id=(int)$pdo->lastInsertId();
    $names=$pdo->prepare('SELECT id,name FROM financial_accounts WHERE user_id=? AND id IN (?,?)');
    $names->execute([$uid,$from,$to]);$nameMap=[];foreach($names->fetchAll() as $a)$nameMap[(int)$a['id']]=$a['name'];
    FinanceAudit::record(
        $uid,'account_transfer_created','account_transfer',$id,'Transferencia entre cuentas',
        ($nameMap[$from]??'Cuenta').' → '.($nameMap[$to]??'Cuenta').' · S/ '.number_format($amount,2),
        null,['from_account_id'=>$from,'to_account_id'=>$to,'amount'=>$amount,'occurred_at'=>$occurred,'description'=>trim((string)($d['description']??''))],
        ['period'=>substr($occurred,0,7)],true
    );
    $pdo->commit();
    emit_event($uid,'account_transfer',['id'=>$id]);
    json_response(['ok'=>true,'id'=>$id]);
}catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'message'=>'No se pudo mover el dinero.'],500);}
