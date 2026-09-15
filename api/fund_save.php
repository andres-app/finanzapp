<?php
require __DIR__.'/../app/bootstrap.php';
$uid=require_auth();verify_csrf();FinanceSchema::ensure($uid);$d=request_json();
$id=(int)($d['id']??0);$name=trim((string)($d['name']??''));$icon=trim((string)($d['icon']??''))?:'💰';$color=trim((string)($d['color']??''))?:'#6b7280';$target=max(0,round((float)($d['target_amount']??0),2));
if($name==='')json_response(['ok'=>false,'message'=>'Escribe el nombre del fondo.'],422);
if(!preg_match('/^#[0-9a-fA-F]{6}$/',$color))$color='#6b7280';
$pdo=db();$pdo->beginTransaction();
try{
    finance_lock_user($uid);$before=null;$created=false;
    if($id){
        $get=$pdo->prepare('SELECT id,name,icon,color,target_amount FROM funds WHERE id=? AND user_id=? AND active=1 FOR UPDATE');
        $get->execute([$id,$uid]);$before=$get->fetch();if(!$before)throw new DomainException('Fondo inválido.');
        $st=$pdo->prepare('UPDATE funds SET name=?,icon=?,color=?,target_amount=? WHERE id=? AND user_id=? AND active=1');$st->execute([$name,$icon,$color,$target,$id,$uid]);
    }else{
        $created=true;$st=$pdo->prepare('INSERT INTO funds(user_id,name,icon,color,target_amount) VALUES(?,?,?,?,?)');$st->execute([$uid,$name,$icon,$color,$target]);$id=(int)$pdo->lastInsertId();
    }
    $after=['id'=>$id,'name'=>$name,'icon'=>$icon,'color'=>$color,'target_amount'=>$target];
    FinanceAudit::record($uid,$created?'fund_created':'fund_updated','fund',$id,$created?'Fondo creado':'Fondo actualizado',$name,$before,$after,null,false);
    $pdo->commit();emit_event($uid,'fund_changed',['id'=>$id]);json_response(['ok'=>true,'id'=>$id]);
}catch(DomainException $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[MiDinero fund_save] '.$e->getMessage());json_response(['ok'=>false,'message'=>'No se pudo guardar el fondo.'],500);}
