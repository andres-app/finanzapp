<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();verify_csrf();FinanceSchema::ensure($uid);$d=request_json();
$id=(int)($d['goal_id']??0);$target=max(0,round((float)($d['target_amount']??0),2));$period=trim((string)($d['period']??''));
if(!$id||!preg_match('/^\d{4}-\d{2}$/',$period))json_response(['ok'=>false,'message'=>'Datos de meta inválidos.'],422);
$pdo=db();$pdo->beginTransaction();
try{
    finance_lock_user($uid);$g=SavingsSchema::linkForGoal($uid,$id,true);if(!$g)throw new DomainException('Meta no válida.');$before=$pdo->prepare("SELECT id,name,period,target_amount FROM goals WHERE id=? AND user_id=? AND type='savings' FOR UPDATE");$before->execute([$id,$uid]);$beforeRow=$before->fetch();
    $up=$pdo->prepare('UPDATE goals SET period=?,target_amount=? WHERE id=? AND user_id=? AND type=\'savings\'');$up->execute([$period,$target,$id,$uid]);
    FinanceAudit::record($uid,'savings_goal_updated','savings_goal',$id,'Meta de ahorro actualizada',($beforeRow['name']??'Meta').' · S/ '.number_format($target,2),$beforeRow,['id'=>$id,'name'=>$beforeRow['name']??null,'period'=>$period,'target_amount'=>$target],null,false);
    $pdo->commit();try{emit_event($uid,'savings_goal_changed',['goal_id'=>$id]);}catch(Throwable $e){}
    json_response(['ok'=>true]);
}catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'message'=>$e->getMessage()],422);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[MiDinero savings goal v7] '.$e->getMessage());json_response(['ok'=>false,'message'=>'No se pudo guardar la meta.'],500);}
