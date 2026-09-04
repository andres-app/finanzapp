<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();
try{
    FinanceSchema::ensure($uid);SavingsSchema::ensure($uid);
    $g=db()->prepare("SELECT COUNT(*) FROM goals WHERE user_id=? AND type='savings'");$g->execute([$uid]);
    $f=db()->prepare("SELECT COUNT(*) FROM funds WHERE user_id=? AND active=1");$f->execute([$uid]);
    $a=db()->prepare("SELECT COUNT(*) FROM financial_accounts WHERE user_id=? AND active=1");$a->execute([$uid]);
    $sf=SavingsSchema::savingsFund($uid,false,true);
    $overview=FinanceService::savingsOverview($uid);
    $history=FinanceService::savingsHistory($uid,5);
    $protected=FinanceService::savingsReservedByAccount($uid,null);
    json_response(['ok'=>true,'module'=>'savings-v9-protected-piggybank','goals'=>(int)$g->fetchColumn(),
      'funds'=>(int)$f->fetchColumn(),'accounts'=>(int)$a->fetchColumn(),
      'savings_fund_id'=>(int)($sf['id']??0),'overview_ok'=>true,
      'saved_total'=>(float)$overview['total_saved'],'protected_by_account'=>$protected,
      'history_rows'=>count($history),'requires_migration'=>false]);
}catch(Throwable $e){
    error_log('[MiDinero savings health v9] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    json_response(['ok'=>false,'module'=>'savings-v9-protected-piggybank','message'=>$e->getMessage(),'file'=>basename($e->getFile()),'line'=>$e->getLine()],500);
}
