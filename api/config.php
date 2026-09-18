<?php
require __DIR__.'/../app/bootstrap.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$uid=require_auth();
FinanceSchema::ensure($uid);
$cats=db()->prepare('SELECT id,name,type,icon,is_ant_expense FROM categories WHERE user_id=? AND active=1 ORDER BY name');$cats->execute([$uid]);
$cons=db()->prepare('SELECT id,category_id,name,default_amount,is_ant_expense,is_quick_access,quick_access_order FROM concepts WHERE user_id=? AND active=1 ORDER BY name');$cons->execute([$uid]);
$accounts=FinanceService::accountBalances($uid);$protected=FinanceService::savingsReservedByAccount($uid,null);
foreach($accounts as &$a){$a['savings_reserved']=max(0,(float)($protected[(int)$a['id']]??0));$a['spendable_balance']=max(0,(float)$a['balance']-$a['savings_reserved']);}unset($a);
$incomeDefaults=[];
try {
  $ri=db()->prepare('SELECT r.concept_id,r.account_id,r.name,a.name account_name,a.icon account_icon FROM recurring_incomes r LEFT JOIN financial_accounts a ON a.id=r.account_id AND a.user_id=r.user_id WHERE r.user_id=? AND r.active=1 AND r.concept_id IS NOT NULL AND r.account_id IS NOT NULL ORDER BY r.id');
  $ri->execute([$uid]);
  $incomeDefaults=$ri->fetchAll();
} catch (Throwable $e) {
  error_log('[MiDinero config income defaults] '.$e->getMessage());
}
json_response([
  'ok'=>true,
  'categories'=>$cats->fetchAll(),
  'concepts'=>$cons->fetchAll(),
  'accounts'=>$accounts,
  'funds'=>array_values(array_filter(FinanceService::funds($uid),fn($f)=>empty($f['savings_goal_id']))),
  'income_defaults'=>$incomeDefaults,
]);
