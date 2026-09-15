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
if($amount<=0||!$from||!$to)json_response(['ok'=>false,'message'=>'Completa el monto y las cuentas.'],422);
if(!$occurred)json_response(['ok'=>false,'message'=>'La fecha no es válida.'],422);

$pdo=db();$pdo->beginTransaction();
try{
    finance_lock_user($uid);
    SavingsSchema::ensure($uid);
    $fund=SavingsSchema::savingsFund($uid,true,true);
    if(!$fund)throw new RuntimeException('No se pudo obtener el Fondo Ahorro.');
    $fundId=(int)$fund['id'];

    $goalName='Ahorro general';
    if($goalId>0){
        $gs=$pdo->prepare("SELECT id,name FROM goals WHERE user_id=? AND id=? AND type='savings' LIMIT 1 FOR UPDATE");
        $gs->execute([$uid,$goalId]);$goal=$gs->fetch();
        if(!$goal)throw new DomainException('La meta seleccionada no es válida.');
        $goalName=(string)$goal['name'];
    }

    $available=FinanceService::savingsGoalSaved($uid,$goalId);
    if($available<$amount-0.005)throw new DomainException('Aquí solo tienes S/ '.number_format(max(0,$available),2).' protegidos.');

    $breakdown=FinanceService::savingsAccountBreakdown($uid,$goalId,$fundId,$available);
    $inAccount=0.0;foreach($breakdown as $b)if((int)($b['id']??0)===$from)$inAccount=(float)$b['amount'];
    if($inAccount<$amount-0.005)throw new DomainException('En esta cuenta solo hay S/ '.number_format(max(0,$inAccount),2).' protegidos para '.($goalId>0?$goalName:'Ahorro general').'.');

    $ids=array_values(array_unique([$from,$to]));sort($ids,SORT_NUMERIC);$ph=implode(',',array_fill(0,count($ids),'?'));
    $st=$pdo->prepare("SELECT id FROM financial_accounts WHERE user_id=? AND active=1 AND id IN ($ph) ORDER BY id FOR UPDATE");
    $st->execute(array_merge([$uid],$ids));
    if(count($st->fetchAll())!==count($ids))throw new DomainException('Una de las cuentas no es válida.');

    $transferId=0;
    if($from!==$to){
        $tr=$pdo->prepare('INSERT INTO account_transfers(user_id,created_by_user_id,from_account_id,to_account_id,amount,occurred_at,description) VALUES(?,?,?,?,?,?,?)');
        $tr->execute([$uid,actual_user_id(),$from,$to,$amount,$occurred,'Retiro de ahorro'.($goalId>0?' · '.$goalName:'')]);
        $transferId=(int)$pdo->lastInsertId();
    }

    $marker=SavingsSchema::marker('withdrawal',$goalId,$from,$to,$note);
    $fa=$pdo->prepare('INSERT INTO fund_allocations(user_id,created_by_user_id,fund_id,amount,occurred_at,note) VALUES(?,?,?,?,?,?)');
    $fa->execute([$uid,actual_user_id(),$fundId,-$amount,$occurred,$marker]);
    $allocationId=(int)$pdo->lastInsertId();
    FinanceAudit::record($uid,'savings_withdrawal','savings_movement',$allocationId,'Retiro de ahorro',$goalName.' · S/ '.number_format($amount,2),null,['goal_id'=>$goalId,'goal_name'=>$goalName,'amount'=>$amount,'from_account_id'=>$from,'to_account_id'=>$to,'transfer_id'=>$transferId,'occurred_at'=>$occurred],['period'=>substr($occurred,0,7)],false);

    $pdo->commit();
    try{emit_event($uid,'savings_withdrawal',['goal_id'=>$goalId,'amount'=>$amount,'account_id'=>$from]);}catch(Throwable $e){}
    json_response(['ok'=>true,'message'=>'Dinero liberado del Ahorro']);
}catch(DomainException $e){
    if($pdo->inTransaction())$pdo->rollBack();
    json_response(['ok'=>false,'message'=>$e->getMessage()],422);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('[MiDinero savings withdraw v9] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    json_response(['ok'=>false,'message'=>'No se pudo liberar el dinero del Ahorro.'],500);
}
